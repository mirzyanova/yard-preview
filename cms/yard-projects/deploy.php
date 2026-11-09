<?php
defined('ABSPATH') || exit;

function yard_static_root(): string {
    return defined('YARD_STATIC_ROOT') ? YARD_STATIC_ROOT : dirname(rtrim(ABSPATH, '/')) . '/yard-static';
}
function yard_release_id($value): bool { return is_string($value) && preg_match('/^[a-f0-9]{40}$/D', $value) === 1; }
function yard_static_path(string $path): bool {
    if ($path === '' || preg_match('/[\x00-\x1f\\\\]/', $path) || str_starts_with($path, '/')) { return false; }
    $parts = explode('/', $path);
    foreach ($parts as $part) { if ($part === '' || $part === '..' || str_starts_with($part, '.')) { return false; } }
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($extension, ['html','json','svg','webp','png','jpg','jpeg','gif','avif','css','js','woff','woff2','ttf','otf','ico'], true)) { return false; }
    return $parts[0] === 'assets' || in_array($path, ['index.html','manifest.json'], true) || preg_match('/^erfurt-\d+\.webp$/D', $path);
}
function yard_deploy_root_check(): string {
    $root = realpath(yard_static_root());
    if (!$root || is_link(yard_static_root()) || @file_get_contents($root . '/.yard-deploy-root') !== "YARD_STATIC_ONLY:ch197840.tw1.ru:v1\n") { throw new RuntimeException('Deployment directory is not initialized.'); }
    return $root;
}
function yard_validate_remote_release(string $root, string $release): array {
    if (!yard_release_id($release)) { throw new RuntimeException('Invalid release ID.'); }
    $directory = $root . '/releases/' . $release;
    $manifest = json_decode((string) @file_get_contents($directory . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    if (($manifest['release'] ?? '') !== $release || empty($manifest['files']['index.html'])) { throw new RuntimeException('Invalid release manifest.'); }
    foreach ($manifest['files'] as $path => $hash) {
        if (!is_string($path) || !yard_static_path($path) || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash) || is_link($directory . '/' . $path) || !is_file($directory . '/' . $path) || !hash_equals($hash, hash_file('sha256', $directory . '/' . $path))) { throw new RuntimeException('Release verification failed.'); }
    }
    return $manifest;
}
function yard_write_pointer(string $root, string $name, string $release): void {
    if (!in_array($name, ['current','previous'], true) || !yard_release_id($release)) { throw new RuntimeException('Invalid release pointer.'); }
    $temp = $root . '/.' . $name . '-' . bin2hex(random_bytes(12));
    if (file_put_contents($temp, $release . "\n", LOCK_EX) !== 41 || !rename($temp, $root . '/' . $name)) { throw new RuntimeException('Could not switch the release.'); }
}
function yard_unpack_release(string $archive, string $release, string $root): void {
    if (!class_exists('ZipArchive') || !yard_release_id($release) || strlen($archive) > 30 * 1024 * 1024) { throw new RuntimeException('Unsupported release archive.'); }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    $temp = wp_tempnam('yard-release.zip');
    if (!$temp || file_put_contents($temp, $archive) !== strlen($archive)) { throw new RuntimeException('Could not stage release archive.'); }
    $zip = new ZipArchive();
    $opened = false;
    $staging = null;
    try {
        $opened = $zip->open($temp) === true;
        if (!$opened || $zip->numFiles < 2 || $zip->numFiles > 5000) { throw new RuntimeException('Invalid release archive.'); }
        $manifest_stat = $zip->statName('manifest.json');
        if (!$manifest_stat || $manifest_stat['size'] > 500000) { throw new RuntimeException('Missing or oversized manifest.'); }
        $manifest_text = $zip->getFromName('manifest.json');
        if (!is_string($manifest_text) || strlen($manifest_text) > 500000) { throw new RuntimeException('Missing release manifest.'); }
        $manifest = json_decode($manifest_text, true, 512, JSON_THROW_ON_ERROR);
        if (($manifest['release'] ?? '') !== $release || !is_array($manifest['files'] ?? null) || !isset($manifest['files']['index.html']) || count($manifest['files']) + 1 !== $zip->numFiles) { throw new RuntimeException('Invalid release manifest.'); }
        $bytes = 0; $seen = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i); $name = $stat['name'];
            $opsys = 0; $attrs = 0; $zip->getExternalAttributesIndex($i, $opsys, $attrs);
            if (!yard_static_path($name) || isset($seen[$name]) || (($attrs >> 16) & 0170000) === 0120000 || ($stat['size'] ?? 0) > 12 * 1024 * 1024) { throw new RuntimeException('Unsafe archive entry.'); }
            $seen[$name] = true; $bytes += (int) $stat['size'];
            if ($bytes > 40 * 1024 * 1024 || ($name !== 'manifest.json' && !isset($manifest['files'][$name]))) { throw new RuntimeException('Unlisted or oversized release.'); }
        }
        $destination = $root . '/releases/' . $release;
        if (is_dir($destination)) {
            if (yard_validate_remote_release($root, $release) !== $manifest) { throw new RuntimeException('An immutable release with this ID already exists.'); }
            return;
        }
        if (!is_dir($root . '/releases') && !mkdir($root . '/releases', 0755)) { throw new RuntimeException('Could not create release directory.'); }
        $staging = $root . '/releases/.incoming-' . bin2hex(random_bytes(16));
        if (!mkdir($staging, 0700)) { throw new RuntimeException('Could not stage release.'); }
        foreach ($manifest['files'] as $path => $hash) {
            if (!yard_static_path($path) || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) { throw new RuntimeException('Invalid file manifest.'); }
            $contents = $zip->getFromName($path);
            if (!is_string($contents) || !hash_equals($hash, hash('sha256', $contents))) { throw new RuntimeException('Release checksum mismatch.'); }
            $directory = dirname($staging . '/' . $path);
            if (!is_dir($directory) && !mkdir($directory, 0755, true)) { throw new RuntimeException('Could not stage static file.'); }
            if (file_put_contents($staging . '/' . $path, $contents) !== strlen($contents)) { throw new RuntimeException('Static file write failed.'); }
        }
        if (strpos((string) file_get_contents($staging . '/index.html'), 'yard-release:' . $release) === false) { throw new RuntimeException('Missing release marker.'); }
        file_put_contents($staging . '/manifest.json', $manifest_text);
        chmod($staging, 0755);
        if (!rename($staging, $destination)) { throw new RuntimeException('Could not commit release.'); }
        $staging = null;
        yard_validate_remote_release($root, $release);
    } finally {
        if ($opened) { $zip->close(); } @unlink($temp);
        // Only this request's newly created staging files can be removed.
        if ($staging && is_dir($staging)) {
            $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($staging, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($items as $item) { $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname()); }
            rmdir($staging);
        }
    }
}
function yard_deploy_process(string $operation, string $archive = '', string $release = '', string $expected = ''): array {
    $root = yard_deploy_root_check();
    $lock = fopen($root . '/.yard-deploy-lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Another deployment is running.'); }
    try {
        $before = trim((string) @file_get_contents($root . '/current'));
        if ($before !== '' && !yard_release_id($before)) { throw new RuntimeException('Invalid current release.'); }
        if ($expected !== '' && $before !== $expected) { throw new RuntimeException('The active release has changed.'); }
        if ($operation === 'rollback') {
            $release = trim((string) @file_get_contents($root . '/previous'));
            yard_validate_remote_release($root, $release);
        } elseif ($operation === 'deploy') {
            yard_unpack_release($archive, $release, $root);
        } else { throw new RuntimeException('Invalid operation.'); }
        if ($before && $before !== $release) { yard_write_pointer($root, 'previous', $before); }
        yard_write_pointer($root, 'current', $release);
        return ['ok' => true, 'release' => $release, 'previous' => $before];
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
add_action('rest_api_init', function (): void {
    register_rest_route('yard/v1', '/deploy', [
        'methods' => 'POST',
        'permission_callback' => fn() => current_user_can('yard_deploy'),
        'callback' => function (WP_REST_Request $request) {
            if (!get_option('yard_deploy_enabled')) { return new WP_Error('yard_deploy_disabled', 'Deployment is not enabled.', ['status' => 503]); }
            try {
                if (str_starts_with($request->get_header('content-type'), 'application/zip')) {
                    $result = yard_deploy_process('deploy', $request->get_body(), $request->get_header('x-yard-release'));
                } else {
                    $data = $request->get_json_params();
                    $result = yard_deploy_process($data['operation'] ?? '', '', '', $data['expectedCurrent'] ?? '');
                }
                return new WP_REST_Response($result, 200, ['Cache-Control' => 'no-store']);
            } catch (Throwable $error) {
                return new WP_Error('yard_deploy_rejected', 'Release rejected; the active site was not replaced.', ['status' => 400]);
            }
        },
    ]);
});
