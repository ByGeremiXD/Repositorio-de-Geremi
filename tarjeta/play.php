<?php
// play.php - Envía siempre application/vnd.apple.mpegurl (sin pantalla de texto plano),
// conserva el estilo "/YYYY/MM/DD/<numero-corto>.ts" en los segmentos (play.php?ts=/YYYY/MM/DD/123456.ts)
// para que den video en todos los reproductores y bloquea la descarga directa del archivo .ts en el navegador.
error_reporting(0);
@ini_set('display_errors', '0');
date_default_timezone_set('UTC');
if (function_exists('set_time_limit')) { @set_time_limit(0); }

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: *');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        $haystack = (string)$haystack;
        $needle   = (string)$needle;
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        $haystack = (string)$haystack;
        $needle   = (string)$needle;
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

$UA            = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';
$PROVIDER_BASE = base64_decode('aHR0cHM6Ly90dmY5MC5jb20v'); // https://tvf90.com/
$AGENDA_API    = base64_decode('aHR0cHM6Ly9hcGkud3F4YWcuY29tL2RpYXJpZXMuanNvbg==');
$ORIGIN_HDR    = base64_decode('aHR0cHM6Ly9yb2phZGlyZWN0YXR2bWFzLm5ldA==');
$EMBED_BASE    = base64_decode('aHR0cHM6Ly9yb2phZGlyZWN0YXR2bWFzLm5ldC9lbWJlZC9ldmVudG9zLmh0bWw/cj0=');

function get_storage_candidates($filename) {
    $list = array(__DIR__ . '/' . $filename);
    $tmp  = @sys_get_temp_dir();
    if (!empty($tmp) && @is_dir($tmp) && @is_writable($tmp)) {
        $list[] = rtrim($tmp, '/\\') . '/' . $filename;
    }
    return array_values(array_unique($list));
}

$MAP_FILES    = get_storage_candidates('.channels_map.json');
$CACHE_FILES  = get_storage_candidates('.m3u8_fast_cache.json');
$BACKUP_FILES = get_storage_candidates('.diaries_backup.json');
$TS_MAP_FILES = get_storage_candidates('.ts_short_map.json');

function read_first_json($files) {
    foreach ($files as $f) {
        if (@file_exists($f) && @is_readable($f)) {
            $dec = @json_decode((string)@file_get_contents($f), true);
            if (is_array($dec) && !empty($dec)) return $dec;
        }
    }
    return array();
}

function write_all_json($files, $data) {
    $json = json_encode($data, JSON_UNESCAPED_SLASHES);
    foreach ($files as $f) {
        @file_put_contents($f, $json, LOCK_EX);
    }
}

function b64url_decode($token) {
    $b64 = strtr(trim((string)$token), '-_ ', '+/+');
    $pad = strlen($b64) % 4;
    if ($pad) {
        $b64 .= str_repeat('=', 4 - $pad);
    }
    $dec = @base64_decode($b64, true);
    return $dec !== false ? $dec : '';
}

function normalize_stream_url($url) {
    $u = trim(stripslashes((string)$url));
    $u = preg_replace('/^https:\/\/([^\/:]+):443(\/|$)/i', 'https://$1$2', $u);
    $u = preg_replace('/^http:\/\/([^\/:]+):80(\/|$)/i', 'http://$1$2', $u);
    return $u;
}

function build_headers($referer, $includeOrigin = true) {
    $headers = array(
        'Accept: */*',
        'Accept-Language: es-ES,es;q=0.9,en;q=0.8',
        'Connection: keep-alive'
    );
    if ($referer !== '') {
        $p      = @parse_url($referer);
        $scheme = !empty($p['scheme']) ? $p['scheme'] : 'https';
        $host   = !empty($p['host']) ? $p['host'] : 'tvf90.com';
        $headers[] = 'Referer: ' . $referer;
        if ($includeOrigin) {
            $headers[] = 'Origin: ' . $scheme . '://' . $host;
        }
    }
    return $headers;
}

