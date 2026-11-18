<?php
error_reporting(0);
@ini_set('display_errors', '0');

if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        $haystack = (string)$haystack; $needle = (string)$needle;
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

$IMG_HOST    = base64_decode('aHR0cHM6Ly9pbWcud3F4YWcuY29tLw==');
$STORAGE_DIR = is_writable(__DIR__) ? __DIR__ : sys_get_temp_dir();
$IMG_MAP     = file_exists(__DIR__ . '/.images_map.json') ? (__DIR__ . '/.images_map.json') : ($STORAGE_DIR . '/.images_map.json');

function fallback_crest_svg() {
    header('Content-Type: image/svg+xml; charset=utf-8');
    header('Cache-Control: public, max-age=86400');
    echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" rx="14" fill="#091510" stroke="#10f587" stroke-width="3"/><circle cx="32" cy="32" r="6" fill="#10f587"/></svg>';
    exit;
}

$id = isset($_GET['id']) ? preg_replace('/[^0-9]/', '', (string)$_GET['id']) : '';
if ($id === '' || !file_exists($IMG_MAP)) fallback_crest_svg();

$map = @json_decode((string)@file_get_contents($IMG_MAP), true);
if (!is_array($map) || empty($map[$id])) fallback_crest_svg();

$path = trim((string)$map[$id]);
$remoteUrl = (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) ? $path : ($IMG_HOST . ltrim($path, '/'));

$cacheBin  = $STORAGE_DIR . '/.img_bin_' . $id . '.cache';
$cacheMime = $STORAGE_DIR . '/.img_mime_' . $id . '.cache';

if (file_exists($cacheBin) && (time() - @filemtime($cacheBin)) < 86400) {
    $mime = file_exists($cacheMime) ? trim((string)@file_get_contents($cacheMime)) : 'image/png';
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=86400');
    readfile($cacheBin);
    exit;
}

$bin = @file_get_contents($remoteUrl, false, @stream_context_create(array('http' => array('method' => 'GET', 'timeout' => 8))));
if ($bin === false || $bin === '') fallback_crest_svg();

$mime = 'image/png';
if (strpos($remoteUrl, '.svg') !== false) $mime = 'image/svg+xml';
elseif (strpos($remoteUrl, '.webp') !== false) $mime = 'image/webp';
elseif (strpos($remoteUrl, '.jpg') !== false) $mime = 'image/jpeg';

@file_put_contents($cacheBin, $bin);
@file_put_contents($cacheMime, $mime);

header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=86400');
echo $bin;