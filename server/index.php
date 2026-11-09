<?php
// Installed once in public_html. The deployment account cannot change this loader or /cms.
declare(strict_types=1);
$host = strtolower(explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''))[0]);
if ($host !== 'ch197840.tw1.ru') { http_response_code(404); exit; }
$directory = __DIR__ . '/yard-static';
$release = trim((string) @file_get_contents($directory . '/current'));
if (!preg_match('/^[a-f0-9]{40}$/D', $release)) { http_response_code(503); header('Retry-After: 60'); exit('Сайт временно недоступен.'); }
$path = $directory . '/releases/' . $release . '/index.html';
if (!is_file($path)) { http_response_code(503); exit('Сайт временно недоступен.'); }
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Content-Type-Options: nosniff');
header('X-Yard-Release: ' . $release);
readfile($path);
