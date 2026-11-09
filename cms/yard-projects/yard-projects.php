<?php
/**
 * Plugin Name: ЯРД — управление проектами
 * Description: Проекты, фотографии и API для существующего сайта ЯРД. Не устанавливает тему.
 * Version: 1.0.4
 * Requires at least: 6.7
 * Requires PHP: 8.2
 */
defined('ABSPATH') || exit;

const YARD_PROJECTS_VERSION = '1.0.4';

function yard_project_caps(): array {
    return array_fill_keys([
        'edit_yard_projects', 'edit_others_yard_projects', 'edit_private_yard_projects',
        'edit_published_yard_projects', 'publish_yard_projects', 'read_private_yard_projects',
        'delete_yard_projects', 'delete_others_yard_projects', 'delete_private_yard_projects',
        'delete_published_yard_projects',
    ], true);
}

function yard_setup(): void {
    $caps = yard_project_caps();
    add_role('yard_deployer', 'Техническая публикация дизайна ЯРД', ['read' => true, 'yard_deploy' => true]);
    add_role('yard_editor', 'Редактор проектов ЯРД', array_merge(['read' => true, 'upload_files' => true], $caps));
    foreach (['administrator', 'yard_editor'] as $role_name) {
        $role = get_role($role_name);
        if ($role) {
            foreach ($caps as $cap => $enabled) { $role->add_cap($cap); }
        }
    }
    update_option('yard_projects_version', YARD_PROJECTS_VERSION, false);
    update_option('blog_public', '0');
    yard_contact_setup();
}
register_activation_hook(__FILE__, 'yard_setup');

add_action('init', function (): void {
    register_post_type('yard_project', [
        'labels' => [
            'name' => 'Проекты', 'singular_name' => 'Проект', 'add_new' => 'Добавить проект',
            'add_new_item' => 'Новый проект', 'edit_item' => 'Редактировать проект',
            'new_item' => 'Новый проект', 'all_items' => 'Все проекты', 'search_items' => 'Найти проект',
            'not_found' => 'Проекты не найдены', 'menu_name' => 'Проекты',
        ],
        'public' => true, 'publicly_queryable' => false, 'exclude_from_search' => true,
        'show_ui' => true, 'show_in_rest' => true, 'rest_base' => 'yard-projects',
        'rewrite' => false, 'query_var' => false, 'menu_icon' => 'dashicons-portfolio',
        'capability_type' => ['yard_project', 'yard_projects'], 'map_meta_cap' => true,
        'supports' => ['title', 'thumbnail', 'revisions', 'page-attributes', 'custom-fields'],
    ]);
    add_theme_support('post-thumbnails');
    add_image_size('yard_card', 1200, 1600, false);
    add_image_size('yard_large', 2400, 2400, false);
    foreach (['year', 'stage', 'location', 'intro', 'card_format', 'card_size', 'card_crop_x', 'card_crop_y', 'gallery_ids', 'gallery_sizes', 'page_image', 'home_image', 'home_crop_x', 'home_crop_y', 'show_home', 'home_order'] as $field) {
        register_post_meta('yard_project', '_yard_' . $field, [
            'single' => true, 'type' => 'string', 'show_in_rest' => false, 'revisions_enabled' => true,
            'auth_callback' => function ($allowed, $key, $post_id): bool { return current_user_can('edit_post', (int) $post_id); },
        ]);
    }
});
add_action('admin_init', function (): void {
    if (current_user_can('manage_options') && get_option('yard_projects_version') !== YARD_PROJECTS_VERSION) { yard_setup(); }
});
add_filter('use_block_editor_for_post_type', fn($use, $type) => $type === 'yard_project' ? false : $use, 10, 2);
add_filter('image_editor_output_format', function ($formats) {
    if (wp_image_editor_supports(['mime_type' => 'image/webp'])) { $formats['image/jpeg'] = 'image/webp'; }
    return $formats;
});

