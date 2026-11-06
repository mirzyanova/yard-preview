<?php
defined('ABSPATH') || exit;

function yard_import_projects(string $source_root): array {
    if (!current_user_can('manage_options') && !(defined('WP_CLI') && WP_CLI)) { throw new RuntimeException('Administrator access is required.'); }
    $root = realpath($source_root);
    if (!$root) { throw new RuntimeException('Source directory does not exist.'); }
    $manifest = json_decode(file_get_contents(__DIR__ . '/seed.json'), true, 512, JSON_THROW_ON_ERROR);
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    if (!add_option('yard_import_lock', time(), '', false)) { throw new RuntimeException('Another import is running. Check its result before retrying.'); }
    $results = [];
    try {
        foreach ($manifest['projects'] as $project) {
            $existing = get_posts(['post_type' => 'yard_project', 'post_status' => 'any', 'meta_key' => '_yard_import_key', 'meta_value' => $project['key'], 'fields' => 'ids', 'posts_per_page' => 1]);
            if ($existing) { $results[] = ['key' => $project['key'], 'id' => $existing[0], 'status' => 'already-imported']; continue; }
            $media = [];
            foreach ($project['photos'] as $photo) {
                $path = realpath($root . '/' . $photo['path']);
                if (!$path || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !hash_equals($photo['sha256'], hash_file('sha256', $path))) {
                    throw new RuntimeException('Missing or changed original photograph: ' . $photo['path']);
                }
                $old = get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_yard_source_sha256', 'meta_value' => $photo['sha256'], 'fields' => 'ids', 'posts_per_page' => 1]);
                if ($old) { $media[] = (int) $old[0]; continue; }
                $tmp = wp_tempnam(basename($path));
                if (!$tmp || !copy($path, $tmp)) { throw new RuntimeException('Could not prepare photograph.'); }
                $attachment = media_handle_sideload(['name' => basename($path), 'tmp_name' => $tmp], 0, $project['name']);
                if (is_wp_error($attachment)) { @unlink($tmp); throw new RuntimeException($attachment->get_error_message()); }
                update_post_meta($attachment, '_yard_source_sha256', $photo['sha256']);
                update_post_meta($attachment, '_yard_media', '1');
                $media[] = $attachment;
            }
            $post_id = wp_insert_post([
                'post_type' => 'yard_project', 'post_status' => 'draft', 'post_title' => $project['name'],
                'post_content' => '', 'menu_order' => $project['order'], 'post_name' => $project['key'],
                'meta_input' => [
                    '_yard_import_key' => $project['key'], '_yard_legacy_id' => (string) $project['legacyId'],
                    '_yard_year' => $project['year'], '_yard_stage' => $project['stage'], '_yard_location' => $project['location'],
                    '_yard_gallery_ids' => implode(',', $media), '_yard_card_format' => $project['cardFormat'],
                    '_yard_show_home' => $project['showOnHome'] ? '1' : '0', '_yard_home_image' => $project['showOnHome'] ? (string) $media[0] : '0',
                    '_yard_home_order' => (string) $project['order'],
                ],
            ], true);
            if (is_wp_error($post_id)) { throw new RuntimeException($post_id->get_error_message()); }
            set_post_thumbnail($post_id, $media[0]);
            foreach ($media as $attachment_id) { wp_update_post(['ID' => $attachment_id, 'post_parent' => $post_id]); }
            $published = wp_update_post(['ID' => $post_id, 'post_status' => 'publish'], true);
            if (is_wp_error($published)) { throw new RuntimeException($published->get_error_message()); }
            $results[] = ['key' => $project['key'], 'id' => $post_id, 'photos' => count($media), 'status' => 'imported'];
        }
    } finally { delete_option('yard_import_lock'); }
    return $results;
}
add_action('admin_menu', function (): void {
    add_submenu_page('edit.php?post_type=yard_project', 'Импорт настоящих проектов', 'Импорт проектов', 'manage_options', 'yard-import', function (): void {
        echo '<div class="wrap"><h1>Импорт настоящих проектов</h1><p>Импортируются только ERFURT (3 фото) и Клубный дом Форум (8 фото) из файлов на этом хостинге. Исходные фотографии проверяются по SHA-256. Демонстрационные карточки не импортируются.</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="yard_import">';
        wp_nonce_field('yard_import'); submit_button('Импортировать два проекта'); echo '</form></div>';
    });
});
add_action('admin_post_yard_import', function (): void {
    if (!current_user_can('manage_options')) { wp_die('Недостаточно прав.', '', ['response' => 403]); }
    check_admin_referer('yard_import');
    try { $result = yard_import_projects(dirname(rtrim(ABSPATH, '/'))); wp_die('<pre>' . esc_html(wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>', 'Импорт выполнен'); }
    catch (Throwable $error) { wp_die(esc_html($error->getMessage()), 'Импорт не завершён', ['response' => 500]); }
});
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('yard import', function ($args, $options): void {
        try { WP_CLI::success(wp_json_encode(yard_import_projects($options['source'] ?? dirname(rtrim(ABSPATH, '/'))), JSON_UNESCAPED_UNICODE)); }
        catch (Throwable $error) { WP_CLI::error($error->getMessage()); }
    });
}
