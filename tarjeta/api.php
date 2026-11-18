<?php
// api.php - Sincronización horaria mundial estilo Google Deportes (UTC epoch + multi-deporte en tiempo real),
// decodificación de iframes ?r= (1..6.php, hd.php, /hd/*.php) y cero MP3.
error_reporting(0);
@ini_set('display_errors', '0');
date_default_timezone_set('UTC');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: *');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

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

$CFG = array(
    'agenda_api' => base64_decode('aHR0cHM6Ly9hcGkud3F4YWcuY29tL2RpYXJpZXMuanNvbg=='),
    'origin_hdr' => base64_decode('aHR0cHM6Ly9yb2phZGlyZWN0YXR2bWFzLm5ldA=='),
    'embed_base' => base64_decode('aHR0cHM6Ly9yb2phZGlyZWN0YXR2bWFzLm5ldC9lbWJlZC9ldmVudG9zLmh0bWw/cj0='),
    // Zona horaria base nativa en la que el proveedor publica diary_hour (Perú/Colombia UTC-5)
    'source_tz'  => 'America/Lima'
);

$UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';

function get_storage_candidates($filename) {
    $list = array(__DIR__ . '/' . $filename);
    $tmp  = @sys_get_temp_dir();
    if (!empty($tmp) && @is_dir($tmp) && @is_writable($tmp)) {
        $list[] = rtrim($tmp, '/\\') . '/' . $filename;
    }
    return array_values(array_unique($list));
}

$MAP_FILES         = get_storage_candidates('.channels_map.json');
$IMG_FILES         = get_storage_candidates('.images_map.json');
$BACKUP_FILES      = get_storage_candidates('.diaries_backup.json');
$SPORTS_SYNC_FILES = get_storage_candidates('.sports_live_sync.json');

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

function fetch_remote_url($url, $originUrl = '', $timeout = 10) {
    global $UA;
    if (function_exists('curl_init')) {
        $ch = @curl_init($url);
        if ($ch) {
            $headers = array(
                'Accept: application/json, text/plain, */*',
                'Cache-Control: no-cache'
            );
            if ($originUrl !== '') {
                $headers[] = 'Referer: ' . $originUrl . '/';
                $headers[] = 'Origin: ' . $originUrl;
            }
            @curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            @curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            @curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
            @curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            @curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
            @curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            @curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            @curl_setopt($ch, CURLOPT_USERAGENT, $UA);
            @curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            @curl_setopt($ch, CURLOPT_ENCODING, '');
            if (defined('CURL_IPRESOLVE_V4')) {
                @curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            }
            $body = @curl_exec($ch);
            @curl_close($ch);
            if ($body !== false && $body !== '') return $body;
        }
    }

    $hdr = "User-Agent: " . $UA . "\r\nAccept: */*\r\n";
    if ($originUrl !== '') {
        $hdr .= "Referer: " . $originUrl . "/\r\nOrigin: " . $originUrl . "\r\n";
    }
    $ctx = @stream_context_create(array(
        'http' => array('method' => 'GET', 'timeout' => $timeout, 'ignore_errors' => true, 'header' => $hdr),
        'ssl'  => array('verify_peer' => false, 'verify_peer_name' => false)
    ));
    $body = @file_get_contents($url, false, $ctx);
    return $body !== false ? $body : '';
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
        $b64 = strtr($candidate, '-_ ', '+/+');
        $pad = strlen($b64) % 4;
        if ($pad) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $dec = @base64_decode($b64);
        if ($dec !== false && preg_match('/^https?:\/\//i', trim($dec))) {
            $current = trim($dec);
        } else {
            break;
        }
    }
    return $current;
}

function slugify_text($text) {
    $unaccent = strtr((string)$text, array(
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u',
        'Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u','Ñ'=>'n','Ü'=>'u'
    ));
    $s = strtolower($unaccent);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}

function normalize_team_key($name) {
    $s = slugify_text($name);
    // Quita palabras genéricas para cruzar equipos con el marcador oficial tipo Google Deportes
    $s = preg_replace('/\b(fc|cf|sc|ac|cd|ca|club|deportivo|atletico|real|united|city|mlb|nba|nhl|nfl|preseason)\b/', '', $s);
    $s = preg_replace('/[^a-z0-9]/', '', $s);
    return $s;
}