function curl_fast_get($url, $referer = 'https://tvf90.com/', $timeout = 6, $includeOrigin = true) {
    global $UA;
    $url = normalize_stream_url($url);

    if (function_exists('curl_init')) {
        $ch = @curl_init($url);
        if ($ch) {
            @curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            @curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            @curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
            @curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            @curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
            @curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            @curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            @curl_setopt($ch, CURLOPT_USERAGENT, $UA);
            @curl_setopt($ch, CURLOPT_HTTPHEADER, build_headers($referer, $includeOrigin));
            @curl_setopt($ch, CURLOPT_ENCODING, '');
            if (defined('CURL_IPRESOLVE_V4')) {
                @curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            }
            $body = @curl_exec($ch);
            $info = @curl_getinfo($ch);
            @curl_close($ch);
            if ($body !== false) {
                return array(
                    'body'      => $body,
                    'status'    => isset($info['http_code']) ? (int)$info['http_code'] : 200,
                    'effective' => !empty($info['url']) ? normalize_stream_url($info['url']) : $url
                );
            }
        }
    }

    $hdrStr = "User-Agent: " . $UA . "\r\n";
    foreach (build_headers($referer, $includeOrigin) as $h) {
        $hdrStr .= $h . "\r\n";
    }
    $ctx = @stream_context_create(array(
        'http' => array(
            'method'        => 'GET',
            'timeout'       => $timeout,
            'ignore_errors' => true,
            'header'        => $hdrStr
        ),
        'ssl' => array('verify_peer' => false, 'verify_peer_name' => false)
    ));
    $body = @file_get_contents($url, false, $ctx);
    return array(
        'body'      => $body !== false ? $body : '',
        'status'    => $body !== false ? 200 : 0,
        'effective' => $url
    );
}

function write_ts_chunk_callback($ch, $chunk) {
    echo $chunk;
    @flush();
    return strlen($chunk);
}

// Bloquea únicamente cuando alguien pega la URL de un segmento .ts en la barra del navegador para descargarlo
function is_direct_ts_navigation_download() {
    $secDest = isset($_SERVER['HTTP_SEC_FETCH_DEST']) ? strtolower((string)$_SERVER['HTTP_SEC_FETCH_DEST']) : '';
    $secMode = isset($_SERVER['HTTP_SEC_FETCH_MODE']) ? strtolower((string)$_SERVER['HTTP_SEC_FETCH_MODE']) : '';
    if ($secDest === 'document' && $secMode === 'navigate') {
        return true;
    }
    return false;
}

function stream_binary_segment($url, $referer = 'https://tvf90.com/') {
    global $UA;

    if (is_direct_ts_navigation_download()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('403 Forbidden: Direct segment download is disabled.');
    }

    $url = normalize_stream_url($url);

    while (ob_get_level() > 0) { @ob_end_flush(); }
    header('Access-Control-Allow-Origin: *');
    header('X-Content-Type-Options: nosniff');
    if (str_contains($url, '.key')) {
        header('Content-Type: application/octet-stream');
    } else {
        header('Content-Type: video/mp2t');
    }
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    if (function_exists('curl_init')) {
        $ch = @curl_init($url);
        if ($ch) {
            @curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
            @curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            @curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
            @curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            @curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
            @curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            @curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            @curl_setopt($ch, CURLOPT_BUFFERSIZE, 65536);
            @curl_setopt($ch, CURLOPT_USERAGENT, $UA);
            @curl_setopt($ch, CURLOPT_HTTPHEADER, build_headers($referer, true));
            if (defined('CURL_IPRESOLVE_V4')) {
                @curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            }
            @curl_setopt($ch, CURLOPT_WRITEFUNCTION, 'write_ts_chunk_callback');
            @curl_exec($ch);
            @curl_close($ch);
            exit;
        }
    }
    $res = curl_fast_get($url, $referer, 12, true);
    echo $res['body'];
    exit;
}

