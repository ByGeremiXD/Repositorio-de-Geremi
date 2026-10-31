<?php
/**
 * canal.php - Extractor recursivo Multi-Dominio + Anti-Bloqueo HLS (index.m3u8 -> .ts)
 *
 * Uso:
 *   - Por ID predefinido:   https://tu-dominio/pages/canal.php?id=skysportslaliga&ext=m3u8
 *   - Cualquier otro canal: https://tu-dominio/pages/canal.php?id=espn&ext=m3u8
 *   - Cualquier URL/dominio:https://tu-dominio/pages/canal.php?url=https://otro-dominio.com/canal.php&ext=m3u8
 *   - Debug JSON:           https://tu-dominio/pages/canal.php?id=skysportslaliga&debug=1
 */

declare(strict_types=1);
error_reporting(0);
ini_set('display_errors', '0');
set_time_limit(0);

const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
const CACHE_TTL  = 90;
const MAX_HOPS   = 8;

const CHANNELS = [
    'skysportslaliga' => 'https://www.tvporinternet.org/sky-sports-la-liga-en-vivo-online.php',
    'daznlaliga'      => 'https://www.tvporinternet.org/dazn-la-liga-en-vivo-online.php',
    'movistarlaliga'  => 'https://www.tvporinternet.org/movistar-la-liga-en-vivo-online.php',
    'espn'            => 'https://www.tvporinternet.org/espn-en-vivo-online.php',
    'espn2'           => 'https://www.tvporinternet.org/espn-2-en-vivo-online.php',
    'espn3'           => 'https://www.tvporinternet.org/espn-3-en-vivo-online.php',
    'espnextra'       => 'https://www.tvporinternet.org/espn-extra-en-vivo-online.php',
    'foxsports'       => 'https://www.tvporinternet.org/fox-sports-en-vivo-online.php',
    'foxsports2'      => 'https://www.tvporinternet.org/fox-sports-2-en-vivo-online.php',
    'foxsports3'      => 'https://www.tvporinternet.org/fox-sports-3-en-vivo-online.php',
    'winplus'         => 'https://www.tvporinternet.org/win-sports-plus-en-vivo-online.php',
    'tntsports'       => 'https://www.tvporinternet.org/tnt-sports-en-vivo-online.php',
    'tycsports'       => 'https://www.tvporinternet.org/tyc-sports-en-vivo-online.php',
    'directvsports'   => 'https://www.tvporinternet.org/directv-sports-en-vivo-online.php',
];

ensureHtaccess();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: Range, Origin, X-Requested-With, Content-Type, Accept, Authorization');
header('Access-Control-Expose-Headers: Content-Length, Content-Range');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$rawUri   = (string)($_SERVER['REQUEST_URI'] ?? '');
$rawQuery = (string)($_SERVER['QUERY_STRING'] ?? '');
$pathInfo = (string)($_SERVER['PATH_INFO'] ?? '');

$channelId = isset($_GET['id']) && $_GET['id'] !== ''
    ? preg_replace('/[^a-zA-Z0-9_-]/', '', strtolower(trim((string)$_GET['id'])))
    : 'skysportslaliga';

$customUrl = isset($_GET['url']) && filter_var($_GET['url'], FILTER_VALIDATE_URL)
    ? trim((string)$_GET['url'])
    : '';

if ($customUrl !== '') {
    $targetPage = $customUrl;
    $stateKey   = 'url_' . substr(md5($customUrl), 0, 12);
} elseif (isset(CHANNELS[$channelId])) {
    $targetPage = CHANNELS[$channelId];
    $stateKey   = $channelId;
} else {
    $targetPage = 'https://www.tvporinternet.org/' . $channelId . '-en-vivo-online.php';
    $stateKey   = $channelId;
}

$cacheFile  = sys_get_temp_dir() . '/hls_state_' . $stateKey . '.json';
$cookieFile = sys_get_temp_dir() . '/hls_cookie_' . $stateKey . '.txt';
$latestFile = sys_get_temp_dir() . '/hls_latest_active.json';

// -----------------------------------------------------------------------------
// 1. DETECCIÓN Y REPRODUCCIÓN DE SEGMENTOS .TS
// -----------------------------------------------------------------------------
$segParam = '';
if (!empty($_GET['seg'])) {
    $segParam = (string)$_GET['seg'];
} elseif (preg_match('#canal\.php=\/?([^?&]+)#i', $rawUri, $m)) {
    $segParam = $m[1];
} elseif (preg_match('#^=\/?([^&]+)#', $rawQuery, $m)) {
    $segParam = $m[1];
} elseif ($pathInfo !== '') {
    $segParam = ltrim($pathInfo, '/');
}