// Detecta el deporte para aplicar reglas de reloj, periodos y duración idénticas a Google Deportes
function detect_sport_type($league, $title, $country) {
    $hay = strtolower($league . ' ' . $title . ' ' . $country);
    if (preg_match('/\b(mlb|beisbol|baseball|lvbp|lmb|lidom|serie del caribe|spring training)\b/i', $hay)) {
        return array('sport' => 'mlb', 'duration_min' => 195, 'icon' => '⚾');
    }
    if (preg_match('/\b(nhl|hockey|stanley cup|khl)\b/i', $hay)) {
        return array('sport' => 'nhl', 'duration_min' => 160, 'icon' => '🏒');
    }
    if (preg_match('/\b(nba|basketball|baloncesto|basquet|euroliga|euroleague|ncaa basket|fiba|liga endesa|lnb)\b/i', $hay)) {
        return array('sport' => 'nba', 'duration_min' => 155, 'icon' => '🏀');
    }
    if (preg_match('/\b(nfl|super bowl|futbol americano|ncaa football)\b/i', $hay)) {
        return array('sport' => 'nfl', 'duration_min' => 195, 'icon' => '🏈');
    }
    if (preg_match('/\b(atp|wta|tenis|tennis|roland garros|wimbledon|us open|australian open|davis cup|laver cup)\b/i', $hay)) {
        return array('sport' => 'tennis', 'duration_min' => 160, 'icon' => '🎾');
    }
    if (preg_match('/\b(f1|formula 1|motogp|nascar|indycar|rally|wrc|automovilismo)\b/i', $hay)) {
        return array('sport' => 'motorsport', 'duration_min' => 135, 'icon' => '🏎️');
    }
    if (preg_match('/\b(ufc|mma|boxeo|boxing|bellator|pfl|wwe|aew|smackdown|raw|wrestlemania)\b/i', $hay)) {
        return array('sport' => 'combat', 'duration_min' => 210, 'icon' => '🥊');
    }
    return array('sport' => 'soccer', 'duration_min' => 118, 'icon' => '⚽');
}

