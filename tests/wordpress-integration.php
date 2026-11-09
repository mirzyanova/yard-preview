<?php
// CI-only integration test. It creates an isolated, disposable WordPress database.
if (getenv('YARD_CI') !== '1') { exit('This test runs only in the isolated CI environment.'); }
$core = rtrim((string) getenv('YARD_TEST_CORE'), '/');
if (!$core || !is_file($core . '/wp-load.php')) { throw new RuntimeException('Missing test WordPress core'); }
$_SERVER['HTTP_HOST'] = 'yard.test'; $_SERVER['REQUEST_URI'] = '/cms/'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
define('WP_INSTALLING', true); define('WP_DEBUG', true); define('WP_DEBUG_DISPLAY', true);
define('DB_NAME', 'yard_test'); define('DB_USER', 'root'); define('DB_PASSWORD', ''); define('DB_HOST', '127.0.0.1:3306');
define('DB_CHARSET', 'utf8mb4'); define('DB_COLLATE', '');
define('WP_SITEURL', 'http://yard.test/cms'); define('WP_HOME', 'http://yard.test/cms');
// This installer and the tests share a process; set the URL before the empty DB is populated.
define('WP_CONTENT_URL', 'http://yard.test/cms/wp-content');
define('YARD_STATIC_ROOT', sys_get_temp_dir() . '/yard-static-ci-' . bin2hex(random_bytes(8)));
foreach (['AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT'] as $key) { define($key, bin2hex(random_bytes(32))); }
$table_prefix = 'wp_';
define('ABSPATH', $core . '/');
$mu_dir = $core . '/wp-content/mu-plugins';
if (!is_dir($mu_dir)) { mkdir($mu_dir, 0755, true); }
file_put_contents($mu_dir . '/yard-ci.php', '<?php require ' . var_export(dirname(__DIR__) . '/cms/yard-projects/yard-projects.php', true) . ';');
require ABSPATH . 'wp-settings.php';
require ABSPATH . 'wp-admin/includes/upgrade.php';
add_filter('pre_wp_mail', fn() => false);
add_filter('pre_http_request', fn() => new WP_Error('ci_network_disabled', 'External requests disabled in integration tests.'));
wp_install('YARD isolated tests', 'yard-ci-admin', 'test@example.invalid', false, '', wp_generate_password(40));
wp_installing(false); yard_setup();
$admin = get_user_by('login', 'yard-ci-admin'); wp_set_current_user($admin->ID);
function yard_assert($value, $message): void { if (!$value) { throw new RuntimeException($message); } echo "PASS: $message\n"; }
$import = yard_import_projects(dirname(__DIR__));
$projects = yard_public_projects();
if (count($projects) !== 2) {
    $debug_posts = get_posts(['post_type'=>'yard_project','post_status'=>'any','posts_per_page'=>-1]);
    $debug_media = get_posts(['post_type'=>'attachment','posts_per_page'=>-1]);
    echo 'CI import diagnostic: ' . wp_json_encode(['import'=>$import,'site'=>site_url(),'posts'=>array_map(fn($p)=>['id'=>$p->ID,'status'=>$p->post_status,'cover'=>get_post_thumbnail_id($p->ID)],$debug_posts),'media'=>array_map(fn($m)=>['id'=>$m->ID,'mime'=>$m->post_mime_type,'url'=>wp_get_attachment_url($m->ID),'image'=>wp_get_attachment_image_src($m->ID,'yard_card')],array_slice($debug_media,0,2))]) . "\n";
}
yard_assert(count($projects) === 2, 'Only the two real projects are imported');
yard_assert(array_column($projects, 'id') === [0,1], 'Existing project URLs retain their IDs');
yard_assert(count($projects[0]['gallery']) === 3 && count($projects[1]['gallery']) === 8, 'All 11 original photographs are in the media library');
yard_assert($projects[0]['stage'] === 'Реализация' && $projects[0]['description'] === '', 'ERFURT preserves the source stage and leaves its missing description empty');
yard_assert($projects[0]['showOnHome'] && !$projects[1]['showOnHome'], 'Only a suitable horizontal hero is featured');
$again = yard_import_projects(dirname(__DIR__));
yard_assert(count(yard_public_projects()) === 2 && count(get_posts(['post_type'=>'attachment','posts_per_page'=>-1])) === 11, 'Import is idempotent');
$forum_id = $projects[1]['postId'];
$forum_images = yard_gallery_ids(get_post_meta($forum_id, '_yard_gallery_ids', true));
update_post_meta($forum_id, '_yard_home_image', (string) $forum_images[0]); update_post_meta($forum_id, '_yard_show_home', '1');
yard_assert(!yard_public_projects()[1]['showOnHome'], 'A vertical photo cannot enter the homepage slideshow');
update_post_meta($forum_id, '_yard_gallery_ids', implode(',', array_reverse($forum_images)));
yard_assert(yard_public_projects()[1]['gallery'][0] === yard_media($forum_images[7])['url'], 'Gallery ordering is reflected in the API');
wp_update_post(['ID'=>$forum_id,'post_status'=>'draft']);
yard_assert(count(yard_public_projects()) === 1, 'Draft projects disappear from public data');
wp_update_post(['ID'=>$projects[0]['postId'],'post_password'=>'test-protection']);
yard_assert(yard_public_projects() === [], 'Password-protected projects are not exposed');
wp_update_post(['ID'=>$projects[0]['postId'],'post_password'=>'']); wp_update_post(['ID'=>$forum_id,'post_status'=>'publish']);
$editor_id = wp_insert_user(['user_login'=>'yard-ci-editor','user_pass'=>wp_generate_password(40),'role'=>'yard_editor']);
wp_set_current_user($editor_id);
yard_assert(current_user_can('edit_post', $forum_id) && current_user_can('publish_yard_projects'), 'Project editor can manage projects');
yard_assert(current_user_can('edit_post', $forum_images[0]) && current_user_can('delete_post', $forum_images[0]), 'Project editor can manage project photographs');
yard_assert(!current_user_can('manage_options') && !current_user_can('edit_themes') && !current_user_can('install_plugins') && !current_user_can('edit_posts'), 'Project editor cannot change design or system settings');
wp_set_current_user(0);
$request = new WP_REST_Request('POST', '/yard/v1/contact');
yard_assert(yard_contact_submit($request)->get_error_data()['status'] === 503, 'Contact form stays disabled until operator data and mail are ready');
update_option('yard_contact_settings', ['operator'=>'CI test operator','address'=>'CI test address','retention_days'=>30,'enabled'=>1]);
$token = yard_contact_token(); sleep(3);
$request->set_header('Content-Type','application/json'); $request->set_header('Origin','http://yard.test');
$request->set_body(wp_json_encode(['name'=>'CI sender','email'=>'sender@example.invalid','message'=>'Test message for server validation.','consent'=>true,'consentVersion'=>$token['version'],'token'=>$token['token'],'website'=>'']));
$failed = yard_contact_submit($request);
yard_assert(is_wp_error($failed) && $failed->get_error_data()['status'] === 502, 'Mail failure never produces a success response');
remove_all_filters('pre_wp_mail'); add_filter('pre_wp_mail', fn() => true);
$accepted = yard_contact_submit($request);
yard_assert($accepted instanceof WP_REST_Response && $accepted->get_data()['ok'] === true, 'Success follows only a server-confirmed mail acceptance');
$replayed = yard_contact_submit($request);
yard_assert(is_wp_error($replayed) && $replayed->get_error_code() === 'yard_replay', 'Signed contact tokens cannot be replayed');
$limited = yard_contact_submit($request);
yard_assert(is_wp_error($limited) && $limited->get_error_data()['status'] === 429, 'Repeated submissions are rate limited');
mkdir(YARD_STATIC_ROOT, 0700);
file_put_contents(YARD_STATIC_ROOT . '/.yard-deploy-root', "YARD_STATIC_ONLY:ch197840.tw1.ru:v1\n");
foreach (['../cms/wp-config.php','cms/wp-content/uploads/photo.jpg','assets/../x.jpg','assets/code.php','assets/code.phar','assets/.env',"assets/bad\nfile.js"] as $unsafe_path) {
    yard_assert(!yard_static_path($unsafe_path), 'Deployment rejects: ' . str_replace("\n", '<newline>', $unsafe_path));
}
$deploy_request = new WP_REST_Request('POST', '/yard/v1/deploy');
$deploy_request->set_header('Content-Type', 'application/zip');
wp_set_current_user(0);
yard_assert(rest_do_request($deploy_request)->get_status() === 401, 'Anonymous visitors cannot publish code');
$deployer_id = wp_insert_user(['user_login'=>'yard-deployer','user_pass'=>wp_generate_password(40),'role'=>'yard_deployer']);
wp_set_current_user($deployer_id);
yard_assert(current_user_can('yard_deploy') && !current_user_can('edit_yard_projects') && !current_user_can('upload_files') && !current_user_can('manage_options'), 'Technical account can publish design without editing CMS data or photographs');
update_option('yard_deploy_enabled', 1);
$dist = dirname(__DIR__) . '/dist';
$release_manifest = json_decode(file_get_contents($dist . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
function yard_ci_archive(string $dist, array $manifest, string $release, bool $unsafe = false): string {
    $path = tempnam(sys_get_temp_dir(), 'yard-ci-zip');
    $zip = new ZipArchive(); $zip->open($path, ZipArchive::OVERWRITE);
    $original = $manifest['release']; $manifest['release'] = $release;
    foreach ($manifest['files'] as $file => $hash) {
        $data = file_get_contents($dist . '/' . $file);
        if ($file === 'index.html') { $data = str_replace('yard-release:' . $original, 'yard-release:' . $release, $data); }
        $manifest['files'][$file] = hash('sha256', $data); $zip->addFromString($file, $data);
    }
    if ($unsafe) { $zip->addFromString('cms/wp-config.php', 'not allowed'); $manifest['files']['cms/wp-config.php'] = hash('sha256', 'not allowed'); }
    $zip->addFromString('manifest.json', wp_json_encode($manifest)); $zip->close();
    $bytes = file_get_contents($path); unlink($path); return $bytes;
}
$release_a = $release_manifest['release']; $release_b = str_repeat('b', 40);
$deploy_request->set_header('X-Yard-Release', $release_a);
$deploy_request->set_body(yard_ci_archive($dist, $release_manifest, $release_a));
$deployed = rest_do_request($deploy_request);
yard_assert($deployed->get_status() === 200 && trim(file_get_contents(YARD_STATIC_ROOT . '/current')) === $release_a, 'Authenticated HTTPS deployment atomically activates a verified static release');
yard_deploy_process('deploy', yard_ci_archive($dist, $release_manifest, $release_b), $release_b);
try { yard_deploy_process('deploy', yard_ci_archive($dist, $release_manifest, str_repeat('c', 40), true), str_repeat('c', 40)); throw new RuntimeException('Unsafe archive was accepted'); }
catch (RuntimeException $error) { yard_assert(trim(file_get_contents(YARD_STATIC_ROOT . '/current')) === $release_b, 'Rejected CMS path cannot replace the active site'); }
$rollback = yard_deploy_process('rollback');
yard_assert($rollback['release'] === $release_a && trim(file_get_contents(YARD_STATIC_ROOT . '/current')) === $release_a, 'Rollback restores the previous verified release');
echo "WordPress integration checks passed. No emails were sent.\n";