function yard_is_editor(): bool {
    return in_array('yard_editor', (array) wp_get_current_user()->roles, true) && !current_user_can('manage_options');
}
add_action('add_attachment', function ($id): void {
    if (yard_is_editor()) { update_post_meta($id, '_yard_media', '1'); }
});
add_filter('ajax_query_attachments_args', function ($args) {
    if (yard_is_editor()) { $args['meta_query'][] = ['key' => '_yard_media', 'value' => '1']; }
    return $args;
});
add_filter('rest_attachment_query', function ($args) {
    if (yard_is_editor()) { $args['meta_query'][] = ['key' => '_yard_media', 'value' => '1']; }
    return $args;
});
add_filter('map_meta_cap', function ($caps, $cap, $user_id, $args) {
    $user = get_userdata($user_id);
    if (!$user || !in_array('yard_editor', (array) $user->roles, true) || empty($args[0])) { return $caps; }
    $post = get_post((int) $args[0]);
    if ($post && $post->post_type === 'attachment' && in_array($cap, ['edit_post', 'delete_post', 'read_post'], true)) {
        return get_post_meta($post->ID, '_yard_media', true) === '1' ? ['upload_files'] : ['do_not_allow'];
    }
    return $caps;
}, 10, 4);

function yard_image_id($id): int {
    $id = absint($id);
    if (!$id || !wp_attachment_is_image($id)) { return 0; }
    $url = wp_get_attachment_url($id);
    if (!$url || wp_parse_url($url, PHP_URL_HOST) !== wp_parse_url(site_url(), PHP_URL_HOST)) { return 0; }
    if (yard_is_editor() && get_post_meta($id, '_yard_media', true) !== '1') { return 0; }
    return $id;
}
function yard_crop_position($value): int {
    return is_numeric($value) ? max(0, min(100, (int) $value)) : 50;
}
function yard_gallery_ids($value): array {
    $ids = is_array($value) ? $value : explode(',', (string) $value);
    return array_values(array_unique(array_filter(array_map('yard_image_id', $ids))));
}

/* Per-gallery-photo editorial width: the original photo and aspect are preserved. */
function yard_gallery_sizes($value): array {
    $input = is_array($value) ? $value : json_decode((string) $value, true);
    if (!is_array($input)) { return []; }
    $result = [];
    foreach ($input as $key => $size) {
        $id = absint($key);
        if ($id && in_array($size, ['large', 'medium', 'small'], true)) { $result[(string) $id] = $size; }
    }
    return $result;
}
function yard_gallery_size_selector(int $id, string $value): string {
    $html = '<label class="yard-gallery-size-label">Размер на странице<select class="yard-gallery-size" aria-label="Размер фотографии">';
    foreach (['large' => 'Большой — по осям', 'medium' => 'Средний — схемы', 'small' => 'Малый — узлы'] as $key => $label) {
        $html .= '<option value="' . esc_attr($key) . '"' . selected($value, $key, false) . '>' . esc_html($label) . '</option>';
    }
    return $html . '</select></label>';
}

function yard_media(int $id, string $size = 'yard_large'): ?array {
    if (!$id || !wp_attachment_is_image($id)) { return null; }
    $image = wp_get_attachment_image_src($id, $size);
    if (!$image || wp_parse_url($image[0], PHP_URL_HOST) !== wp_parse_url(site_url(), PHP_URL_HOST)) { return null; }
    return ['url' => $image[0], 'width' => $image[1], 'height' => $image[2], 'srcset' => wp_get_attachment_image_srcset($id, $size) ?: ''];
}