// Obtiene marcadores y horarios UTC oficiales en vivo desde el feed público multi-deporte (estilo Google Deportes)
function get_live_sports_scoreboard_index() {
    global $SPORTS_SYNC_FILES, $UA;

    foreach ($SPORTS_SYNC_FILES as $sf) {
        if (@file_exists($sf) && (time() - (int)@filemtime($sf)) < 45) {
            $cached = @json_decode((string)@file_get_contents($sf), true);
            if (is_array($cached)) return $cached;
        }
    }

    $endpoints = array(
        'https://site.api.espn.com/apis/site/v2/sports/soccer/all/scoreboard',
        'https://site.api.espn.com/apis/site/v2/sports/baseball/mlb/scoreboard',
        'https://site.api.espn.com/apis/site/v2/sports/hockey/nhl/scoreboard',
        'https://site.api.espn.com/apis/site/v2/sports/basketball/nba/scoreboard',
        'https://site.api.espn.com/apis/site/v2/sports/football/nfl/scoreboard',
        'https://site.api.espn.com/apis/site/v2/sports/mma/ufc/scoreboard'
    );

    $responses = array();

    if (function_exists('curl_multi_init')) {
        $mh      = @curl_multi_init();
        $handles = array();
        foreach ($endpoints as $i => $url) {
            $ch = @curl_init($url);
            if ($ch) {
                @curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                @curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                @curl_setopt($ch, CURLOPT_TIMEOUT, 3);
                @curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
                @curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                @curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                @curl_setopt($ch, CURLOPT_USERAGENT, $UA);
                @curl_setopt($ch, CURLOPT_ENCODING, '');
                @curl_multi_add_handle($mh, $ch);
                $handles[$i] = $ch;
            }
        }
        $running = null;
        do {
            $status = @curl_multi_exec($mh, $running);
            if ($running) {
                @curl_multi_select($mh, 0.2);
            }
        } while ($running > 0 && $status === CURLM_OK);

        foreach ($handles as $i => $ch) {
            $body = @curl_multi_getcontent($ch);
            if ($body) $responses[] = $body;
            @curl_multi_remove_handle($mh, $ch);
            @curl_close($ch);
        }
        @curl_multi_close($mh);
    } else {
        // Fallback rápido para hostings sin curl_multi
        foreach (array_slice($endpoints, 0, 3) as $url) {
            $b = fetch_remote_url($url, '', 2);
            if ($b !== '') $responses[] = $b;
        }
    }

    $index = array();
    foreach ($responses as $raw) {
        $data = @json_decode($raw, true);
        if (!is_array($data) || empty($data['events']) || !is_array($data['events'])) continue;

        foreach ($data['events'] as $ev) {
            $dateStr = isset($ev['date']) ? (string)$ev['date'] : '';
            $tsSec   = $dateStr !== '' ? @strtotime($dateStr) : false;
            if (!$tsSec) continue;

            $comp = isset($ev['competitions'][0]) ? $ev['competitions'][0] : array();
            $competitors = isset($comp['competitors']) && is_array($comp['competitors']) ? $comp['competitors'] : array();
            if (count($competitors) < 2) continue;

            $homeName  = '';
            $awayName  = '';
            $homeScore = '';
            $awayScore = '';

            foreach ($competitors as $c) {
                $tName  = isset($c['team']['shortDisplayName']) ? $c['team']['shortDisplayName'] : (isset($c['team']['displayName']) ? $c['team']['displayName'] : '');
                $tName2 = isset($c['team']['displayName']) ? $c['team']['displayName'] : $tName;
                $score  = isset($c['score']) ? (string)$c['score'] : '';
                if (isset($c['homeAway']) && $c['homeAway'] === 'away') {
                    $awayName  = $tName2;
                    $awayScore = $score;
                } else {
                    $homeName  = $tName2;
                    $homeScore = $score;
                }
            }

            $stType      = isset($ev['status']['type']) ? $ev['status']['type'] : array();
            $state       = isset($stType['state']) ? strtolower((string)$stType['state']) : ''; // pre, in, post
            $shortDetail = isset($stType['shortDetail']) ? (string)$stType['shortDetail'] : '';
            $dispClock   = isset($ev['status']['displayClock']) ? (string)$ev['status']['displayClock'] : '';
            $period      = isset($ev['status']['period']) ? (int)$ev['status']['period'] : 0;

            $kHome = normalize_team_key($homeName);
            $kAway = normalize_team_key($awayName);
            if ($kHome === '' && $kAway === '') continue;

            $index[] = array(
                'k_home'       => $kHome,
                'k_away'       => $kAway,
                'start_ts_ms'  => $tsSec * 1000,
                'iso_utc'      => gmdate('Y-m-d\TH:i:s\Z', $tsSec),
                'state'        => $state,
                'short_detail' => $shortDetail,
                'clock'        => $dispClock,
                'period'       => $period,
                'home_score'   => $homeScore,
                'away_score'   => $awayScore
            );
        }
    }

    if (!empty($index)) {
        write_all_json($SPORTS_SYNC_FILES, $index);
    }
    return $index;
}

// Cruza un partido de la agenda con el índice oficial en vivo para sincronizar hora UTC exacta y minuto/periodo real
function match_event_with_live_index($home, $away, $approxTsMs, $liveIndex) {
    $kh = normalize_team_key($home);
    $ka = normalize_team_key($away);
    if ($kh === '' || strlen($kh) < 3) return null;

    foreach ($liveIndex as $item) {
        $ih = $item['k_home'];
        $ia = $item['k_away'];
        if ($ih === '' || $ia === '') continue;

        // Ventana de ±18 horas para corregir desfases de zona horaria o día
        if (abs($item['start_ts_ms'] - $approxTsMs) > (18 * 3600 * 1000)) {
            continue;
        }

        $homeMatch = ($kh === $ih || str_contains($kh, $ih) || str_contains($ih, $kh));
        $awayMatch = ($ka !== '' && ($ka === $ia || str_contains($ka, $ia) || str_contains($ia, $ka)));

        $swappedHome = ($kh === $ia || str_contains($kh, $ia) || str_contains($ia, $kh));
        $swappedAway = ($ka !== '' && ($ka === $ih || str_contains($ka, $ih) || str_contains($ih, $ka)));

        if (($homeMatch && $awayMatch) || ($swappedHome && $swappedAway)) {
            return $item;
        }
    }
    return null;
}

