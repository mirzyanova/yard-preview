#!/usr/bin/env python3
"""Read-only preflight of the exact static copy and FTPS, without any credentials."""
from ftplib import FTP_TLS
from pathlib import Path
import hashlib
import ssl
import urllib.parse
import urllib.request

ROOT=Path(__file__).resolve().parents[1]
BASE='https://ch197840.tw1.ru/'
files=['index.html','erfurt-01.webp','erfurt-02.webp','erfurt-03.webp']
files += [str(p.relative_to(ROOT)) for p in (ROOT/'assets').rglob('*') if p.is_file()]
for path in sorted(files):
    url=BASE if path=='index.html' else BASE+urllib.parse.quote(path)
    request=urllib.request.Request(url,headers={'Cache-Control':'no-cache','User-Agent':'YARD-migration-preflight/1.0'})
    with urllib.request.urlopen(request,context=ssl.create_default_context(),timeout=20) as response:
        data=response.read(5_000_000)
        if response.status!=200 or hashlib.sha256(data).digest()!=hashlib.sha256((ROOT/path).read_bytes()).digest():
            raise RuntimeError('Timeweb content differs: '+path)
        print('Verified HTTPS and original bytes: '+path)
ftp=FTP_TLS(context=ssl.create_default_context(),timeout=20)
try:
    ftp.connect('vh474.timeweb.ru',21)
    ftp.auth()
    print('FTPS TLS certificate and hostname: verified. No credentials used.')
finally:
    ftp.close()