$isTsRequest = (($_GET['ext'] ?? '') === 'ts') || ($segParam !== '' && str_contains($segParam, '.ts'));

if ($isTsRequest) {
    $state = [];
    if (is_file($cacheFile)) {
        $state = json_decode((string)file_get_contents($cacheFile), true) ?: [];
    }
    if (empty($state['track_url']) && is_file($latestFile)) {
        $state = json_decode((string)file_get_contents($latestFile), true) ?: [];
    }
    if (empty($state['track_url'])) {
        $state = loadOrResolveState($targetPage, $channelId, $cacheFile, $cookieFile, false);
    }

    if (empty($state['track_url']) || empty($state['referer'])) {
        http_response_code(502);
        exit;
    }

    $segKey      = basename((string)parse_url($segParam, PHP_URL_PATH) ?: $segParam);
    $upstreamUrl = (string)($state['segments'][$segKey] ?? '');

    if ($upstreamUrl === '') {
        $state       = refreshMediaSegments($state, $cacheFile, $latestFile, $cookieFile);
        $upstreamUrl = (string)($state['segments'][$segKey] ?? $state['segments']['latest'] ?? '');
    }

    if ($upstreamUrl === '') {
        http_response_code(404);
        exit;
    }

    header('Content-Type: video/mp2t');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    $ok = streamSegment($upstreamUrl, (string)$state['referer'], (string)$state['origin'], $cookieFile);
    if (!$ok) {
        $state       = loadOrResolveState($targetPage, $channelId, $cacheFile, $cookieFile, true);
        $state       = refreshMediaSegments($state, $cacheFile, $latestFile, $cookieFile);
        $retryUrl    = (string)($state['segments'][$segKey] ?? $state['segments']['latest'] ?? '');
        if ($retryUrl !== '') {
            streamSegment($retryUrl, (string)$state['referer'], (string)$state['origin'], $cookieFile);
        }
    }
    exit;
}

// -----------------------------------------------------------------------------
// 2. EXTRACCIÓN Y SALIDA DE PLAYLIST (.M3U8) CON ANTI-BLOQUEO
// -----------------------------------------------------------------------------
$forceRefresh = isset($_GET['refresh']) || isset($_GET['debug']);
$state        = loadOrResolveState($targetPage, $channelId, $cacheFile, $cookieFile, $forceRefresh);