// Convierte la fecha y hora de la agenda a timestamp UTC exacto (con corrección inteligente de cruce de medianoche)
function compute_utc_timestamp_ms($dateStr, $timeStr, $sourceTzName) {
    $cleanDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string)$dateStr)) ? trim((string)$dateStr) : gmdate('Y-m-d');
    $cleanTime = substr(trim((string)$timeStr), 0, 8);
    if (preg_match('/^\d{2}:\d{2}$/', $cleanTime)) {
        $cleanTime .= ':00';
    } elseif (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $cleanTime)) {
        $cleanTime = '00:00:00';
    }

    try {
        $tz  = new DateTimeZone($sourceTzName);
        $dt  = new DateTime($cleanDate . ' ' . $cleanTime, $tz);
        $utc = $dt->getTimestamp();

        // Si la agenda publica la fecha en UTC pero la hora en UTC-5 (p.ej. partido a las 21:00 UTC-5 que ya es día siguiente en UTC),
        // evita que quede 24 horas en el futuro o 24 horas en el pasado.
        $now = time();
        if (($utc - $now) > (18 * 3600)) {
            $utc -= 86400;
        } elseif (($now - $utc) > (20 * 3600)) {
            $utc += 86400;
        }
        return $utc * 1000;
    } catch (Exception $e) {
        $ts = @strtotime($cleanDate . 'T' . $cleanTime . '-05:00');
        return ($ts ? $ts : time()) * 1000;
    }
}

function build_stream_ids($chName, $decodedTarget, &$eventCounter) {
    $streamParam = '';
    $srvPrefix   = '';

    $p = @parse_url($decodedTarget);
    if (!empty($p['query'])) {
        parse_str($p['query'], $q);
        if (!empty($q['stream'])) {
            $streamParam = strtolower(preg_replace('/[^a-z0-9_-]/', '', (string)$q['stream']));
        }
    }
    if (!empty($p['path'])) {
        $pathLower = strtolower($p['path']);
        if (preg_match('#/hd/([a-z0-9_-]+)\.php$#i', $pathLower, $hdm)) {
            $srvPrefix = 'hd';
            if ($hdm[1] !== 'hd' && $streamParam === '') {
                $streamParam = $hdm[1];
            }
        } elseif (preg_match('#/([0-9]+|hd)\.php$#i', $pathLower, $sm)) {
            $srvPrefix = strtolower($sm[1]);
        } elseif ($streamParam === '' && preg_match('#/([a-z0-9_-]+)\.php$#i', $pathLower, $pm)) {
            $streamParam = strtolower($pm[1]);
        }
    }

    $formattedStream = $streamParam;
    if ($formattedStream !== '' && preg_match('/^([a-z]+)([0-9]+)$/', $formattedStream, $m)) {
        $formattedStream = $m[1] . '-' . $m[2];
    }

    $raw        = (string)$chName;
    $explicitOp = '';
    $isHd       = ($srvPrefix === 'hd');
    if (preg_match('/\bOP\s*([0-9]+)\b/i', $raw, $m)) $explicitOp = 'op' . (int)$m[1];
    elseif (preg_match('/\bOPCION\s*([0-9]+)\b/i', $raw, $m)) $explicitOp = 'op' . (int)$m[1];
    if (preg_match('/(\|\s*HD\b|\bHD\b)/i', $raw)) $isHd = true;

    $parts    = explode('|', $raw);
    $base     = preg_replace('/\b(op\s*[0-9]+|opcion\s*[0-9]+|hd)\b/i', '', trim($parts[0]));
    $nameSlug = slugify_text($base);
    if ($nameSlug === '') $nameSlug = 'canal';

    if ($isHd && $explicitOp !== '') $nameSlugFull = $nameSlug . '-hd-' . $explicitOp;
    elseif ($isHd) $nameSlugFull = $nameSlug . '-hd';
    elseif ($explicitOp !== '') $nameSlugFull = $nameSlug . '-' . $explicitOp;
    else $nameSlugFull = $nameSlug;

    $basePrimary = $formattedStream !== '' ? $formattedStream : $nameSlug;
    if ($isHd || $srvPrefix === 'hd') {
        $primaryId = $basePrimary . '-hd';
    } elseif ($srvPrefix !== '' && $srvPrefix !== '1') {
        $primaryId = $basePrimary . '-op' . $srvPrefix;
    } else {
        $primaryId = $basePrimary;
    }

    if (isset($eventCounter[$primaryId])) {
        $eventCounter[$primaryId]++;
        $primaryId = $primaryId . '-op' . $eventCounter[$primaryId];
    } else {
        $eventCounter[$primaryId] = 1;
    }

    $aliases = array_unique(array_filter(array(
        $primaryId,
        $formattedStream,
        $streamParam,
        $streamParam !== '' ? ($streamParam . '-hd') : '',
        $formattedStream !== '' ? ($formattedStream . '-hd') : '',
        $nameSlugFull,
        $nameSlug,
        slugify_text($raw)
    )));

    return array('primary' => $primaryId, 'aliases' => $aliases);
}

