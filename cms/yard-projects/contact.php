<?php
defined('ABSPATH') || exit;

function yard_contact_setup(): void {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = $wpdb->prefix . 'yard_contact_limits';
    dbDelta("CREATE TABLE $table (
      ip_hash varchar(64) NOT NULL,
      window_end bigint unsigned NOT NULL,
      attempts int unsigned NOT NULL DEFAULT 0,
      PRIMARY KEY  (ip_hash),
      KEY window_end (window_end)
    ) " . $wpdb->get_charset_collate() . ';');
}
function yard_legal_public(): array {
    $settings = (array) get_option('yard_contact_settings', []);
    $operator = (string) ($settings['operator'] ?? '');
    $address = (string) ($settings['address'] ?? '');
    $days = (int) ($settings['retention_days'] ?? 0);
    $ready = $operator !== '' && $address !== '' && $days > 0;
    $data = [
        'operator' => $operator, 'address' => $address, 'inn' => (string) ($settings['inn'] ?? ''),
        'email' => 'yard.bureau@mail.ru', 'retentionDays' => $days,
        'ready' => $ready, 'contactEnabled' => $ready && !empty($settings['enabled']),
    ];
    $data['version'] = substr(hash('sha256', wp_json_encode($data, JSON_UNESCAPED_UNICODE)), 0, 24);
    return $data;
}
add_action('admin_menu', function (): void {
    add_submenu_page('edit.php?post_type=yard_project', 'Обратная связь и конфиденциальность', 'Обратная связь', 'manage_options', 'yard-contact', 'yard_contact_settings_page');
});
add_action('admin_init', function (): void {
    register_setting('yard-contact', 'yard_contact_settings', ['sanitize_callback' => function ($value) {
        $value = is_array($value) ? $value : [];
        return [
            'operator' => mb_substr(sanitize_text_field($value['operator'] ?? ''), 0, 250),
            'address' => mb_substr(sanitize_textarea_field($value['address'] ?? ''), 0, 600),
            'inn' => preg_replace('/[^0-9]/', '', (string) ($value['inn'] ?? '')),
            'retention_days' => min(3650, absint($value['retention_days'] ?? 0)),
            'enabled' => !empty($value['enabled']) ? 1 : 0,
        ];
    }]);
});
function yard_contact_settings_page(): void {
    if (!current_user_can('manage_options')) { return; }
    $settings = (array) get_option('yard_contact_settings', []);
    echo '<div class="wrap"><h1>Обратная связь и конфиденциальность</h1><p>Получатель сообщений: <strong>yard.bureau@mail.ru</strong>. Сначала укажите фактического оператора персональных данных и срок хранения обращений, проверьте правовые основания и уведомление Роскомнадзора. Затем настройте и проверьте почтовый транспорт.</p><p>На тестовом аккаунте Timeweb отправка PHP mail может быть заблокирована. Форма не включается автоматически. Пароли SMTP, если нужны, задаются только в защищённой конфигурации сервера, вне GitHub.</p><form method="post" action="options.php">';
    settings_fields('yard-contact');
    echo '<table class="form-table">';
    foreach (['operator' => 'Полное наименование ООО / ИП / ФИО оператора', 'address' => 'Адрес оператора', 'inn' => 'ИНН (если применимо)', 'retention_days' => 'Срок хранения обращений в днях'] as $key => $label) {
        echo '<tr><th><label for="yard-legal-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td><input class="regular-text" id="yard-legal-' . esc_attr($key) . '" name="yard_contact_settings[' . esc_attr($key) . ']" value="' . esc_attr($settings[$key] ?? '') . '"' . ($key === 'retention_days' ? ' type="number" min="1" max="3650"' : '') . '></td></tr>';
    }
    echo '<tr><th>Серверная отправка</th><td><label><input type="checkbox" name="yard_contact_settings[enabled]" value="1"' . checked(!empty($settings['enabled']), true, false) . '> Включить после проверки почты и документов</label></td></tr></table>';
    submit_button('Сохранить');
    echo '</form></div>';
}