function resolve_url($base, $rel) {
    $rel = trim((string)$rel);
    if (preg_match('/^https?:\/\//i', $rel)) {
        return normalize_stream_url($rel);
    }
    $base   = normalize_stream_url($base);
    $p      = @parse_url($base);
    $scheme = !empty($p['scheme']) ? $p['scheme'] : 'https';
    $host   = !empty($p['host']) ? $p['host'] : '';
    $port   = (!empty($p['port']) && $p['port'] != 80 && $p['port'] != 443) ? (':' . $p['port']) : '';
    $path   = !empty($p['path']) ? $p['path'] : '/';

    if (str_starts_with($rel, '//')) return $scheme . ':' . $rel;
    if (str_starts_with($rel, '/'))  return $scheme . '://' . $host . $port . $rel;

    $dir = rtrim(substr($path, 0, strrpos($path, '/') + 1), '/') . '/';
    return $scheme . '://' . $host . $port . $dir . $rel;
}

function decode_r_param($iframeUrl) {
    $current = trim((string)$iframeUrl);
    for ($i = 0; $i < 3; $i++) {
        $candidate = '';
        if (preg_match('/[?&]r=([A-Za-z0-9+\/=_-]+)/', $current, $m)) {
            $candidate = rawurldecode($m[1]);
        } else {
            $parsed = @parse_url($current);
            if (!empty($parsed['query'])) {
                parse_str($parsed['query'], $q);
                if (!empty($q['r'])) {
                    $candidate = (string)$q['r'];
                }
            }
        }
        if ($candidate === '') break;
        $dec = b64url_decode($candidate);
        if ($dec !== '' && preg_match('/^https?:\/\//i', trim($dec))) {
            $current = trim($dec);
        } else {
            break;
        }
    }
    return $current;
}

function extract_m3u8_candidates_from_html($html) {
    $found = array();
    if ($html === '') return $found;

    $clean = str_replace('\/', '/', $html);

    if (preg_match_all('/(https?:\/\/[0-9a-z.-]*ftlly\.com(?::[0-9]+)?\/[^\s"\'<>\\\\]+?\.m3u8(?:\?[^\s"\'<>\\\\]+)?)/i', $clean, $m)) {
        foreach ($m[1] as $u) {
            $found[] = normalize_stream_url($u);
        }
    }

    if (preg_match_all('/(https?:\/\/[a-z0-9.-]+(?::[0-9]+)?\/[^\s"\'<>\\\\]+?\.m3u8(?:\?[^\s"\'<>\\\\]+)?)/i', $clean, $m)) {
        foreach ($m[1] as $u) {
            $found[] = normalize_stream_url($u);
        }
    }

    if (preg_match_all('/\[(?:\s*["\'][^"\']*["\']\s*,?)+\]\s*\.join\(\s*["\']["\']\s*\)/i', $html, $arrMatches)) {
        foreach ($arrMatches[0] as $arrStr) {
            if (preg_match_all('/["\']([^"\']*)["\']/', $arrStr, $chars)) {
                $joined = str_replace('\/', '/', implode('', $chars[1]));
                if (preg_match('/(https?:\/\/[^\s"\'<>\\\\]+?\.m3u8(?:\?[^\s"\'<>\\\\]+)?)/i', $joined, $jm)) {
                    $found[] = normalize_stream_url($jm[1]);
                }
            }
        }
    }

    if (preg_match_all('/atob\(\s*["\']([A-Za-z0-9+\/=_-]+)["\']\s*\)/i', $html, $b64List)) {
        foreach ($b64List[1] as $b64) {
            $d = str_replace('\/', '/', b64url_decode($b64));
            if ($d !== '' && preg_match('/(https?:\/\/[^\s"\'<>\\\\]+?\.m3u8(?:\?[^\s"\'<>\\\\]+)?)/i', $d, $bm)) {
                $found[] = normalize_stream_url($bm[1]);
            }
        }
    }
    if (preg_match_all('/["\'](aHR0cHM6Ly[A-Za-z0-9+\/=_-]+)["\']/', $html, $rawB64)) {
        foreach ($rawB64[1] as $b64) {
            $d = str_replace('\/', '/', b64url_decode($b64));
            if ($d !== '' && preg_match('/(https?:\/\/[^\s"\'<>\\\\]+?\.m3u8(?:\?[^\s"\'<>\\\\]+)?)/i', $d, $bm)) {
                $found[] = normalize_stream_url($bm[1]);
            }
        }
    }

    return array_values(array_unique($found));
}

