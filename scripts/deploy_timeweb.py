#!/usr/bin/env python3
"""Publish static releases over HTTPS using a project-isolated WordPress account."""
import argparse
import base64, hashlib, io, json, os, re, ssl, urllib.request, zipfile
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

def request_deploy(data, content_type, release=''):
    user=os.environ.get('YARD_DEPLOY_USER','')
    password=os.environ.get('YARD_DEPLOY_APP_PASSWORD','').replace(' ','')
    if user!='yard-deployer' or len(password)<20: raise RuntimeError('Use only the dedicated yard-deployer application password')
    authorization=base64.b64encode((user+':'+password).encode()).decode()
    headers={'Authorization':'Basic '+authorization,'Content-Type':content_type,'User-Agent':'YARD-deployment/1.0'}
    if release: headers['X-Yard-Release']=release
    request=urllib.request.Request(SITE+'cms/?rest_route=/yard/v1/deploy',data=data,headers=headers,method='POST')
    with urllib.request.urlopen(request,timeout=90,context=ssl.create_default_context()) as response:
        result=json.loads(response.read(100000))
        if result.get('ok') is not True or not SHA.fullmatch(result.get('release','')): raise RuntimeError('Deployment was not confirmed')
        return result

def health_check(release):
    request=urllib.request.Request(SITE+'?yard_release='+release,headers={'Cache-Control':'no-cache','User-Agent':'YARD-deployment-check/1.0'})
    with urllib.request.urlopen(request,timeout=25,context=ssl.create_default_context()) as response:
        if response.status!=200 or response.headers.get('X-Yard-Release')!=release or ('yard-release:'+release).encode() not in response.read(600000): raise RuntimeError('The website did not confirm the selected release')
    request=urllib.request.Request(SITE+'cms/?rest_route=/yard/v1/projects',headers={'Cache-Control':'no-cache'})
    with urllib.request.urlopen(request,timeout=25,context=ssl.create_default_context()) as response:
        data=json.loads(response.read(2000000))
        if data.get('apiVersion')!=1 or not isinstance(data.get('projects'),list): raise RuntimeError('CMS did not confirm the project API')

def run(root,operation):
    if operation=='rollback':
        result=request_deploy(json.dumps({'operation':'rollback'}).encode(),'application/json')
    else:
        manifest=validate_release(root)
        archive=io.BytesIO()
        with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED) as zip:
            for path in sorted(set(manifest['files'])|{'manifest.json'}): zip.write(Path(root)/path,path)
        if archive.tell()>30*1024*1024: raise RuntimeError('Static release exceeds the server upload limit')
        result=request_deploy(archive.getvalue(),'application/zip',manifest['release'])
    try: health_check(result['release'])
    except Exception:
        if result.get('previous') and result['previous']!=result['release']:
            restored=request_deploy(json.dumps({'operation':'rollback','expectedCurrent':result['release']}).encode(),'application/json')
            print('Health check failed; restored the previous release: '+restored['release'])
        raise
    print('Verified active release: '+result['release'])

if __name__=='__main__':
    parser=argparse.ArgumentParser()
    parser.add_argument('--directory',default='dist')
    parser.add_argument('--operation',choices=['deploy','rollback'],default='deploy')
    args=parser.parse_args(); run(args.directory,args.operation)