if (empty($state['track_url'])) {
    if (isset($_GET['debug'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status'      => 'error',
            'channel_id'  => $channelId,
            'target_page' => $targetPage,
            'trace'       => $state['trace'] ?? [],
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }
    http_response_code(502);
    header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
    exit("#EXTM3U\n#EXT-X-ERROR: No se pudo extraer el index.m3u8 del iframe.");
}

$mediaRes = fetchWithAntiBlock($state['track_url'], $state['referer'], $state['origin'], $cookieFile);

if ($mediaRes['status'] !== 200 || !str_contains($mediaRes['body'], '#EXTM3U')) {
    $state    = loadOrResolveState($targetPage, $channelId, $cacheFile, $cookieFile, true);
    $mediaRes = fetchWithAntiBlock((string)($state['track_url'] ?? ''), (string)($state['referer'] ?? ''), (string)($state['origin'] ?? ''), $cookieFile);
}

if (str_contains($mediaRes['body'], '#EXT-X-STREAM-INF')) {
    $subTrackUrl = pickVariantUrl($mediaRes['body'], $mediaRes['effective_url']);
    if ($subTrackUrl !== '') {
        $state['track_url'] = $subTrackUrl;
        $mediaRes           = fetchWithAntiBlock($subTrackUrl, $state['referer'], $state['origin'], $cookieFile);
    }
}

if (isset($_GET['debug'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status'            => 'ok',
        'id'                => $channelId,
        'master_m3u8'       => $state['master_url'] ?? '',
        'media_track_m3u8'  => $state['track_url'] ?? '',
        'referer_injected'  => $state['referer'] ?? '',
        'origin_injected'   => $state['origin'] ?? '',
        'http_status_m3u8'  => $mediaRes['status'],
        'sample_ts_output'  => 'canal.php=/' . date('Y/m/d') . '/seg-1.ts&ext=ts',
        'trace'             => $state['trace'] ?? [],
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
header('Content-Disposition: inline; filename="' . $channelId . '.m3u8"');
header('Cache-Control: no-cache, no-store, must-revalidate');

echo buildCleanMediaPlaylist($mediaRes['body'], $mediaRes['effective_url'], $state, $cacheFile, $latestFile);
exit;

// -----------------------------------------------------------------------------
// 3. FUNCIONES DE RED, ANTI-BLOQUEO Y REESCRITURA
// -----------------------------------------------------------------------------

function ensureHtaccess(): void {
    $htFile = __DIR__ . '/.htaccess';
    $rules  = "<IfModule mod_rewrite.c>\n"
            . "RewriteEngine On\n"
            . "RewriteRule ^canal\\.php=(.*)$ canal.php?seg=$1 [L,QSA]\n"
            . "</IfModule>\n";

    if (!is_file($htFile)) {
        @file_put_contents($htFile, $rules);
    } else {
        $curr = (string)@file_get_contents($htFile);
        if (!str_contains($curr, 'canal\.php=')) {
            @file_put_contents($htFile, rtrim($curr) . "\n" . $rules);
        }
    }
}

function buildAntiBlockHeaders(string $referer = '', string $origin = '', bool $isHlsRequest = false): array {
    $clientIp = (string)($_SERVER['HTTP_CF_CONNECTING_IP']
        ?? $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR']
        ?? '');
    if (str_contains($clientIp, ',')) {
        $clientIp = trim(explode(',', $clientIp)[0]);
    }

    $headers = [
        'Accept: ' . ($isHlsRequest ? '*/*' : 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8'),
        'Accept-Language: es-ES,es;q=0.9,en-US;q=0.8,en;q=0.7',
        'Cache-Control: no-cache',
        'Pragma: no-cache',
        'Sec-Ch-Ua: "Google Chrome";v="131", "Chromium";v="131", "Not_A Brand";v="24"',
        'Sec-Ch-Ua-Mobile: ?0',
        'Sec-Ch-Ua-Platform: "Windows"',
        'Sec-Fetch-Dest: ' . ($isHlsRequest ? 'empty' : 'iframe'),
        'Sec-Fetch-Mode: ' . ($isHlsRequest ? 'cors' : 'navigate'),
        'Sec-Fetch-Site: cross-site',
        'Connection: keep-alive',
    ];

    if ($referer !== '') {
        $headers[] = 'Referer: ' . $referer;
    }
    if ($origin !== '') {
        $headers[] = 'Origin: ' . $origin;
    }
    if ($clientIp !== '' && filter_var($clientIp, FILTER_VALIDATE_IP)) {
        $headers[] = 'X-Forwarded-For: ' . $clientIp;
        $headers[] = 'X-Real-IP: ' . $clientIp;
        $headers[] = 'Client-IP: ' . $clientIp;
    }

    return $headers;
}

function fetchWithAntiBlock(string $url, string $referer = '', string $origin = '', string $cookieFile = ''): array {
    if ($url === '') {
        return ['body' => '', 'effective_url' => '', 'status' => 0];
    }

    $isHls = stripos($url, '.m3u8') !== false || stripos($url, '.ts') !== false;

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_ENCODING       => '',
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_USERAGENT      => USER_AGENT,
        CURLOPT_HTTPHEADER     => buildAntiBlockHeaders($referer, $origin, $isHls),
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
    ];

    if ($cookieFile !== '') {
        $opts[CURLOPT_COOKIEJAR]  = $cookieFile;
        $opts[CURLOPT_COOKIEFILE] = $cookieFile;
    }

    curl_setopt_array($ch, $opts);

    $body         = (string)curl_exec($ch);
    $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $status       = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'body'          => $body,
        'effective_url' => $effectiveUrl !== '' ? $effectiveUrl : $url,
        'status'        => $status,
    ];
}

function streamSegment(string $url, string $referer, string $origin, string $cookieFile): bool {
    $bytesSent = 0;

    $ch = curl_init($url);
    $opts = [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_HEADER         => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERAGENT      => USER_AGENT,
        CURLOPT_HTTPHEADER     => buildAntiBlockHeaders($referer, $origin, true),
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_WRITEFUNCTION  => static function ($ch, string $chunk) use (&$bytesSent): int {
            $len = strlen($chunk);
            $bytesSent += $len;
            echo $chunk;
            if (ob_get_level() > 0) {
                @ob_flush();
            }
            flush();
            return $len;
        },
    ];

    if ($cookieFile !== '') {
        $opts[CURLOPT_COOKIEJAR]  = $cookieFile;
        $opts[CURLOPT_COOKIEFILE] = $cookieFile;
    }

    curl_setopt_array($ch, $opts);
    curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($status >= 200 && $status < 300 && $bytesSent > 0);
}

function pickVariantUrl(string $masterBody, string $masterUrl): string {
    $lines      = preg_split('/\r\n|\r|\n/', $masterBody);
    $candidates = [];

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }
        $abs = resolveUrl($trimmed, $masterUrl);
        if (stripos($abs, 'v1') !== false) {
            return $abs;
        }
        $candidates[] = $abs;
    }

    return $candidates[0] ?? '';
}

function buildCleanMediaPlaylist(string $manifestBody, string $manifestUrl, array &$state, string $cacheFile, string $latestFile): string {
    $lines    = preg_split('/\r\n|\r|\n/', $manifestBody);
    $out      = [];
    $datePath = date('Y/m/d');
    $segments = $state['segments'] ?? [];
    $useEqual = is_file(__DIR__ . '/.htaccess');
    $prefix   = $useEqual ? 'canal.php=/' : 'canal.php?=/';

    $parentQuery = (string)parse_url($manifestUrl, PHP_URL_QUERY);

    $seq = 0;
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            $out[] = '';
            continue;
        }

        if (str_starts_with($trimmed, '#')) {
            if (preg_match('/^#EXT-X-MEDIA-SEQUENCE:(\d+)/i', $trimmed, $m)) {
                $seq = (int)$m[1];
            }
            if (stripos($trimmed, 'URI="') !== false) {
                $trimmed = (string)preg_replace_callback(
                    '#URI="([^"]+)"#i',
                    static function (array $m) use ($manifestUrl, $parentQuery, &$segments, $datePath, $prefix): string {
                        $absUrl = resolveUrl($m[1], $manifestUrl);
                        if ($parentQuery !== '' && !str_contains($absUrl, '?')) {
                            $absUrl .= '?' . $parentQuery;
                        }
                        $keyId = 'key-' . substr(md5($absUrl), 0, 8) . '.ts';
                        $segments[$keyId] = $absUrl;
                        return 'URI="' . $prefix . $datePath . '/' . $keyId . '&ext=ts"';
                    },
                    $trimmed
                );
            }
            $out[] = $trimmed;
            continue;
        }

        $absSegmentUrl = resolveUrl($trimmed, $manifestUrl);
        if ($parentQuery !== '' && !str_contains($absSegmentUrl, '?')) {
            $absSegmentUrl .= '?' . $parentQuery;
        }

        $segId = 'seg-' . ($seq > 0 ? $seq++ : substr(md5($trimmed), 0, 8)) . '.ts';

        $segments[$segId]   = $absSegmentUrl;
        $segments['latest'] = $absSegmentUrl;

        $out[] = $prefix . $datePath . '/' . $segId . '&ext=ts';
    }

    if (count($segments) > 80) {
        $segments = array_slice($segments, -80, null, true);
    }

    $state['segments']   = $segments;
    $state['updated_at'] = time();

    $encoded = json_encode($state, JSON_UNESCAPED_SLASHES);
    @file_put_contents($cacheFile, $encoded);
    @file_put_contents($latestFile, $encoded);

    return implode("\n", $out);
}