function resolve_working_m3u8_from_iframe($iframeUrl, $depth = 0) {
    if ($depth > 2) return null;

    $targetUrl = decode_r_param($iframeUrl);
    $res       = curl_fast_get($targetUrl, 'https://rojadirectatvmas.net/', 5, false);
    $html      = $res['body'];
    $effUrl    = !empty($res['effective']) ? $res['effective'] : $targetUrl;
    if ($html === '') return null;

    $m3u8List = extract_m3u8_candidates_from_html($html);
    foreach ($m3u8List as $m3u8Url) {
        $check = curl_fast_get($m3u8Url, 'https://tvf90.com/', 5, true);
        $body  = ltrim((string)$check['body']);
        if (str_starts_with($body, '#EXTM3U')) {
            return array(
                'm3u8'      => $m3u8Url,
                'effective' => !empty($check['effective']) ? $check['effective'] : $m3u8Url,
                'ref'       => 'https://tvf90.com/',
                'body'      => $check['body']
            );
        }
    }

    if (preg_match_all('/<iframe[^>]+src=["\']([^"\']+)["\']/i', $html, $innerFrames)) {
        foreach ($innerFrames[1] as $innerSrc) {
            $absInner = resolve_url($effUrl, decode_r_param($innerSrc));
            if ($absInner !== $targetUrl && (str_contains($absInner, 'tvf90.com') || str_contains($absInner, '.php'))) {
                $subRes = resolve_working_m3u8_from_iframe($absInner, $depth + 1);
                if (is_array($subRes) && !empty($subRes['m3u8'])) {
                    return $subRes;
                }
            }
        }
    }

    return null;
}

// Registra cada segmento bajo el estilo "/YYYY/MM/DD/<numero-corto>.ts" y lo sirve por play.php?ts=/YYYY/MM/DD/<numero-corto>.ts
// para que conserve ese estilo en el código fuente, oculte ftlly.com y funcione en TODOS los reproductores y hostings.
function register_short_ts_path($absSegUrl, &$tsMap) {
    $datePrefix = '/' . date('Y/m/d') . '/';
    $shortNum   = sprintf('%06u', (abs(crc32($absSegUrl)) % 900000) + 100000);
    $shortPath  = $datePrefix . $shortNum . '.ts';

    $tsMap[$shortNum]  = array('u' => $absSegUrl, 't' => time());
    $tsMap[$shortPath] = array('u' => $absSegUrl, 't' => time());

    return 'play.php?ts=' . $shortPath;
}

