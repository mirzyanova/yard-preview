#!/usr/bin/env python3
"""Build a static-only release; never copy WordPress, credentials, or uploads."""
import argparse
import hashlib
import json
from pathlib import Path
import re
import shutil

ROOT = Path(__file__).resolve().parents[1]

def once(text, old, new):
    if text.count(old) != 1:
        raise ValueError(f'Source changed: expected exactly one {old[:100]!r}')
    return text.replace(old, new, 1)

def build(output, release_id, fixture=None):
    if not re.fullmatch(r'[a-f0-9]{40}', release_id):
        raise ValueError('Release ID must be a full commit SHA')
    output = Path(output).resolve()
    if output == ROOT or ROOT in output.parents and output.name in {'cms', 'server', 'assets', 'scripts'}:
        raise ValueError('Refusing to overwrite source directories')
    if output.exists() and any(output.iterdir()):
        raise ValueError('Release directory must be new and empty')
    output.mkdir(parents=True, exist_ok=True)
    source = (ROOT / 'index.html').read_text()
    html = once(source, '(function(){\n"use strict";', '(async function(){\n"use strict";')
    start = html.index('var photos=[')
    end = html.index('function projectCardFormat(p)')
    html = html[:start] + (ROOT / 'cms/client-boot.js').read_text() + '\n' + html[end:]
    html = once(html, '}).slice(0,4)}', '})}')
    html = once(html, 'var next=projects[(p.id+1)%projects.length];', 'var next=projects[(projects.indexOf(p)+1)%projects.length];')
    html = once(html, 'var p=projects[Number(r.split("/")[2])];', 'var p=projects.find(function(item){return item.id===Number(r.split("/")[2])});')
    html = once(html, 'var index=p.id, position=', 'var index=p.displayIndex, position=')
    html = html.replace("p.id<=1?p.name:'Демонстрационное фото, не проект ЯРД'", 'p.name')
    html = once(html, 'img(heroImage(p),p.name)', 'yardHeroImage(p)')
    html = html.replace("p.stage+' · '+p.year", 'yardProjectMeta(p)')
    html = once(html, "p.id===1?' gallery-portrait':''", "p.cardFormat==='vertical'?' gallery-portrait':''")
    description_start = html.index("+(p.id<=1?'':'<section class=\"detail\"")
    description_end = html.index("+'<a class=\"next-project\"", description_start)
    html = html[:description_start] + "+(p.description?'<section class=\"detail\"><div class=\"detail-label\">О ПРОЕКТЕ</div><p>'+p.description+'</p></section>':'')" + html[description_end:]
    start = html.index('function privacy(){')
    end = html.index('function contacts(){', start)
    html = html[:start] + (ROOT / 'cms/client-contact.js').read_text() + '\n' + html[end:]
    html = once(html, 'else if(r==="/privacy"){privacy()}', 'else if(r==="/privacy"){privacy()}else if(r==="/consent"){yardLegalPage(true)}')
    html = once(html, 'function bindSlideshow(root,images,autoplay){', 'function bindSlideshow(root,images,autoplay){\nif(!root||!images.length){if(root)root.hidden=true;return;}')
    html = once(html, 'if(autoplay&&!window.matchMedia', 'if(autoplay&&images.length>1&&!window.matchMedia')
    html = once(html, 'function show(n){', 'if(photo){photo.onload=function(){root.classList.toggle("gallery-portrait",this.naturalHeight>this.naturalWidth)};if(photo.complete&&photo.naturalWidth)photo.onload();}\nfunction show(n){')
    html = once(html, 'if(photo){photo.onerror=', 'if(photo){photo.onload=function(){root.classList.toggle("gallery-portrait",this.naturalHeight>this.naturalWidth)};photo.onerror=')
    # The source remains the design authority; no CSS is changed during adaptation.
    source_css = re.search(r'<style>(.*?)</style>', source, re.S).group(1)
    built_css = re.search(r'<style>(.*?)</style>', html, re.S).group(1)
    assert source_css == built_css
    if 'images.unsplash.com' in html or 'Демонстрационное описание' in html:
        raise ValueError('Demo data leaked into the production release')
    prefix = '/yard-static/releases/' + release_id + '/'
    html = html.replace('assets/', prefix + 'assets/')
    html = re.sub(r'(?<![\w/-])(erfurt-0[1-3]\.webp)', prefix + r'\1', html)
    html = once(html, '<head>', '<head>\n<!-- yard-release:' + release_id + ' -->')
    if fixture:
        initial = json.dumps(fixture, ensure_ascii=False).replace('<', '\\u003c').replace('>', '\\u003e').replace('&', '\\u0026')
        html = once(html, '<head>', '<head>\n<script>window.YARD_INITIAL_DATA=' + initial + ';</script>')
    (output / 'index.html').write_text(html)
    # Explicit static allowlist: code deploy cannot carry /cms or user media.
    if any(p.is_symlink() for p in (ROOT / 'assets').rglob('*')):
        raise ValueError('Symlinks are not allowed in public assets')
    shutil.copytree(ROOT / 'assets', output / 'assets', dirs_exist_ok=True)
    for file in sorted(ROOT.glob('erfurt-*.webp')):
        shutil.copyfile(file, output / file.name)
    manifest = {str(p.relative_to(output)): hashlib.sha256(p.read_bytes()).hexdigest() for p in sorted(output.rglob('*')) if p.is_file()}
    (output / 'manifest.json').write_text(json.dumps({'release': release_id, 'files': manifest, 'css_sha256': hashlib.sha256(source_css.encode()).hexdigest()}, indent=2) + '\n')
    return manifest

if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--output', required=True)
    parser.add_argument('--release', required=True)
    args = parser.parse_args()
    files = build(args.output, args.release)
    print(f'Built {len(files)} static files; WordPress and client media are excluded.')