function refreshMediaSegments(array $state, string $cacheFile, string $latestFile, string $cookieFile): array {
    if (empty($state['track_url'])) {
        return $state;
    }
    $res = fetchWithAntiBlock((string)$state['track_url'], (string)$state['referer'], (string)$state['origin'], $cookieFile);
    if ($res['status'] === 200 && str_contains($res['body'], '#EXTINF')) {
        buildCleanMediaPlaylist($res['body'], $res['effective_url'], $state, $cacheFile, $latestFile);
    }
    return $state;
}

function loadOrResolveState(string $startUrl, string $channelId, string $cacheFile, string $cookieFile, bool $forceRefresh = false): array {
    if (!$forceRefresh && is_file($cacheFile) && (time() - filemtime($cacheFile)) < CACHE_TTL) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached) && !empty($cached['track_url']) && !empty($cached['referer'])) {
            return $cached;
        }
    }

    $trace   = [];
    $visited = [];

    $dfs = static function (string $url, string $referer, int $depth) use (&$dfs, &$trace, &$visited, $channelId, $cookieFile): ?array {
        if ($depth > MAX_HOPS || isset($visited[$url])) {
            return null;
        }
        $visited[$url] = true;

        $refParts = parse_url($referer);
        $origin   = isset($refParts['host']) ? (($refParts['scheme'] ?? 'https') . '://' . $refParts['host']) : '';

        $res          = fetchWithAntiBlock($url, $referer, $origin, $cookieFile);
        $effectiveUrl = $res['effective_url'];
        $body         = $res['body'];

        $trace[] = [
            'step'          => count($trace) + 1,
            'requested_url' => $url,
            'effective_url' => $effectiveUrl,
            'referer_sent'  => $referer,
            'http_status'   => $res['status'],
        ];

        if ($body === '') {
            return null;
        }

        if (str_contains($body, '#EXTM3U')) {
            $effParts     = parse_url($referer !== '' ? $referer : $effectiveUrl);
            $streamOrigin = ($effParts['scheme'] ?? 'https') . '://' . ($effParts['host'] ?? '');
            return [
                'master_url' => $effectiveUrl,
                'referer'    => $referer !== '' ? $referer : $effectiveUrl,
                'origin'     => $streamOrigin,
            ];
        }

        $m3u8 = extractM3u8FromHtml($body, $effectiveUrl);
        if ($m3u8 !== null) {
            $effParts     = parse_url($effectiveUrl);
            $streamOrigin = ($effParts['scheme'] ?? 'https') . '://' . ($effParts['host'] ?? '');
            return [
                'master_url' => $m3u8,
                'referer'    => $effectiveUrl,
                'origin'     => $streamOrigin,
            ];
        }

        foreach (extractCandidateIframes($body, $effectiveUrl, $channelId) as $childUrl) {
            $found = $dfs($childUrl, $effectiveUrl, $depth + 1);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    };

    $startParts   = parse_url($startUrl);
    $rootReferer  = ($startParts['scheme'] ?? 'https') . '://' . ($startParts['host'] ?? 'www.tvporinternet.org') . '/';

    $found = $dfs($startUrl, $rootReferer, 0);
    if ($found === null) {
        return ['trace' => $trace];
    }

    $masterRes = fetchWithAntiBlock($found['master_url'], $found['referer'], $found['origin'], $cookieFile);
    $trackUrl  = $masterRes['effective_url'] !== '' ? $masterRes['effective_url'] : $found['master_url'];

    if (str_contains($masterRes['body'], '#EXT-X-STREAM-INF')) {
        $variant = pickVariantUrl($masterRes['body'], $trackUrl);
        if ($variant !== '') {
            $masterQuery = (string)parse_url($trackUrl, PHP_URL_QUERY);
            if ($masterQuery !== '' && !str_contains($variant, '?')) {
                $variant .= '?' . $masterQuery;
            }
            $trackUrl = $variant;
        }
    }

    $state = [
        'master_url' => $found['master_url'],
        'track_url'  => $trackUrl,
        'referer'    => $found['referer'],
        'origin'     => $found['origin'],
        'segments'   => [],
        'trace'      => $trace,
        'updated_at' => time(),
    ];

    @file_put_contents($cacheFile, json_encode($state, JSON_UNESCAPED_SLASHES));
    return $state;
}