function output_rewritten_m3u8($body, $baseEff) {
    global $TS_MAP_FILES;

    $tsMap = read_first_json($TS_MAP_FILES);
    $now   = time();
    foreach ($tsMap as $k => $v) {
        if (!is_array($v) || ($now - (int)(isset($v['t']) ? $v['t'] : 0)) > 600) {
            unset($tsMap[$k]);
        }
    }

    // SIEMPRE application/vnd.apple.mpegurl para que nunca salga la pantalla negra de texto plano y siempre reproduzca video
    header('Access-Control-Allow-Origin: *');
    header('Content-Type: application/vnd.apple.mpegurl');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $lines = preg_split('/\r\n|\r|\n/', (string)$body);
    $out   = array();

    foreach ($lines as $line) {
        $t = trim($line);
        if ($t === '') {
            $out[] = '';
            continue;
        }
        if (str_starts_with($t, '#')) {
            if (preg_match('/URI="([^"]+)"/i', $t, $um)) {
                $absKey   = resolve_url($baseEff, $um[1]);
                $shortUri = register_short_ts_path($absKey, $tsMap);
                $t        = str_replace($um[0], 'URI="' . $shortUri . '"', $t);
            }
            $out[] = $t;
        } else {
            $absSeg = resolve_url($baseEff, $t);
            $out[]  = register_short_ts_path($absSeg, $tsMap);
        }
    }

    write_all_json($TS_MAP_FILES, $tsMap);
    echo implode("\n", $out);
    exit;
}

function find_iframe_in_diaries($reqId) {
    global $AGENDA_API, $ORIGIN_HDR, $EMBED_BASE, $BACKUP_FILES;

    $rawBody = '';
    foreach ($BACKUP_FILES as $bf) {
        if (@file_exists($bf) && (time() - (int)@filemtime($bf)) < 300) {
            $rawBody = (string)@file_get_contents($bf);
            if ($rawBody !== '') break;
        }
    }
    if ($rawBody === '') {
        $res     = curl_fast_get($AGENDA_API . '?_t=' . time(), $ORIGIN_HDR . '/', 8, true);
        $rawBody = $res['body'];
    }
    if ($rawBody === '') return array();

    $rawJson = @json_decode($rawBody, true);
    $items   = is_array($rawJson) ? (isset($rawJson['data']) ? $rawJson['data'] : $rawJson) : array();
    if (!is_array($items)) return array();

    $cleanReq = str_replace(array('-', '_', ' '), '', preg_replace('/-(hd|op[0-9]+)$/', '', $reqId));
    $matches  = array();

    foreach ($items as $item) {
        $attr      = isset($item['attributes']) ? $item['attributes'] : $item;
        $rawEmbeds = isset($attr['embeds']['data']) && is_array($attr['embeds']['data'])
            ? $attr['embeds']['data']
            : (isset($attr['embeds']) && is_array($attr['embeds']) ? $attr['embeds'] : array());

        foreach ($rawEmbeds as $emb) {
            $eAttr     = isset($emb['attributes']) ? $emb['attributes'] : $emb;
            $chName    = !empty($eAttr['embed_name']) ? (string)$eAttr['embed_name'] : '';
            $rawIframe = !empty($eAttr['emded_iframe']) ? $eAttr['emded_iframe'] : (!empty($eAttr['embed_iframe']) ? $eAttr['embed_iframe'] : '');
            if ($rawIframe === '') continue;

            $fullIframe = str_starts_with($rawIframe, 'http')
                ? $rawIframe
                : (str_starts_with($rawIframe, '/') ? $ORIGIN_HDR . $rawIframe : $EMBED_BASE . rawurlencode($rawIframe));

            $decodedTarget = decode_r_param($fullIframe);
            $cleanName     = preg_replace('/[^a-z0-9]/', '', strtolower($chName));

            if ($cleanReq !== '' && (str_contains(strtolower($decodedTarget), $cleanReq) || str_contains($cleanName, $cleanReq))) {
                $matches[] = $decodedTarget;
            }
        }
    }
    return array_values(array_unique($matches));
}

// =========================================================================
// 1. MANEJO DE SEGMENTOS .TS (?ts=/YYYY/MM/DD/123456.ts)
// =========================================================================
$segParam = '';
if (!empty($_GET['ts']))      $segParam = trim((string)$_GET['ts']);
elseif (!empty($_GET['seg'])) $segParam = trim((string)$_GET['seg']);
elseif (!empty($_GET['k']))   $segParam = trim((string)$_GET['k']);
elseif (!empty($_SERVER['PATH_INFO']) && preg_match('/([0-9]{4}\/[0-9]{2}\/[0-9]{2}\/[0-9a-zA-Z_-]+\.ts)$/i', $_SERVER['PATH_INFO'], $pim)) {
    $segParam = '/' . $pim[1];
}