function yard_ip_key(): string {
    // Do not trust client-controlled X-Forwarded-For headers. No raw IP is retained.
    return hash_hmac('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), wp_salt('auth'));
}
function yard_rate_allowed(int $limit = 3): bool {
    global $wpdb;
    $table = $wpdb->prefix . 'yard_contact_limits';
    $now = time();
    $end = (intdiv($now, 600) + 1) * 600;
    $key = yard_ip_key();
    $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE window_end <= %d", $now));
    $ok = $wpdb->query($wpdb->prepare(
        "INSERT INTO $table (ip_hash, window_end, attempts) VALUES (%s, %d, 1)
         ON DUPLICATE KEY UPDATE attempts = IF(window_end <= %d, 1, attempts + 1), window_end = %d",
        $key, $end, $now, $end
    ));
    if ($ok === false) { return false; }
    $attempts = $wpdb->get_var($wpdb->prepare("SELECT attempts FROM $table WHERE ip_hash = %s", $key));
    return $attempts !== null && (int) $attempts <= $limit;
}
function yard_contact_token(): array {
    $now = time();
    $nonce = bin2hex(random_bytes(12));
    $payload = $now . '.' . $nonce;
    $signature = hash_hmac('sha256', $payload . '|' . yard_ip_key(), wp_salt('nonce'));
    return ['token' => $payload . '.' . $signature, 'version' => yard_legal_public()['version']];
}
function yard_check_contact_token(string $token): bool {
    if (!preg_match('/^(\d{10})\.([a-f0-9]{24})\.([a-f0-9]{64})$/D', $token, $parts)) { return false; }
    $age = time() - (int) $parts[1];
    if ($age < 3 || $age > 7200) { return false; }
    $expected = hash_hmac('sha256', $parts[1] . '.' . $parts[2] . '|' . yard_ip_key(), wp_salt('nonce'));
    return hash_equals($expected, $parts[3]);
}
function yard_contact_submit(WP_REST_Request $request) {
    $legal = yard_legal_public();
    if (!$legal['contactEnabled']) { return new WP_Error('yard_unavailable', 'Форма пока недоступна. Напишите на yard.bureau@mail.ru.', ['status' => 503]); }
    $origin = $request->get_header('origin');
    $expected = wp_parse_url(site_url());
    $expected_origin = ($expected['scheme'] ?? 'https') . '://' . ($expected['host'] ?? '') . (isset($expected['port']) ? ':' . $expected['port'] : '');
    if ($origin !== $expected_origin) { return new WP_Error('yard_origin', 'Обновите страницу и попробуйте снова.', ['status' => 403]); }
    if (strlen($request->get_body()) > 16384) { return new WP_Error('yard_size', 'Сообщение слишком длинное.', ['status' => 413]); }
    $data = $request->get_json_params();
    if (!is_array($data) || !empty($data['website']) || !is_string($data['token'] ?? null) || !yard_check_contact_token($data['token'])) {
        return new WP_Error('yard_verification', 'Не удалось проверить форму. Обновите страницу и попробуйте снова.', ['status' => 400]);
    }
    if (($data['consent'] ?? null) !== true || !is_string($data['consentVersion'] ?? null) || !hash_equals($legal['version'], $data['consentVersion'])) {
        return new WP_Error('yard_consent', 'Подтвердите отдельное согласие на обработку данных.', ['status' => 400]);
    }
    foreach (['name', 'email', 'message'] as $key) {
        if (!isset($data[$key]) || !is_string($data[$key])) { return new WP_Error('yard_fields', 'Заполните все поля.', ['status' => 400]); }
    }
    $name = trim(sanitize_text_field($data['name']));
    $email = trim($data['email']);
    $message = trim(sanitize_textarea_field($data['message']));
    if ($name === '' || mb_strlen($name) > 120 || strlen($email) > 180 || !is_email($email) || preg_match('/[\r\n]/', $email) || mb_strlen($message) < 10 || mb_strlen($message) > 5000) {
        return new WP_Error('yard_fields', 'Проверьте имя, email и сообщение (10–5000 символов).', ['status' => 400]);
    }
    if (!yard_rate_allowed()) { return new WP_Error('yard_rate', 'Слишком много попыток. Подождите до 10 минут или напишите на почту бюро.', ['status' => 429]); }
    // The signed token is single-use. add_option provides an atomic replay guard.
    $used_key = 'yard_used_' . hash('sha256', $data['token']);
    if (!add_option($used_key, time() + 7200, '', false)) { return new WP_Error('yard_replay', 'Эта форма уже отправлена. Обновите страницу.', ['status' => 409]); }
    global $wpdb;
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d", $wpdb->esc_like('yard_used_') . '%', time()));
    $sent_at = gmdate('c');
    $body = "Имя: $name\nEmail: $email\n\nСообщение:\n$message\n\nСогласие на обработку данных для ответа: да.\nВерсия согласия: {$legal['version']}\nВремя: $sent_at\nОператор: {$legal['operator']}\nАдрес оператора: {$legal['address']}\nСрок хранения: {$legal['retentionDays']} дней.\n";
    $from = defined('YARD_MAIL_FROM') && is_email(YARD_MAIL_FROM) ? YARD_MAIL_FROM : 'ch197840@vh474.timeweb.ru';
    $headers = ['Content-Type: text/plain; charset=UTF-8', 'From: ЯРД <' . $from . '>', 'Reply-To: ' . $email];
    $sent = wp_mail('yard.bureau@mail.ru', 'Обращение с сайта ЯРД', $body, $headers);
    if (!$sent) {
        delete_option($used_key);
        return new WP_Error('yard_mail', 'Сервер не смог отправить письмо. Попробуйте позже или напишите на yard.bureau@mail.ru.', ['status' => 502]);
    }
    $response = new WP_REST_Response(['ok' => true, 'message' => 'Сервер принял сообщение к отправке. Спасибо за обращение.']);
    $response->header('Cache-Control', 'no-store');
    return $response;
}
add_action('rest_api_init', function (): void {
    register_rest_route('yard/v1', '/contact-token', [
        'methods' => 'GET', 'permission_callback' => '__return_true',
        'callback' => function () {
            if (!yard_legal_public()['contactEnabled']) { return new WP_Error('yard_unavailable', 'Форма пока недоступна.', ['status' => 503]); }
            $response = new WP_REST_Response(yard_contact_token()); $response->header('Cache-Control', 'no-store'); return $response;
        },
    ]);
    register_rest_route('yard/v1', '/contact', ['methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'yard_contact_submit']);
});

// Optional authenticated SMTP, read only from private server configuration.
add_action('phpmailer_init', function ($mailer): void {
    if (!defined('YARD_SMTP_HOST') || !defined('YARD_SMTP_USER') || !defined('YARD_SMTP_PASSWORD')) { return; }
    $mailer->isSMTP();
    $mailer->Host = YARD_SMTP_HOST;
    $mailer->Port = defined('YARD_SMTP_PORT') ? (int) YARD_SMTP_PORT : 465;
    $mailer->SMTPSecure = $mailer->Port === 465 ? 'ssl' : 'tls';
    $mailer->SMTPAuth = true;
    $mailer->Username = YARD_SMTP_USER;
    $mailer->Password = YARD_SMTP_PASSWORD;
    $mailer->SMTPDebug = 0;
    $mailer->Timeout = 15;
    $mailer->SMTPOptions = ['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]];
});
