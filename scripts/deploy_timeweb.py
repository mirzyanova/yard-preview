#!/usr/bin/env python3
"""Certificate-verified FTPS releases inside the restricted yard-static directory."""
import argparse
from ftplib import FTP_TLS, error_perm
import hashlib, io, json, os, re, ssl, urllib.request
from pathlib import Path, PurePosixPath
HOST='vh474.timeweb.ru'
SITE='https://ch197840.tw1.ru/'
MARKER=b'YARD_STATIC_ONLY:ch197840.tw1.ru:v1\n'
SHA=re.compile(r'^[a-f0-9]{40}$')

def valid_path(path):
    p=PurePosixPath(path)
    if any(ord(c)<32 for c in path): return False
    if p.is_absolute() or '..' in p.parts or not p.parts or any(x.startswith('.') for x in p.parts): return False
    types={'.html','.json','.svg','.webp','.png','.jpg','.jpeg','.gif','.avif','.css','.js','.woff','.woff2','.ttf','.otf','.ico'}
    return bool((p.parts[0]=='assets' or path in {'index.html','manifest.json'} or re.fullmatch(r'erfurt-\d+\.webp',path)) and p.suffix.lower() in types)

def validate_release(root):
    root=Path(root)
    manifest=json.loads((root/'manifest.json').read_text())
    if not SHA.fullmatch(manifest.get('release','')): raise ValueError('Invalid release ID')
    if {str(p.relative_to(root)) for p in root.rglob('*') if p.is_file()} != set(manifest['files'])|{'manifest.json'}: raise ValueError('Unlisted or missing release files')
    for path,digest in manifest['files'].items():
        p=root/path
        if not valid_path(path) or p.is_symlink() or hashlib.sha256(p.read_bytes()).hexdigest()!=digest: raise ValueError('Unsafe or changed release: '+path)
    return manifest

def read_remote(ftp,path,missing_ok=False,limit=5000000):
    data=bytearray()
    def append(chunk):
        data.extend(chunk)
        if len(data)>limit: raise ValueError('Unexpected remote file size')
    try: ftp.retrbinary('RETR '+path,append)
    except error_perm as error:
        if missing_ok and str(error).startswith('550'): return None
        raise
    return bytes(data)

def mkdirs(ftp,path):
    parts=PurePosixPath(path).parts
    for i in range(1,len(parts)+1):
        directory='/'.join(parts[:i])
        try: ftp.mkd(directory)
        except error_perm:
            original=ftp.pwd(); ftp.cwd(directory); ftp.cwd(original)

def atomic_write(ftp,path,data):
    temporary=path+'.pending-'+os.environ.get('GITHUB_RUN_ID','')+'-'+os.urandom(4).hex()
    ftp.storbinary('STOR '+temporary,io.BytesIO(data))
    if read_remote(ftp,temporary)!=data: raise RuntimeError('Remote write verification failed')
    ftp.rename(temporary,path)  # Require atomic replacement. Never delete the current file as a fallback.

def verify_remote_release(ftp,release):
    if not SHA.fullmatch(release): raise ValueError('Invalid remote release')
    prefix='releases/'+release+'/'
    manifest=json.loads(read_remote(ftp,prefix+'manifest.json',limit=100000))
    if manifest.get('release')!=release or 'index.html' not in manifest.get('files',{}): raise ValueError('Invalid remote manifest')
    for path,digest in manifest['files'].items():
        if not valid_path(path): raise ValueError('Unsafe remote path')
        if hashlib.sha256(read_remote(ftp,prefix+path)).hexdigest()!=digest: raise RuntimeError('Remote hash mismatch: '+path)

def health_check(release):
    request=urllib.request.Request(SITE+'?yard_release='+release,headers={'Cache-Control':'no-cache','User-Agent':'YARD-deployment-check/1.0'})
    with urllib.request.urlopen(request,timeout=25,context=ssl.create_default_context()) as response:
        if response.status!=200 or response.headers.get('X-Yard-Release')!=release or ('yard-release:'+release).encode() not in response.read(600000): raise RuntimeError('The website did not confirm the selected release')
    api=urllib.request.Request(SITE+'cms/?rest_route=/yard/v1/projects',headers={'Cache-Control':'no-cache'})
    with urllib.request.urlopen(api,timeout=25,context=ssl.create_default_context()) as response:
        data=json.loads(response.read(2000000))
        if data.get('apiVersion')!=1 or not isinstance(data.get('projects'),list): raise RuntimeError('CMS did not confirm the project API')

def run(root,operation):
    host,user,password=(os.environ.get(k,'') for k in ['FTP_HOST','FTP_USER','FTP_PASSWORD'])
    if host!=HOST or user=='ch197840' or not re.fullmatch(r'ch197840[a-zA-Z0-9_]{1,8}',user) or not password: raise RuntimeError('Use the verified host and a separate restricted account')
    local=validate_release(root) if operation=='deploy' else None
    ftp=FTP_TLS(context=ssl.create_default_context(),timeout=25)
    try:
        ftp.connect(host,21); ftp.auth(); ftp.login(user,password); ftp.prot_p(); ftp.set_pasv(True)
        if read_remote(ftp,'.yard-deploy-root',limit=256)!=MARKER: raise RuntimeError('Wrong FTP directory: stopped')
        previous=(read_remote(ftp,'current',missing_ok=True,limit=128) or b'').decode().strip()
        if previous and not SHA.fullmatch(previous): raise RuntimeError('Invalid active release')
        if operation=='rollback':
            target=(read_remote(ftp,'previous',limit=128) or b'').decode().strip()
            if not SHA.fullmatch(target): raise RuntimeError('No verified previous release')
        else:
            target=local['release']; prefix='releases/'+target+'/'
            mkdirs(ftp,'releases/'+target)
            existing=read_remote(ftp,prefix+'manifest.json',missing_ok=True,limit=100000)
            if existing is not None:
                if json.loads(existing)!=local: raise RuntimeError('A different immutable release exists')
            else:
                for path in sorted(local['files']):
                    mkdirs(ftp,str(PurePosixPath(prefix+path).parent))
                    atomic_write(ftp,prefix+path,(Path(root)/path).read_bytes())
                atomic_write(ftp,prefix+'manifest.json',(Path(root)/'manifest.json').read_bytes())
        verify_remote_release(ftp,target)
        if previous and previous!=target: atomic_write(ftp,'previous',(previous+'\n').encode())
        atomic_write(ftp,'current',(target+'\n').encode())
        try: health_check(target)
        except Exception:
            if previous and previous!=target:
                atomic_write(ftp,'current',(previous+'\n').encode())
                print('Health check failed; the previous release pointer was restored.')
            raise
        print('Verified active release: '+target)
    finally:
        try: ftp.quit()
        except Exception: ftp.close()

if __name__=='__main__':
    parser=argparse.ArgumentParser()
    parser.add_argument('--directory',default='dist')
    parser.add_argument('--operation',choices=['deploy','rollback'],default='deploy')
    args=parser.parse_args(); run(args.directory,args.operation)