function resolveUrl(string $rel, string $baseUrl): string {
    $rel = trim(html_entity_decode($rel, ENT_QUOTES | ENT_HTML5));
    if ($rel === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $rel)) {
        return $rel;
    }
    $parts  = parse_url($baseUrl);
    $scheme = $parts['scheme'] ?? 'https';
    $host   = $parts['host'] ?? '';
    $port   = isset($parts['port']) ? ':' . $parts['port'] : '';

    if (str_starts_with($rel, '//')) {
        return $scheme . ':' . $rel;
    }
    if (str_starts_with($rel, '/')) {
        return $scheme . '://' . $host . $port . $rel;
    }
    $path = $parts['path'] ?? '/';
    $dir  = rtrim(substr($path, 0, (int)strrpos($path, '/') + 1), '/');
    return $scheme . '://' . $host . $port . $dir . '/' . $rel;
}

function unwrapBase64Redirect(string $url, string $baseUrl): string {
    $parsed = parse_url($url);
    if (!isset($parsed['query'])) {
        return $url;
    }
    parse_str($parsed['query'], $queryParams);
    foreach (['r', 'url', 'src', 'id', 'stream', 'get'] as $key) {
        if (!empty($queryParams[$key]) && is_string($queryParams[$key])) {
            $candidate = trim($queryParams[$key]);
            $decoded   = base64_decode(strtr($candidate, '-_', '+/'), true);
            if ($decoded !== false && preg_match('#^(https?://|//|/|[a-zA-Z0-9_-]+\.(?:php|html))#i', $decoded)) {
                return resolveUrl($decoded, $baseUrl);
            }
        }
    }
    return $url;
}

