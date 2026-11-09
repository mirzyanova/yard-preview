import importlib.util, json, re, tempfile, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def module(name):
    spec=importlib.util.spec_from_file_location(name,ROOT/'scripts'/(name+'.py'))
    result=importlib.util.module_from_spec(spec); spec.loader.exec_module(result); return result
build=module('build_release')
deploy=module('deploy_timeweb')
class DeploymentBoundaryTests(unittest.TestCase):
    def test_cms_and_secrets_never_deployed(self):
        for path in ['../cms/wp-config.php','cms/wp-content/uploads/x.jpg','assets/../../cms/x.jpg','assets/config.php','/index.html','.env','assets/.env','assets/code.phar']:
            self.assertFalse(deploy.valid_path(path),path)
        for path in ['index.html','manifest.json','assets/yard-logo-horizontal.svg','assets/projects/forum/forum-01.webp']:
            self.assertTrue(deploy.valid_path(path),path)
    def test_css_preserved_and_demo_data_removed(self):
        with tempfile.TemporaryDirectory() as temp:
            release=Path(temp)/'release'; build.build(release,'a'*40)
            html=(release/'index.html').read_text()
            css=lambda s:re.search(r'<style>(.*?)</style>',s,re.S).group(1)
            self.assertEqual(css((ROOT/'index.html').read_text()),css(html))
            self.assertNotIn('images.unsplash.com',html)
            self.assertNotIn('Демонстрационное описание',html)
            self.assertTrue(deploy.validate_release(release))
            (release/'wp-config.php').write_text('private')
            with self.assertRaises(ValueError):deploy.validate_release(release)
    def test_portfolio_hover_caption_and_launch_copy(self):
        source=(ROOT/'index.html').read_text()
        self.assertNotIn('ЭСКИЗ · ПОРТФОЛИО ПОСТЕПЕННО ЗАПОЛНЯЕТСЯ',source)
        self.assertNotIn('эскиз сайта',source)
        self.assertIn('Для обеспечения работы сайта используются файлы cookie.',source)
        self.assertNotIn('Это тестовая версия сайта. Рекламные',source)
        self.assertIn('class="tile-visual"',source)
        self.assertIn('class="tile-caption"',source)
        self.assertNotIn('class="tile-info"',source)
        self.assertIn('.mosaic .tile-visual{display:block',source)
        self.assertIn('.mosaic .tile:hover .tile-caption',source)
        self.assertIn('.mosaic .mosaic-column .tile{aspect-ratio:auto !important',source)
        # Releasing to Timeweb must preserve the changed markup and layout CSS.
        with tempfile.TemporaryDirectory() as temp:
            target=Path(temp)/'release'
            build.build(target,'b'*40)
            production=(target/'index.html').read_text()
            self.assertIn('class="tile-caption"',production)
            self.assertIn('class="tile-visual"',production)
            self.assertIn('.mosaic{column-gap:clamp(28px,3.1vw,52px);padding-bottom:clamp(105px,10vw,165px)}',production)
            self.assertNotIn('ЭСКИЗ · ПОРТФОЛИО',production)
    def test_composition_and_size_api_cannot_overlap_captions(self):
        source=(ROOT/'index.html').read_text()
        self.assertIn('.mosaic .tile-caption{display:flex;position:static;',source)
        self.assertIn('.mosaic .mosaic-column .tile{aspect-ratio:auto !important',source)
        self.assertIn('.mosaic .tile.format-horizontal .tile-visual{aspect-ratio:1.56}',source)
        self.assertIn('.mosaic .tile.format-vertical .tile-visual{aspect-ratio:.78}',source)
        self.assertIn('function projectCardSize(p,index)',source)
        self.assertIn("['small','medium','large']", (ROOT/'cms/client-boot.js').read_text())
        self.assertIn("'cardSize' =>", (ROOT/'cms/yard-projects/yard-projects.php').read_text())
        self.assertIn('name="yard_card_size"', (ROOT/'cms/yard-projects/yard-projects.php').read_text())
        with tempfile.TemporaryDirectory() as temp:
            target=Path(temp)/'release'
            build.build(target,'c'*40)
            final=(target/'index.html').read_text()
            self.assertIn('function projectCardSize(p,index)',final)
            self.assertIn('.mosaic .tile-caption{display:flex;position:static;',final)
            self.assertIn("cardSize:['small','medium','large']",final)
    def test_real_projects_only_and_missing_data_remains_empty(self):
        projects=json.loads((ROOT/'cms/yard-projects/seed.json').read_text())['projects']
        self.assertEqual([len(p['photos']) for p in projects],[3,8])
        self.assertEqual(projects[0]['stage'],'Реализация')
        self.assertFalse(projects[1]['showOnHome'])
if __name__=='__main__':unittest.main()
