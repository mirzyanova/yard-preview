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
    def test_real_projects_only_and_missing_data_remains_empty(self):
        projects=json.loads((ROOT/'cms/yard-projects/seed.json').read_text())['projects']
        self.assertEqual([len(p['photos']) for p in projects],[3,8])
        self.assertEqual(projects[0]['stage'],'')
        self.assertFalse(projects[1]['showOnHome'])
if __name__=='__main__':unittest.main()