function extract_image_path($attr) {
    $jsonStr = json_encode($attr, JSON_UNESCAPED_SLASHES);
    if (preg_match('/"url"\s*:\s*"(\/uploads\/[^"]+)"/i', $jsonStr, $m)) return $m[1];
    return '';
}

function register_short_image_id($imgPath, &$imgMap) {
    if ($imgPath === '') return '';
    $numId = (string)((abs(crc32($imgPath)) % 900) + 100);
    while (isset($imgMap[$numId]) && $imgMap[$numId] !== $imgPath) {
        $numId = (string)(((int)$numId + 1) % 900 + 100);
    }
    $imgMap[$numId] = $imgPath;
    return 'images.php?id=' . $numId;
}

function parse_match_details($rawTitle, $fallbackCountry) {
    $league    = $fallbackCountry !== '' ? $fallbackCountry : 'DEPORTES';
    $matchPart = trim((string)$rawTitle);
    if (strpos($matchPart, ':') !== false) {
        $parts = explode(':', $matchPart, 2);
        if (trim($parts[0]) !== '' && trim($parts[1]) !== '') {
            $league    = trim($parts[0]);
            $matchPart = trim($parts[1]);
        }
    }
    $home = $matchPart;
    $away = '';
    if (preg_match('/^(.+?)\s+(?:vs\.?|v\/s|v|–|-)\s+(.+)$/iu', $matchPart, $m)) {
        $home = trim($m[1]);
        $away = trim($m[2]);
    }
    return array('league' => $league, 'home' => $home, 'away' => $away);
}

$persistentMap = read_first_json($MAP_FILES);
$imgMap        = read_first_json($IMG_FILES);
$liveIndex     = get_live_sports_scoreboard_index();

$bustAgenda = $CFG['agenda_api'] . (strpos($CFG['agenda_api'], '?') === false ? '?' : '&') . '_t=' . time();
$rawBody    = fetch_remote_url($bustAgenda, $CFG['origin_hdr'], 12);

if ($rawBody !== '') {
    foreach ($BACKUP_FILES as $bf) {
        @file_put_contents($bf, $rawBody);
    }
} else {
    foreach ($BACKUP_FILES as $bf) {
        if (@file_exists($bf)) {
            $rawBody = (string)@file_get_contents($bf);
            if ($rawBody !== '') break;
        }
    }
}

$rawJson = @json_decode($rawBody, true);
$items   = is_array($rawJson) ? (isset($rawJson['data']) ? $rawJson['data'] : $rawJson) : array();

$eventsOut = array();

