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
    def test_crop_home_order_and_two_level_project_text(self):
        source=(ROOT/'index.html').read_text()
        plugin=(ROOT/'cms/yard-projects/yard-projects.php').read_text()
        boot=(ROOT/'cms/client-boot.js').read_text()
        self.assertIn('function cardImage(p)',source)
        self.assertIn('object-position:',source)
        self.assertIn('data-crop-x=',source)
        self.assertIn('function projectDetail(p)',source)
        self.assertIn('class="detail-intro"',source)
        self.assertIn('class="detail-body"',source)
        self.assertIn("cardCropX:yardCrop(p.cardCropX)",boot)
        self.assertIn("intro:yardEscape(p.intro)",boot)
        self.assertIn("function yard_home_positions(",plugin)
        self.assertIn("name=\"yard_home_order\"",plugin)
        with tempfile.TemporaryDirectory() as temp:
            release=Path(temp)/'release'
            build.build(release,'d'*40)
            html=(release/'index.html').read_text()
            self.assertIn('function cardImage(p)',html)
            self.assertIn('function projectDetail(p)',html)
            self.assertIn("intro:yardEscape(p.intro)",html)
            self.assertIn("data-crop-x=",html)
            self.assertIn('class="detail-body"',html)
    def test_project_gallery_is_unified_and_images_keep_aspect_ratio(self):
        source=(ROOT/'index.html').read_text()
        plugin=(ROOT/'cms/yard-projects/yard-projects.php').read_text()
        boot=(ROOT/'cms/client-boot.js').read_text()
        self.assertIn('function projectGalleryItems(p)',source)
        self.assertIn('function projectImages(p)',source)
        self.assertIn('function bindProjectGallery(root,items,p)',source)
        self.assertIn('function projectStory(p)',source)
        self.assertIn('class="project-gallery-thumbs"',source)
        self.assertIn('class="project-gallery-canvas size-',source)
        self.assertIn('class="project-story-facts"',source)
        self.assertIn('class="project-story-description"',source)
        self.assertIn('projectImages(p)+projectStory(p)',source)
        self.assertIn('.project-gallery-image{display:block;width:auto;height:auto;max-width:100%;max-height:100%;object-fit:contain}',source)
        self.assertIn('.project-gallery-canvas.size-medium{width:76%}',source)
        self.assertIn('.project-gallery-canvas.size-small{width:45%}',source)
        self.assertIn('.project-story-facts .project-fact{display:grid;',source)
        self.assertNotIn('function projectLead(p)',source)
        self.assertNotIn('function projectFrame(p)',source)
        self.assertIn('function yard_gallery_sizes(',plugin)
        self.assertIn("'galleryItems' =>",plugin)
        self.assertIn("'pageImage' =>",plugin)
        self.assertIn('galleryItems:Array.isArray(p.galleryItems)',boot)
        self.assertIn('pageImage:p.pageImage',boot)
        with tempfile.TemporaryDirectory() as temp:
            release=Path(temp)/'release'
            build.build(release,'e'*40)
            site=(release/'index.html').read_text()
            self.assertIn('function projectImages(p)',site)
            self.assertIn('function bindProjectGallery(root,items,p)',site)
            self.assertIn('projectImages(p)+projectStory(p)',site)
            self.assertIn('galleryItems:Array.isArray(p.galleryItems)',site)
            self.assertNotIn('bindSlideshow(app.querySelector(".gallery")',site)
            self.assertTrue(deploy.validate_release(release))
    def test_annotated_gallery_composition_and_staggered_spacing(self):
        source=(ROOT/'index.html').read_text()
        self.assertIn('.mosaic.yard-index .mosaic-column{display:flex;flex-direction:column;gap:clamp(82px,6vw,104px)',source)
        self.assertIn('@media(min-width:901px)',source)
        self.assertIn('.mosaic.yard-index .mosaic-column:nth-child(2) .tile:first-child .tile-visual{aspect-ratio:1.92}',source)
        self.assertIn('.mosaic.yard-index .mosaic-column:nth-child(3) .tile:first-child .tile-visual{aspect-ratio:1.24}',source)
        self.assertIn('.mosaic.yard-index .mosaic-column:nth-child(3){gap:clamp(94px,7.3vw,115px)}',source)
        # Do not alter card sizing API, individual cropping or the outer alignment axes.
        self.assertIn('projectCardSize(p,i)',source)
        self.assertIn('cardImage(p)',source)
        self.assertIn('.mosaic.yard-index .mosaic-column:first-child .tile{align-self:flex-start!important}',source)
        self.assertIn('.mosaic.yard-index .mosaic-column:last-child .tile{align-self:flex-end!important}',source)
        with tempfile.TemporaryDirectory() as temp:
            output=Path(temp)/'release'
            build.build(output,'a'*40)
            page=(output/'index.html').read_text()
            self.assertIn('aspect-ratio:1.92',page)
            self.assertIn('aspect-ratio:1.24',page)
            self.assertIn('gap:clamp(82px,6vw,104px)',page)
            self.assertTrue(deploy.validate_release(output))

    def test_portfolio_shrinks_three_percent_and_skips_initial_viewport_animation(self):
        source=(ROOT/'index.html').read_text()
        # Size is reduced within each column; the site and extreme alignment axes do not move.
        self.assertIn('.mosaic.yard-index .tile.size-large{width:97%!important}',source)
        self.assertIn('.mosaic.yard-index .tile.size-medium{width:91.2%!important}',source)
        self.assertIn('.mosaic.yard-index .tile.size-small{width:79.5%!important}',source)
        self.assertIn('.mosaic.yard-index .tile.size-small{width:84.4%!important}',source)
        self.assertIn('.mosaic.yard-index .tile.size-medium{width:93.1%!important}',source)
        self.assertIn('.mosaic.yard-index .tile.size-small{width:87.3%!important}',source)
        self.assertIn('.mosaic.yard-index .tile.size-medium{width:94.1%!important}',source)
        self.assertIn('.mosaic.yard-index .mosaic-column:first-child .tile{align-self:flex-start!important}',source)
        self.assertIn('.mosaic.yard-index .mosaic-column:last-child .tile{align-self:flex-end!important}',source)
        reveal=source[source.index('function bindPortfolioScrollRise()'):source.index('function allProjects(view)')]
        # Seed the visible first screen before enabling CSS that hides unrevealed tiles.
        self.assertIn('requestAnimationFrame(function(){',reveal)
        self.assertIn('var viewportBottom=window.innerHeight||document.documentElement.clientHeight;',reveal)
        self.assertIn('if(tile.getBoundingClientRect().top<viewportBottom)',reveal)
        self.assertLess(reveal.index('tile.classList.add("is-revealed")'),reveal.index('app.classList.add("portfolio-rise-ready")'))
        self.assertIn('if(!tile.classList.contains("is-revealed"))observer.observe(tile)',reveal)
        self.assertIn('portfolioRevealGeneration',reveal)
        self.assertIn('observer.unobserve(entry.target)',reveal)
        self.assertIn('function route(){portfolioRevealGeneration++;',source)
        with tempfile.TemporaryDirectory() as temp:
            output=Path(temp)/'release'
            build.build(output,'1'*40)
            page=(output/'index.html').read_text()
            self.assertIn('var viewportBottom=window.innerHeight||document.documentElement.clientHeight;',page)
            self.assertIn('.mosaic.yard-index .tile.size-large{width:97%!important}',page)
            self.assertIn('app.classList.add("portfolio-rise-ready")',page)
            self.assertTrue(deploy.validate_release(output))

    def test_portfolio_cards_reveal_with_restored_spacing(self):
        source=(ROOT/'index.html').read_text()
        self.assertIn('gap:clamp(82px,6vw,104px)',source)
        self.assertIn('gap:clamp(94px,7.3vw,115px)',source)
        self.assertIn('gap:clamp(88px,9vw,110px)',source)
        self.assertIn('.mosaic.yard-index .mosaic-column{gap:96px}',source)
        self.assertIn('function bindPortfolioScrollRise()',source)
        self.assertIn('new IntersectionObserver(function(entries)',source)
        self.assertIn('entry.target.classList.add("is-revealed")',source)
        self.assertIn('observer.unobserve(entry.target)',source)
        self.assertIn('portfolioRevealObserver.disconnect()',source)
        self.assertIn('rootMargin:"0px 0px -7% 0px"',source)
        self.assertIn('bindPortfolioScrollRise();',source)
        self.assertIn('#app.portfolio-rise-ready .yard-index .tile:not(.is-revealed)',source)
        self.assertIn('translate3d(0,clamp(46px,5.5vw,86px),0)',source)
        self.assertIn('@media(prefers-reduced-motion:reduce)',source)
        # Do not change the current 3-column composition or captions on the photograph.
        self.assertIn('grid-template-columns:repeat(3,minmax(0,1fr))',source)
        self.assertIn('class="tile-caption"',source)
        self.assertIn('.mosaic.yard-index .tile-visual::after{',source)
        with tempfile.TemporaryDirectory() as temp:
            output=Path(temp)/'release'
            build.build(output,'d'*40)
            page=(output/'index.html').read_text()
            self.assertIn('function bindPortfolioScrollRise()',page)
            self.assertIn('#app.portfolio-rise-ready .yard-index .tile:not(.is-revealed)',page)
            self.assertIn('gap:clamp(82px,6vw,104px)',page)
            self.assertTrue(deploy.validate_release(output))

    def test_three_column_mosaic_and_frame_edge_arrows(self):
        source=(ROOT/'index.html').read_text()
        self.assertIn('class="mosaic yard-index"',source)
        self.assertIn('var columnCount=mosaicColumnCount()',source)
        self.assertIn('projects.forEach(function(project,index){columns[index%columnCount]',source)
        self.assertIn('.mosaic.yard-index{display:grid;grid-template-columns:repeat(3',source)
        self.assertIn('.mosaic.yard-index .mosaic-column{display:flex;',source)
        self.assertIn('column-gap:clamp(16px,1.75vw,30px)',source)
        self.assertIn('.mosaic.yard-index .mosaic-column:first-child .tile{align-self:flex-start!',source)
        self.assertIn('.mosaic.yard-index .mosaic-column:last-child .tile{align-self:flex-end!',source)
        self.assertIn('.mosaic.yard-index .tile.size-small{width:79.5%',source)
        self.assertIn('.mosaic.yard-index .tile.size-medium{width:91.2%',source)
        self.assertIn('.mosaic.yard-index .tile.size-large{width:97%',source)
        self.assertIn('function projectGalleryItems(p)',source)
        self.assertIn('.project-gallery-stage .project-gallery-arrow{position:absolute;top:0;bottom:0;transform:none;width:25%',source)
        self.assertIn('.project-gallery-stage .project-gallery-arrow:hover',source)
        self.assertIn('.project-gallery-stage .project-gallery-arrow:focus-visible',source)
        self.assertNotIn('<h2 class="project-story-label">ПРОЕКТ</h2>',source)
        gallery=source[source.index('function projectImages(p)'):source.index('function bindProjectGallery(root,items,p)')]
        self.assertNotIn('class="project-gallery-photo"',gallery)
        self.assertIn('<div class="project-gallery-canvas size-',gallery)
        self.assertIn('project-gallery-prev',gallery)
        self.assertIn('project-gallery-next',gallery)
        with tempfile.TemporaryDirectory() as temp:
            output=Path(temp)/'release'
            build.build(output,'f'*40)
            compiled=(output/'index.html').read_text()
            self.assertIn('class="mosaic yard-index"',compiled)
            self.assertIn('var columnCount=mosaicColumnCount()',compiled)
            self.assertIn('width:25%',compiled)
            self.assertNotIn('<h2 class="project-story-label">ПРОЕКТ</h2>',compiled)
            self.assertTrue(deploy.validate_release(output))

    def test_home_sections_reveal_in_order_only_when_scrolled_into_view(self):
        source=(ROOT/'index.html').read_text()
        closing='class="closing home-scroll-rise"'
        facts='class="yard-facts home-scroll-rise"'
        self.assertEqual(source.count(closing),1)
        self.assertEqual(source.count(facts),1)
        self.assertLess(source.index(closing),source.index(facts))
        self.assertIn('function bindHomeScrollRise()',source)
        self.assertIn('new IntersectionObserver(function(entries)',source)
        self.assertIn('entry.target.classList.add("is-visible")',source)
        self.assertIn('observer.unobserve(entry.target)',source)
        self.assertIn('observer.disconnect()',source)
        self.assertIn('var previousCleanup=clearEvents;',source)
        self.assertIn('rootMargin:"0px 0px -9% 0px"',source)
        self.assertIn('@media(prefers-reduced-motion:no-preference)',source)
        self.assertIn('@media(prefers-reduced-motion:reduce)',source)
        self.assertIn('#app.home-rise-ready .home-scroll-rise:not(.is-visible)',source)
        self.assertIn('opacity:0;transform:translate3d(0,clamp(58px,7vw,112px),0) scale(.982)',source)
        self.assertIn('bindHomeScrollRise()\n}',source)
        with tempfile.TemporaryDirectory() as temp:
            release=Path(temp)/'release'
            build.build(release,'b'*40)
            html=(release/'index.html').read_text()
            self.assertLess(html.index(closing),html.index(facts))
            self.assertIn('function bindHomeScrollRise()',html)
            self.assertIn('#app.home-rise-ready .home-scroll-rise:not(.is-visible)',html)
            self.assertTrue(deploy.validate_release(release))

    def test_portfolio_labels_overlay_images_with_accessible_hover(self):
        source=(ROOT/'index.html').read_text()
        self.assertIn("'<span class=\"tile-visual\">'+cardImage(p)+'<span class=\"tile-caption\">",source)
        self.assertIn('/* Hover-card presentation: text lives inside the photograph',source)
        self.assertIn('.mosaic.yard-index .tile-visual::after{',source)
        self.assertIn('linear-gradient(180deg,rgba(0,0,0,.30)',source)
        self.assertIn('font-size:clamp(21px,1.74vw,25.5px)',source)
        self.assertIn('.mosaic.yard-index .tile:hover .tile-visual::after',source)
        self.assertIn('.mosaic.yard-index .tile:focus-visible .tile-visual::after',source)
        self.assertIn('.mosaic.yard-index .tile:hover .tile-caption',source)
        self.assertIn('.mosaic.yard-index .tile-caption{left:17px;right:17px;bottom:17px;',source)
        self.assertIn('@media(hover:none)',source)
        self.assertIn('@media(prefers-reduced-motion:reduce)',source)
        with tempfile.TemporaryDirectory() as temp:
            output=Path(temp)/'release'
            build.build(output,'c'*40)
            page=(output/'index.html').read_text()
            self.assertIn("'<span class=\"tile-visual\">'+cardImage(p)+'<span class=\"tile-caption\">",page)
            self.assertIn('font-size:clamp(21px,1.74vw,25.5px)',page)
            self.assertIn('background:linear-gradient(180deg,transparent 38%',page)
            self.assertTrue(deploy.validate_release(output))

    def test_real_projects_only_and_missing_data_remains_empty(self):
        projects=json.loads((ROOT/'cms/yard-projects/seed.json').read_text())['projects']
        self.assertEqual([len(p['photos']) for p in projects],[3,8])
        self.assertEqual(projects[0]['stage'],'Реализация')
        self.assertFalse(projects[1]['showOnHome'])
if __name__=='__main__':unittest.main()