if ($segParam !== '') {
    $segUrl = '';
    $tsMap  = read_first_json($TS_MAP_FILES);

    if (isset($tsMap[$segParam]['u'])) {
        $segUrl = normalize_stream_url($tsMap[$segParam]['u']);
    } elseif (preg_match('/([0-9]{5,8})\.ts$/i', $segParam, $nm) && isset($tsMap[$nm[1]]['u'])) {
        $segUrl = normalize_stream_url($tsMap[$nm[1]]['u']);
    } else {
        $dec = normalize_stream_url(b64url_decode($segParam));
        if (preg_match('/^https?:\/\//i', $dec)) {
            $segUrl = $dec;
        }
    }

    if ($segUrl === '' || !preg_match('/^https?:\/\//i', $segUrl)) {
        http_response_code(404);
        exit('Segment expired or not found');
    }

    if (str_contains($segUrl, '.m3u8')) {
        $res = curl_fast_get($segUrl, 'https://tvf90.com/', 6, true);
        if (str_starts_with(ltrim($res['body']), '#EXTM3U')) {
            output_rewritten_m3u8($res['body'], !empty($res['effective']) ? $res['effective'] : $segUrl);
        }
    }

    stream_binary_segment($segUrl, 'https://tvf90.com/');
}

// =========================================================================
// 2. RESOLUCIÓN DE CANALES (?id=..., ?stream=... o ?r=...)
// =========================================================================
if (!empty($_GET['r'])) {
    $directIframe = decode_r_param((string)$_GET['r']);
    $resolved     = resolve_working_m3u8_from_iframe($directIframe);
    if ($resolved) {
        output_rewritten_m3u8($resolved['body'], $resolved['effective']);
    }
}

$reqId = '';
if (!empty($_GET['id']))         $reqId = trim((string)$_GET['id']);
elseif (!empty($_GET['stream'])) $reqId = trim((string)$_GET['stream']);
$reqId = preg_replace('/[^a-z0-9_-]/', '', strtolower($reqId));

if ($reqId === '') {
    header('Content-Type: text/plain; charset=utf-8');
    exit('Missing channel ID (?id= o ?stream=)');
}

$fastCache = read_first_json($CACHE_FILES);
if (isset($fastCache[$reqId]) && !empty($fastCache[$reqId]['m3u8']) && (time() - (int)$fastCache[$reqId]['ts']) < 90) {
    $cachedM3u8 = normalize_stream_url($fastCache[$reqId]['m3u8']);
    $chk        = curl_fast_get($cachedM3u8, 'https://tvf90.com/', 5, true);
    if (str_starts_with(ltrim($chk['body']), '#EXTM3U')) {
        output_rewritten_m3u8($chk['body'], !empty($chk['effective']) ? $chk['effective'] : $cachedM3u8);
    }
    unset($fastCache[$reqId]);
}

$mapData       = read_first_json($MAP_FILES);
$candidateUrls = array();

if (isset($mapData[$reqId])) {
    if (!empty($mapData[$reqId]['target'])) {
        $candidateUrls[] = decode_r_param($mapData[$reqId]['target']);
    } elseif (!empty($mapData[$reqId]['target_b64'])) {
        $candidateUrls[] = decode_r_param((string)@base64_decode($mapData[$reqId]['target_b64']));
    }
}

$fromDiaries = find_iframe_in_diaries($reqId);
foreach ($fromDiaries as $fd) {
    $candidateUrls[] = $fd;
}

$isHdRequest      = false;
$preferredServers = array('1', '2', '3', '4', 'hd', '5', '6');
$baseReq          = $reqId;