if (is_array($items)) {
    foreach ($items as $idx => $item) {
        if (!is_array($item)) continue;
        $attr    = isset($item['attributes']) ? $item['attributes'] : $item;
        $title   = !empty($attr['diary_description']) ? $attr['diary_description'] : ('Evento #' . ($idx + 1));
        $date    = !empty($attr['date_diary']) ? $attr['date_diary'] : gmdate('Y-m-d');
        $time    = !empty($attr['diary_hour']) ? $attr['diary_hour'] : '00:00:00';
        $country = isset($attr['country']['data']['attributes']['name']) ? $attr['country']['data']['attributes']['name'] : '';

        $imgPath   = extract_image_path($attr);
        $logoUrl   = register_short_image_id($imgPath, $imgMap);
        $details   = parse_match_details($title, $country);
        $sportInfo = detect_sport_type($details['league'], $title, $country);

        // 1. Hora UTC calculada desde la zona horaria base de la agenda
        $startTsMs = compute_utc_timestamp_ms($date, $time, $CFG['source_tz']);
        $isoUtc    = gmdate('Y-m-d\TH:i:s\Z', (int)round($startTsMs / 1000));

        // 2. Sincronización con marcador oficial estilo Google Deportes (corrige horas desincronizadas y estado real por deporte)
        $synced = match_event_with_live_index($details['home'], $details['away'], $startTsMs, $liveIndex);
        $liveState   = '';
        $liveDetail  = '';
        $liveClock   = '';
        $livePeriod  = 0;
        $homeScore   = '';
        $awayScore   = '';

        if (is_array($synced)) {
            $startTsMs  = (int)$synced['start_ts_ms'];
            $isoUtc     = $synced['iso_utc'];
            $liveState  = $synced['state']; // pre, in, post
            $liveDetail = $synced['short_detail'];
            $liveClock  = $synced['clock'];
            $livePeriod = (int)$synced['period'];
            $homeScore  = $synced['home_score'];
            $awayScore  = $synced['away_score'];
        }

        $rawEmbeds = isset($attr['embeds']['data']) && is_array($attr['embeds']['data'])
            ? $attr['embeds']['data']
            : (isset($attr['embeds']) && is_array($attr['embeds']) ? $attr['embeds'] : array());

        $channels     = array();
        $eventCounter = array();

        foreach ($rawEmbeds as $emb) {
            if (!is_array($emb)) continue;
            $eAttr     = isset($emb['attributes']) ? $emb['attributes'] : $emb;
            $chName    = !empty($eAttr['embed_name']) ? $eAttr['embed_name'] : 'Canal';
            $rawIframe = !empty($eAttr['emded_iframe']) ? $eAttr['emded_iframe'] : (!empty($eAttr['embed_iframe']) ? $eAttr['embed_iframe'] : '');
            if ($rawIframe === '') continue;

            $fullIframe = str_starts_with($rawIframe, 'http')
                ? $rawIframe
                : (str_starts_with($rawIframe, '/') ? $CFG['origin_hdr'] . $rawIframe : $CFG['embed_base'] . rawurlencode($rawIframe));

            $decodedTarget = decode_r_param($fullIframe);
            $idBundle      = build_stream_ids($chName, $decodedTarget, $eventCounter);
            $primaryId     = $idBundle['primary'];

            $entryData = array(
                'name'       => $chName,
                'target'     => $decodedTarget,
                'target_b64' => base64_encode($decodedTarget),
                'updated_at' => time()
            );

            $persistentMap[$primaryId] = $entryData;
            foreach ($idBundle['aliases'] as $al) {
                $persistentMap[$al] = $entryData;
            }

            $channels[] = array(
                'name'     => $chName,
                'id'       => $primaryId,
                'play_url' => 'play.php?id=' . $primaryId
            );
        }

        $eventsOut[] = array(
            'title'        => $title,
            'league'       => $details['league'],
            'home'         => $details['home'],
            'away'         => $details['away'],
            'sport'        => $sportInfo['sport'],
            'sport_icon'   => $sportInfo['icon'],
            'duration_min' => $sportInfo['duration_min'],
            'date'         => $date,
            'time'         => $time,
            'iso'          => $isoUtc,
            'start_ts_ms'  => $startTsMs,
            'live_state'   => $liveState,
            'live_detail'  => $liveDetail,
            'live_clock'   => $liveClock,
            'live_period'  => $livePeriod,
            'home_score'   => $homeScore,
            'away_score'   => $awayScore,
            'country'      => $country,
            'logo'         => $logoUrl,
            'channels'     => $channels
        );
    }
}

write_all_json($MAP_FILES, $persistentMap);
write_all_json($IMG_FILES, $imgMap);

echo json_encode(array(
    'ok'           => true,
    'server_ts_ms' => (int)round(microtime(true) * 1000),
    'server_utc'   => gmdate('Y-m-d\TH:i:s\Z'),
    'count'        => count($eventsOut),
    'events'       => $eventsOut
), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);