function extractM3u8FromHtml(string $html, string $currentUrl): ?string {
    if (preg_match('#https?:\\\\?/\\\\?/[^"\'\s<>`]+?\.m3u8(?:\?[^"\'\s<>`]*)?#i', $html, $m)) {
        return stripslashes($m[0]);
    }
    if (preg_match('#(?:playbackURL|source|file|streamUrl|hlsUrl|videoUrl|manifest)\s*[:=]\s*["\']([^"\']+\.m3u8[^"\']*)["\']#i', $html, $m)) {
        return resolveUrl(stripslashes($m[1]), $currentUrl);
    }
    if (preg_match_all('#atob\(\s*["\']([A-Za-z0-9+/=_-]{16,})["\']\s*\)#i', $html, $matches)) {
        foreach ($matches[1] as $b64) {
            $decoded = base64_decode(strtr($b64, '-_', '+/'), true);
            if ($decoded !== false && stripos($decoded, '.m3u8') !== false) {
                if (preg_match('#https?://[^"\'\s<>]+?\.m3u8(?:\?[^"\'\s<>]*)?#i', $decoded, $mUrl)) {
                    return $mUrl[0];
                }
                return resolveUrl(trim($decoded), $currentUrl);
            }
        }
    }
    if (preg_match('#\[((?:\d+\s*,\s*){15,}\d+)\]#', $html, $m)) {
        $nums = array_map('intval', explode(',', $m[1]));
        $str  = implode('', array_map('chr', $nums));
        if (stripos($str, '.m3u8') !== false && preg_match('#https?://[^\s"\'<>]+?\.m3u8[^\s"\'<>]*#i', $str, $mUrl)) {
            return $mUrl[0];
        }
    }
    return null;
}

function extractCandidateIframes(string $html, string $baseUrl, string $channelId): array {
    $candidates = [];
    $quotedId   = preg_quote($channelId, '#');

    if (preg_match_all('#(?:href|data-src|data-url|data-iframe|src|onclick)\s*=\s*["\']([^"\']*?(?:embed\d*\.php|embed\.html\?r=|' . $quotedId . ')[^"\']*?)["\']#i', $html, $m)) {
        foreach ($m[1] as $raw) {
            if (preg_match('#["\'](https?://[^"\']+|//[^"\']+|/[^"\']+|embed[^"\']+)["\']#i', $raw, $sub)) {
                $raw = $sub[1];
            }
            $resolved     = resolveUrl($raw, $baseUrl);
            $candidates[] = unwrapBase64Redirect($resolved, $baseUrl);
            $candidates[] = $resolved;
        }
    }

    if (preg_match_all('#<iframe[^>]+(?:src|data-src)\s*=\s*["\']([^"\']+)["\']#i', $html, $m)) {
        foreach ($m[1] as $src) {
            if (preg_match('#(chat|otti|widget|facebook|twitter|ads|doubleclick|googletagmanager|analytics|about:blank)#i', $src)) {
                continue;
            }
            $resolved     = resolveUrl($src, $baseUrl);
            $candidates[] = unwrapBase64Redirect($resolved, $baseUrl);
            $candidates[] = $resolved;
        }
    }

    if (preg_match_all('#(?:location\.href|window\.location|iframe\.src|\.src)\s*=\s*["\']((?:https?:)?//[^"\']+|\.?/[^"\']+\.(?:php|html)[^"\']*)["\']#i', $html, $m)) {
        foreach ($m[1] as $src) {
            if (!preg_match('#(chat|ads|login)#i', $src)) {
                $resolved     = resolveUrl($src, $baseUrl);
                $candidates[] = unwrapBase64Redirect($resolved, $baseUrl);
                $candidates[] = $resolved;
            }
        }
    }

    return array_values(array_unique(array_filter($candidates)));
}