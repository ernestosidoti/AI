<?php
/**
 * Geocode proxy — ricerca indirizzi via OpenStreetMap Nominatim
 * - Cache 24h per risultati positivi
 * - Fallback progressivo se query specifica non trova nulla
 */
define('AILAB', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/auth.php';

header('Content-Type: application/json');
aiSecurityHeaders();

if (!aiIsAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Non autenticato']);
    exit;
}

$q = trim($_GET['q'] ?? '');
$onlyCity = ($_GET['type'] ?? '') === 'city';
if (mb_strlen($q) < 2) {
    echo json_encode(['success' => true, 'results' => []]);
    exit;
}

// Cache
$cacheDir = __DIR__ . '/../downloads/_geocache';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
$cacheKey = md5(strtolower($q) . ($onlyCity ? '|city' : ''));
$cacheFile = $cacheDir . '/' . $cacheKey . '.json';

if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
    $cached = json_decode(file_get_contents($cacheFile), true);
    // Usa cache solo se NON vuota (permetti retry su fallimenti precedenti)
    if (!empty($cached['results'])) {
        echo file_get_contents($cacheFile);
        exit;
    }
}

/**
 * Chiama Nominatim con fallback strategies
 */
function nominatimSearch(string $query): array
{
    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'q' => $query,
        'format' => 'json',
        'addressdetails' => 1,
        'countrycodes' => 'it',
        'limit' => 7,
        'accept-language' => 'it',
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Listetelemarketing-AILab/1.0 (info@listetelemarketing.eu)',
            'Accept: application/json',
        ],
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$resp) return [];
    return json_decode($resp, true) ?: [];
}

/**
 * Genera varianti di fallback progressive
 */
function generateFallbacks(string $q): array
{
    $variants = [$q]; // originale

    // Rimuovi numero civico (sequenza di cifre)
    $noDigits = trim(preg_replace('/\b\d+[a-z]?\b/i', '', $q));
    $noDigits = preg_replace('/\s+/', ' ', $noDigits);
    if ($noDigits !== $q && $noDigits !== '') {
        $variants[] = $noDigits;
    }

    // Prendi solo ultima parte (probabile comune) — da 2 a 4 parole finali
    $words = preg_split('/\s+/', trim($q));
    $n = count($words);
    if ($n >= 3) {
        $last4 = implode(' ', array_slice($words, -4));
        $last3 = implode(' ', array_slice($words, -3));
        $last2 = implode(' ', array_slice($words, -2));
        if (!in_array($last4, $variants, true)) $variants[] = $last4;
        if (!in_array($last3, $variants, true)) $variants[] = $last3;
        if (!in_array($last2, $variants, true)) $variants[] = $last2;
    }

    return array_values(array_unique($variants));
}

$allResults = [];
$seenDisplay = [];

if ($onlyCity) {
    // Ricerca solo comune — usa structured query + featuretype=city
    $ch = curl_init('https://nominatim.openstreetmap.org/search?' . http_build_query([
        'city' => $q,
        'format' => 'json',
        'addressdetails' => 1,
        'countrycodes' => 'it',
        'limit' => 10,
        'accept-language' => 'it',
        'featuretype' => 'city',
    ]));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Listetelemarketing-AILab/1.0 (info@listetelemarketing.eu)',
            'Accept: application/json',
        ],
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $raw = json_decode($resp, true) ?: [];

    foreach ($raw as $item) {
        $type = $item['addresstype'] ?? $item['type'] ?? '';
        // Solo tipologie di comune
        if (!in_array($type, ['city','town','village','municipality','hamlet','administrative'], true)) continue;
        $dn = $item['display_name'] ?? '';
        if (isset($seenDisplay[$dn])) continue;
        $seenDisplay[$dn] = true;
        $allResults[] = $item;
    }
} else {
    foreach (generateFallbacks($q) as $variant) {
        $raw = nominatimSearch($variant);
        foreach ($raw as $item) {
            $dn = $item['display_name'] ?? '';
            if (isset($seenDisplay[$dn])) continue;
            $seenDisplay[$dn] = true;
            $allResults[] = $item;
        }
        if (count($allResults) >= 7) break;
        if (count($allResults) < 3) usleep(1100000);
    }
}

// Normalizza output
$results = [];
foreach ($allResults as $item) {
    $a = $item['address'] ?? [];
    $comune = $a['village'] ?? $a['town'] ?? $a['municipality'] ?? $a['city'] ?? $a['hamlet'] ?? $a['county'] ?? '';
    $provSigla = '';
    if (!empty($a['ISO3166-2-lvl6'])) {
        $parts = explode('-', $a['ISO3166-2-lvl6']);
        if (count($parts) === 2) $provSigla = strtoupper($parts[1]);
    }

    $results[] = [
        'display' => $item['display_name'] ?? '',
        'indirizzo' => $a['road'] ?? '',
        'civico' => $a['house_number'] ?? '',
        'comune' => $comune,
        'provincia' => $provSigla,
        'cap' => $a['postcode'] ?? '',
        'stato' => $a['country'] ?? 'Italia',
        'lat' => $item['lat'] ?? null,
        'lon' => $item['lon'] ?? null,
        'type' => $item['addresstype'] ?? ($item['type'] ?? ''),
    ];
}

$out = json_encode(['success' => true, 'results' => $results]);
// Cache solo se non vuoto
if (!empty($results)) @file_put_contents($cacheFile, $out);
echo $out;