add_action('add_meta_boxes_yard_project', function (): void {
    add_meta_box('yard-project-fields', 'Параметры проекта и фотографии', 'yard_project_box', 'yard_project', 'normal', 'high');
    remove_meta_box('postcustom', 'yard_project', 'normal');
    remove_meta_box('pageparentdiv', 'yard_project', 'side');
});
function yard_project_box(WP_Post $post): void {
    wp_nonce_field('yard_save_project', 'yard_project_nonce');
    $v = fn($field) => get_post_meta($post->ID, '_yard_' . $field, true);
    echo '<p>Заполните параметры проекта. Пустые поля на сайте не показываются. Чтобы скрыть проект, переведите его в черновик.</p><div class="yard-fields">';
    foreach (['year' => 'Год', 'stage' => 'Стадия', 'location' => 'Локация'] as $field => $label) {
        echo '<label>' . esc_html($label) . '<input name="yard_' . esc_attr($field) . '" value="' . esc_attr($v($field)) . '" maxlength="' . ($field === 'year' ? '4' : '180') . '"' . ($field === 'year' ? ' inputmode="numeric" pattern="[0-9]{4}|^$"' : '') . '></label>';
    }
    echo '<label>Порядок в разделе «Проекты»<input type="number" name="yard_order" value="' . (int) $post->menu_order . '" min="0" max="100000"><span class="description">Порядок плиток и строк списка, независимо от главной.</span></label>';
    echo '<label>Форма карточки<select name="yard_card_format">';
    foreach (['auto' => 'По пропорциям фотографии', 'horizontal' => 'Горизонтальная', 'vertical' => 'Вертикальная', 'square' => 'Квадратная'] as $key => $label) {
        echo '<option value="' . esc_attr($key) . '"' . selected($v('card_format') ?: 'auto', $key, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></label>';
    echo '<label>Визуальный приоритет / размер карточки<select name="yard_card_size">';
    foreach (['auto' => 'Автоматически — по композиции', 'small' => 'Маленькая', 'medium' => 'Средняя', 'large' => 'Большая'] as $key => $label) {
        echo '<option value="' . esc_attr($key) . '"' . selected($v('card_size') ?: 'auto', $key, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select><span class="description">Ширина плитки, отдельно от её пропорций.</span></label></div><hr>';
    echo '<h3>Карточка в разделе «Проекты»</h3><p class="description">Задайте формат выше и сдвиньте кадр в предпросмотре. Исходное фото не изменяется.</p>';
    yard_media_control('cover', 'Основная фотография карточки', get_post_thumbnail_id($post->ID));
    echo '<hr><h3>Главная страница — слайдшоу</h3>';
    yard_media_control('home_image', 'Фотография для главной', absint($v('home_image')));
    echo '<p><label><input type="checkbox" name="yard_show_home" value="1"' . checked($v('show_home'), '1', false) . '> Показывать в слайдшоу на главной</label></p>';
    echo '<p class="description">Подойдёт фото любых пропорций. WordPress предложит кадр по центру, который можно сдвинуть в предпросмотре.</p>';
    echo '<p class="yard-home-order"><label>Желаемый номер слайда <input type="number" name="yard_home_order" min="1" max="100000" placeholder="Например, 1" value="' . ((int) $v('home_order') > 0 ? absint($v('home_order')) : '') . '"></label><span class="description">1 — первый слайд, 2 — второй. Учитываются только отмеченные проекты с фотографией для главной. Пустые номера ставятся после заданных. При повторе номера порядок определяет поле «Порядок в разделе Проекты». Фактический номер отображается в таблице проектов.</span></p><hr>';
    echo '<h3>Текст страницы проекта</h3>';
    echo '<p><label class="yard-text-field">Главная фраза — крупный шрифт<textarea name="yard_intro" rows="3" maxlength="1400" placeholder="Краткая идея или главный тезис проекта">' . esc_textarea($v('intro')) . '</textarea></label></p>';
    echo '<p><label class="yard-text-field">Описание проекта — обычный шрифт<textarea name="yard_description" rows="8" placeholder="Основной текст проекта">' . esc_textarea(wp_strip_all_tags($post->post_content)) . '</textarea></label></p>';
    echo '<p class="description">На странице сначала выводится крупная фраза, ниже — подробное описание. Любое поле можно оставить пустым.</p><hr>';
    echo '<h3>Страница проекта — общее фото</h3><p class="description">Основная фотография в начале страницы, с сохранением пропорций. Если не выбрана, используется первая фотография галереи, а если галерея пуста — обложка карточки.</p>';
    yard_media_control('page_image', 'Общее фото проекта', absint($v('page_image')));
    echo '<hr><h3>Галерея проекта</h3><p>Все кадры сохраняют исходные пропорции — вертикальные, квадратные и горизонтальные. Выберите ширину отдельно для каждой фотографии: большой по осям страницы, средний для схем или малый для узлов. Фотографии располагаются последовательно, без обрезки. Порядок можно менять перетаскиванием.</p>';
    $gallery_sizes = yard_gallery_sizes($v('gallery_sizes'));
    echo '<input type="hidden" name="yard_gallery_ids" id="yard-gallery-ids" value="' . esc_attr(implode(',', yard_gallery_ids($v('gallery_ids')))) . '">';
    echo '<input type="hidden" name="yard_gallery_sizes" id="yard-gallery-sizes" value="' . esc_attr(wp_json_encode($gallery_sizes)) . '"><ul id="yard-gallery">';
    foreach (yard_gallery_ids($v('gallery_ids')) as $id) {
        echo '<li data-id="' . $id . '">' . wp_get_attachment_image($id, 'thumbnail') . yard_gallery_size_selector($id, $gallery_sizes[(string) $id] ?? 'large') . '<div><button type="button" class="button yard-up" aria-label="Раньше">↑</button> <button type="button" class="button yard-down" aria-label="Позже">↓</button> <button type="button" class="button yard-remove">Убрать</button></div></li>';
    }
    echo '</ul><button type="button" class="button" id="yard-add-gallery">Добавить фотографии</button>';
}
function yard_media_control(string $field, string $label, int $id): void {
    $is_home = $field === 'home_image';
    $is_page = $field === 'page_image';
    $format = $is_home ? 'horizontal' : ($is_page ? 'natural' : (get_post_meta(get_the_ID(), '_yard_card_format', true) ?: 'auto'));
    $image = $id ? wp_get_attachment_image($id, ($is_home || $is_page) ? 'yard_large' : 'yard_card') : '';
    echo '<div class="yard-media-control" data-field="' . esc_attr($field) . '" data-format="' . esc_attr($format) . '"><h3>' . esc_html($label) . '</h3><input type="hidden" name="yard_' . esc_attr($field) . '" value="' . $id . '"><div class="yard-media-preview">' . $image . '</div><button type="button" class="button yard-pick-image">Выбрать / загрузить</button> <button type="button" class="yard-clear-image button">Убрать</button>';
    if (!$is_page) {
        echo '<div class="yard-crop-controls"' . ($id ? '' : ' hidden') . '><p>' . ($is_home ? 'Положение кадра для главной' : 'Положение кадра в мозаике') . '</p>';
    foreach (['x' => 'По горизонтали', 'y' => 'По вертикали'] as $axis => $label) {
        $key = ($is_home ? 'home' : 'card') . '_crop_' . $axis;
        $position = yard_crop_position(get_post_meta(get_the_ID(), '_yard_' . $key, true));
        echo '<label>' . esc_html($label) . ' <input type="range" name="yard_' . $key . '" min="0" max="100" value="' . $position . '"></label>';
    }
        echo '<button type="button" class="button yard-center-crop">По центру</button></div>';
    }
    echo '</div>';
}
add_action('admin_enqueue_scripts', function (): void {
    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== 'yard_project') { return; }
    wp_enqueue_media();
    wp_enqueue_script('yard-project-admin', plugins_url('admin.js', __FILE__), ['jquery', 'jquery-ui-sortable'], YARD_PROJECTS_VERSION, true);
    wp_enqueue_style('yard-project-admin', plugins_url('admin.css', __FILE__), [], YARD_PROJECTS_VERSION);
});
add_action('save_post_yard_project', function ($id): void {
    if (wp_is_post_revision($id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !current_user_can('edit_post', $id)) { return; }
    if (!isset($_POST['yard_project_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['yard_project_nonce'])), 'yard_save_project')) { return; }
    $read = fn($key) => isset($_POST['yard_' . $key]) ? sanitize_text_field(wp_unslash($_POST['yard_' . $key])) : '';
    $year = $read('year');
    update_post_meta($id, '_yard_year', preg_match('/^\d{4}$/D', $year) ? $year : '');
    foreach (['stage', 'location'] as $key) { update_post_meta($id, '_yard_' . $key, mb_substr($read($key), 0, 180)); }
    $format = $read('card_format');
    update_post_meta($id, '_yard_card_format', in_array($format, ['auto', 'horizontal', 'vertical', 'square'], true) ? $format : 'auto');
    $card_size = $read('card_size');
    update_post_meta($id, '_yard_card_size', in_array($card_size, ['auto', 'small', 'medium', 'large'], true) ? $card_size : 'auto');
    update_post_meta($id, '_yard_intro', mb_substr($read('intro'), 0, 1400));
    foreach (['x', 'y'] as $axis) { update_post_meta($id, '_yard_card_crop_' . $axis, (string) yard_crop_position($read('card_crop_' . $axis))); }
    $cover = yard_image_id($read('cover'));
    if ($cover) { set_post_thumbnail($id, $cover); } else { delete_post_thumbnail($id); }
    $gallery = yard_gallery_ids($read('gallery_ids'));
    update_post_meta($id, '_yard_gallery_ids', implode(',', $gallery));
    $proposed_sizes = yard_gallery_sizes($read('gallery_sizes'));
    $saved_sizes = [];
    foreach ($gallery as $media_id) {
        $saved_sizes[(string) $media_id] = $proposed_sizes[(string) $media_id] ?? 'large';
    }
    update_post_meta($id, '_yard_gallery_sizes', wp_json_encode($saved_sizes));
    $page_image = yard_image_id($read('page_image'));
    update_post_meta($id, '_yard_page_image', (string) $page_image);
    foreach (array_unique(array_merge($gallery, [$cover, $page_image])) as $media_id) { if ($media_id) { update_post_meta($media_id, '_yard_media', '1'); } }
    $home = yard_image_id($read('home_image'));
    update_post_meta($id, '_yard_home_image', (string) $home);
    foreach (['x', 'y'] as $axis) {
        update_post_meta($id, '_yard_home_crop_' . $axis, (string) yard_crop_position($read('home_crop_' . $axis)));
    }
    update_post_meta($id, '_yard_show_home', $read('show_home') === '1' ? '1' : '0');
    update_post_meta($id, '_yard_home_order', (string) min(100000, absint($read('home_order'))));
    // Updating menu_order directly avoids recursively invoking save_post.
    global $wpdb;
    $wpdb->update($wpdb->posts, ['menu_order' => min(100000, absint($read('order')))], ['ID' => $id], ['%d'], ['%d']);
    clean_post_cache($id);
    static $updating_content = false;
    if (!$updating_content && isset($_POST['yard_description'])) {
        $description = sanitize_textarea_field(wp_unslash($_POST['yard_description']));
        $previous = (string) get_post_field('post_content', $id);
        if ($description !== $previous) {
            $updating_content = true;
            try { wp_update_post(['ID' => $id, 'post_content' => $description]); }
            finally { $updating_content = false; }
        }
    }
});

function yard_public_projects(): array {
    $posts = get_posts(['post_type' => 'yard_project', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => ['menu_order' => 'ASC', 'ID' => 'ASC'], 'has_password' => false]);
    $result = [];
    foreach ($posts as $post) {
        $id = $post->ID;
        $get = fn($key) => get_post_meta($id, '_yard_' . $key, true);
        $gallery_ids = yard_gallery_ids($get('gallery_ids'));
        $cover_id = (int) get_post_thumbnail_id($id);
        $cover = yard_media($cover_id ?: ($gallery_ids[0] ?? 0), 'yard_card');
        if (!$cover) { continue; }
        $gallery = array_values(array_filter(array_map('yard_media', $gallery_ids)));
        if (!$gallery) { $gallery = [$cover]; }
        $sizes = yard_gallery_sizes($get('gallery_sizes'));
        $actual_ids = $gallery_ids ?: [$cover_id];
        $gallery_items = [];
        foreach ($gallery as $index => $media) {
            $photo_id = $actual_ids[$index] ?? 0;
            $gallery_items[] = ['url' => $media['url'], 'size' => $sizes[(string) $photo_id] ?? 'large'];
        }
        $page_id = absint($get('page_image'));
        $page = $page_id ? yard_media($page_id) : null;
        $page = $page ?: ($gallery[0] ?? $cover);
        $home_id = absint($get('home_image'));
        $home = $home_id ? yard_media($home_id) : null;
        $format = $get('card_format');
        if (!in_array($format, ['horizontal', 'vertical', 'square'], true)) {
            $ratio = $cover['width'] / max(1, $cover['height']);
            $format = $ratio > 1.2 ? 'horizontal' : ($ratio < .85 ? 'vertical' : 'square');
        }
        $legacy = get_post_meta($id, '_yard_legacy_id', true);
        $result[] = [
            'id' => $legacy !== '' ? (int) $legacy : $id, 'postId' => $id,
            'name' => get_the_title($id), 'year' => $get('year'), 'stage' => $get('stage'), 'location' => $get('location'),
            'intro' => $get('intro'), 'description' => trim(wp_strip_all_tags(strip_shortcodes($post->post_content))),
            'image' => $cover['url'], 'cover' => $cover, 'pageImage' => $page['url'],
            'gallery' => array_column($gallery, 'url'), 'galleryMedia' => $gallery, 'galleryItems' => $gallery_items,
            'cardFormat' => $format, 'cardSize' => in_array($get('card_size'), ['small', 'medium', 'large'], true) ? $get('card_size') : 'auto', 'homeImage' => $home['url'] ?? null,
            'showOnHome' => $get('show_home') === '1' && $home !== null, 'hasHorizontalHero' => $home !== null,
            'cardCropX' => yard_crop_position($get('card_crop_x')), 'cardCropY' => yard_crop_position($get('card_crop_y')),
            'homeCropX' => yard_crop_position($get('home_crop_x')), 'homeCropY' => yard_crop_position($get('home_crop_y')),
            'homeOrder' => (int) $get('home_order'), 'order' => (int) $post->menu_order,
        ];
    }
    return $result;
}
function yard_home_positions(array $projects): array {
    $featured = array_values(array_filter($projects, fn($p) => !empty($p['showOnHome']) && !empty($p['homeImage'])));
    usort($featured, function ($a, $b): int {
        $priority = fn($p) => (int) ($p['homeOrder'] ?? 0) > 0 ? (int) $p['homeOrder'] : PHP_INT_MAX;
        return ($priority($a) <=> $priority($b))
            ?: ((int) ($a['order'] ?? 0) <=> (int) ($b['order'] ?? 0))
            ?: ((int) $a['postId'] <=> (int) $b['postId']);
    });
    $result = [];
    foreach ($featured as $i => $p) { $result[(int) $p['postId']] = $i + 1; }
    return $result;
}
add_action('rest_api_init', function (): void {
    register_rest_route('yard/v1', '/projects', [
        'methods' => 'GET', 'permission_callback' => '__return_true',
        'callback' => function () {
            $response = new WP_REST_Response(['projects' => yard_public_projects(), 'legal' => yard_legal_public(), 'apiVersion' => 1]);
            $response->header('Cache-Control', 'no-store, max-age=0');
            return $response;
        },
    ]);
});
add_filter('manage_yard_project_posts_columns', function ($columns) {
    return ['cb' => $columns['cb'], 'title' => 'Название', 'yard_year' => 'Год', 'yard_stage' => 'Стадия', 'yard_location' => 'Локация', 'yard_order' => '№ в проектах', 'yard_size' => 'Размер', 'yard_home' => '№ на главной', 'date' => 'Публикация'];
});
add_action('manage_yard_project_posts_custom_column', function ($column, $id): void {
    if ($column === 'yard_order') { echo (int) get_post($id)->menu_order; }
    elseif ($column === 'yard_size') {
        $value = get_post_meta($id, '_yard_card_size', true);
        echo esc_html(['small' => 'Маленькая', 'medium' => 'Средняя', 'large' => 'Большая'][$value] ?? 'Автоматически');
    }
    elseif ($column === 'yard_home') {
        $positions = yard_home_positions(yard_public_projects());
        echo isset($positions[(int) $id]) ? (int) $positions[(int) $id] : '—';
    }
    elseif (in_array($column, ['yard_year', 'yard_stage', 'yard_location'], true)) { echo esc_html(get_post_meta($id, '_' . $column, true)); }
}, 10, 2);
add_action('pre_get_posts', function ($query): void {
    if (is_admin() && $query->is_main_query() && $query->get('post_type') === 'yard_project' && !$query->get('orderby')) { $query->set('orderby', ['menu_order' => 'ASC', 'ID' => 'ASC']); }
});
add_action('admin_menu', function (): void {
    if (yard_is_editor()) {
        foreach (['index.php', 'edit.php', 'edit.php?post_type=page', 'edit-comments.php', 'tools.php'] as $menu) { remove_menu_page($menu); }
    }
});
add_filter('login_redirect', function ($redirect, $requested, $user) {
    return $user instanceof WP_User && in_array('yard_editor', $user->roles, true) ? admin_url('edit.php?post_type=yard_project') : $redirect;
}, 10, 3);
add_filter('xmlrpc_enabled', '__return_false');
add_filter('wp_headers', function ($headers) { $headers['X-Robots-Tag'] = 'noindex, nofollow, noarchive'; return $headers; });
add_action('template_redirect', function (): void { wp_safe_redirect(home_url('/../'), 302); exit; });

require_once __DIR__ . '/contact.php';
require_once __DIR__ . '/import.php';
require_once __DIR__ . '/deploy.php';