if (preg_match('/^(.+?)-hd(?:-op([0-9]+))?$/', $reqId, $m)) {
    $baseReq          = $m[1];
    $isHdRequest      = true;
    $preferredServers = array('hd', '1', '2', '3', '4', '5', '6');
} elseif (preg_match('/^(.+?)-op([0-9]+)$/', $reqId, $m)) {
    $baseReq          = $m[1];
    $opSrv            = (string)(int)$m[2];
    $preferredServers = array_values(array_unique(array_merge(array($opSrv), $preferredServers)));
}

$noCountry = preg_replace('/-(argentina|chile|colombia|peru|mexico|uruguay|ecuador|espanol|es|ar|cl|co|pe|mx)$/', '', $baseReq);

$streamVariants = array_values(array_unique(array_filter(array(
    str_replace(array('-', '_', ' '), '', $baseReq),
    str_replace(array('-', '_', ' '), '', $noCountry),
    $baseReq,
    $noCountry
))));

$knownAliases = array(
    'beinsportes'   => array('beinsportes', 'beinsports', 'bein', 'beinsport', 'bein1', 'beinsportses'),
    'beinsport'     => array('beinsportes', 'beinsports', 'bein', 'beinsport', 'bein1'),
    'dsports'       => array('dsports', 'directvsports', 'dsportsar', 'dsports1', 'dsportsargentina'),
    'espnpremium'   => array('espnpremiumar', 'espnpremium', 'espn'),
    'espnpremiumar' => array('espnpremiumar', 'espnpremium', 'espn'),
    'tntsports'     => array('tntsportsar', 'tntsports', 'tntsportschile'),
    'tntsportsar'   => array('tntsportsar', 'tntsports', 'tntsportschile'),
    'winsports'     => array('winsports', 'winsportsplus', 'winplus'),
    'foxdeportes'   => array('foxdeportes', 'foxsports', 'foxsports1')
);

foreach ($knownAliases as $k => $extras) {
    if (in_array($k, $streamVariants, true)) {
        $streamVariants = array_values(array_unique(array_merge($streamVariants, $extras)));
    }
}

foreach ($streamVariants as $stName) {
    if ($isHdRequest) {
        $candidateUrls[] = $PROVIDER_BASE . 'hd/' . $stName . '.php';
        $candidateUrls[] = $PROVIDER_BASE . 'hd.php?stream=' . $stName;
        $candidateUrls[] = $PROVIDER_BASE . 'hd/hd.php?stream=' . $stName;
    }

    foreach ($preferredServers as $srv) {
        if ($srv === 'hd') {
            $candidateUrls[] = $PROVIDER_BASE . 'hd.php?stream=' . $stName;
            $candidateUrls[] = $PROVIDER_BASE . 'hd/' . $stName . '.php';
            $candidateUrls[] = $PROVIDER_BASE . 'hd/hd.php?stream=' . $stName;
        } else {
            $candidateUrls[] = $PROVIDER_BASE . $srv . '.php?stream=' . $stName;
        }
    }

    $candidateUrls[] = $PROVIDER_BASE . $stName . '.php';
}

$resolved = null;
foreach (array_unique($candidateUrls) as $tryIframe) {
    $res = resolve_working_m3u8_from_iframe($tryIframe);
    if (is_array($res) && !empty($res['m3u8'])) {
        $resolved = $res;
        break;
    }
}

if (!$resolved) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array(
        'ok'    => false,
        'error' => 'No se encontró un mono.m3u8 activo con #EXTM3U en tvf90.com para este canal',
        'id'    => $reqId,
        'tried' => array_values(array_unique($candidateUrls))
    ), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

$fastCache[$reqId] = array(
    'm3u8' => $resolved['m3u8'],
    'ref'  => $resolved['ref'],
    'ts'   => time()
);
write_all_json($CACHE_FILES, $fastCache);

output_rewritten_m3u8($resolved['body'], $resolved['effective']);