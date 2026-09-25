<?php
// HiveMonitoring data for the authenticated JSON API.
const CHARTS_BASE_URL = 'https://main.beehivemonitoring.com';

function chartsToken()
{
    $token = trim((string)(getenv('HIVEMONITORING_API_TOKEN') ?: ''));
    if ($token === '') {
        throw new RuntimeException('HiveMonitoring API token is missing.');
    }
    return $token;
}

function chartsApiGet($url, $token)
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is missing.');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-Auth-Token: ' . $token]
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('HiveMonitoring is currently unavailable.');
    }
    if ($httpCode !== 200) {
        throw new RuntimeException('HiveMonitoring API error: HTTP ' . $httpCode);
    }

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        throw new RuntimeException('HiveMonitoring returned invalid JSON data.');
    }
    return $data;
}

function chartsRows($data)
{
    foreach (['data', 'items', 'history'] as $field) {
        if (isset($data[$field]) && is_array($data[$field])) {
            return $data[$field];
        }
    }
    return $data;
}

function chartsBrowserCulture()
{
    $languages = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
    $culture = trim(explode(';', explode(',', (string)$languages)[0])[0]);
    return preg_match('/^[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})*$/D', $culture)
        ? $culture : null;
}

function chartsTimestamp($row)
{
    $value = $row['time'] ?? null;
    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        return null;
    }
    $unix = is_numeric($value) ? (float)$value : strtotime($value);
    if ($unix !== false && $unix > 20000000000) {
        $unix /= 1000;
    }
    return $unix !== false && is_finite((float)$unix) && $unix > 0 ? $unix : null;
}

function chartsHivePoints($baseUrl, $token, $hiveId, $fromMs, $toMs, $limit, $range)
{
    $points = ['weight' => [], 'temperature' => []];
    $cursor = $fromMs;
    $buckets = ['weight' => [], 'temperature' => []];
    $firstPoints = ['weight' => null, 'temperature' => null];
    $lastPoints = ['weight' => null, 'temperature' => null];
    $attributes = ['weight' => ['weight', 2], 'tempOut' => ['temperature', 1]];
    // Render long time ranges compactly while preserving minimum and maximum values.
    $bucketMs = in_array($range, ['5y', '1y'], true)
        ? max(1, (int)ceil(($toMs - $fromMs) / 750)) : 0;
    do {
        $rows = chartsRows(chartsApiGet(
            $baseUrl . '/api/hives/' . rawurlencode((string)$hiveId) . '/history?' .
            http_build_query([
                'from' => $cursor, 'to' => $toMs, 'limit' => $limit,
                'reverse' => 'false', 'attributes' => 'weight,tempOut'
            ]),
            $token
        ));
        $latestMs = null;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $unix = chartsTimestamp($row);
            if ($unix === null) {
                continue;
            }
            $timestamp = (int)round($unix * 1000);
            $latestMs = max($latestMs ?? $timestamp, $timestamp);
            if ($timestamp < $fromMs || $timestamp > $toMs) {
                continue;
            }

            foreach ($attributes as $attribute => [$series, $decimals]) {
                if (!isset($row[$attribute]) || !is_numeric($row[$attribute]) ||
                    !is_finite((float)$row[$attribute])) {
                    continue;
                }
                $point = ['x' => $timestamp, 'y' => round((float)$row[$attribute], $decimals)];
                if ($firstPoints[$series] === null || $timestamp < $firstPoints[$series]['x']) {
                    $firstPoints[$series] = $point;
                }
                if ($lastPoints[$series] === null || $timestamp > $lastPoints[$series]['x']) {
                    $lastPoints[$series] = $point;
                }
                if ($bucketMs === 0) {
                    $points[$series][$timestamp] = $point;
                } else {
                    $bucket = (int)floor(($timestamp - $fromMs) / $bucketMs);
                    if (!isset($buckets[$series][$bucket])) {
                        $buckets[$series][$bucket] = ['min' => $point, 'max' => $point];
                    } else {
                        if ($point['y'] < $buckets[$series][$bucket]['min']['y']) {
                            $buckets[$series][$bucket]['min'] = $point;
                        }
                        if ($point['y'] > $buckets[$series][$bucket]['max']['y']) {
                            $buckets[$series][$bucket]['max'] = $point;
                        }
                    }
                }
            }
        }
        $hasMore = count($rows) >= $limit;
        unset($rows);
        if ($hasMore && ($latestMs === null || $latestMs < $cursor)) {
            throw new RuntimeException('The historical data could not be loaded completely.');
        }
        $cursor = ($latestMs ?? $toMs) + 1;
    } while ($hasMore && $cursor <= $toMs);

    foreach (array_keys($points) as $series) {
        foreach ($buckets[$series] as $bucket) {
            foreach ($bucket as $point) {
                $points[$series][$point['x']] = $point;
            }
        }
        if ($firstPoints[$series] !== null) {
            $points[$series][$firstPoints[$series]['x']] = $firstPoints[$series];
            $points[$series][$lastPoints[$series]['x']] = $lastPoints[$series];
        }
        usort($points[$series], function ($a, $b) {
            return $a['x'] <=> $b['x'];
        });
    }
    return $points;
}

function chartsOverview(array $query)
{
    $token = chartsToken();
    $baseUrl = CHARTS_BASE_URL;
    $ranges = [
        '5y' => 'P5Y',
        '1y' => 'P1Y',
        '1m' => 'P1M',
        '1w' => 'P7D',
        '1d' => 'P1D'
    ];
    $range = isset($query['range']) && is_string($query['range']) && isset($ranges[$query['range']])
        ? $query['range'] : '1w';
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $fromMs = $now->sub(new DateInterval($ranges[$range]))->getTimestamp() * 1000;
    $toMs = $now->getTimestamp() * 1000;
    $historyFromMs = $now->sub(new DateInterval('P5Y'))->getTimestamp() * 1000;
    // Five-minute measurements plus a buffer; full pages are fetched continuously.
    $limit = min(10000, max(500, (int)ceil(($toMs - $fromMs) / 300000) + 100));

    $charts = [];
    $errors = [];
    try {
        $culture = chartsBrowserCulture();
        $hivesUrl = $baseUrl . '/api/hives' . ($culture === null
            ? '' : '?' . http_build_query(['culture' => $culture]));
        $hives = chartsRows(chartsApiGet($hivesUrl, $token));
        $seenHives = [];
        foreach ($hives as $hive) {
            if (!is_array($hive) || !isset($hive['id']) || !is_scalar($hive['id'])) {
                continue;
            }
            $hiveId = (string)$hive['id'];
            if (isset($seenHives[$hiveId])) {
                continue;
            }
            $seenHives[$hiveId] = true;
            $name = isset($hive['name']) && is_scalar($hive['name']) ? trim((string)$hive['name']) : '';
            $name = $name !== '' ? $name : 'Hive ' . $hiveId;
            try {
                $points = chartsHivePoints($baseUrl, $token, $hiveId, $fromMs, $toMs, $limit, $range);
                if ($points['weight']) {
                    $charts[] = [
                        'name' => $name,
                        'hiveId' => $hiveId,
                        'signature' => hash_hmac('sha256', $hiveId, $token),
                        'weightPoints' => $points['weight'],
                        'temperaturePoints' => $points['temperature']
                    ];
                }
            } catch (RuntimeException $exception) {
                // A faulty hive does not prevent the other charts from loading.
                $errors[] = $name . ': ' . $exception->getMessage();
            }
        }
    } catch (RuntimeException $exception) {
        $errors[] = $exception->getMessage();
    }
    if (!$charts && $errors) {
        throw new RuntimeException(implode(' ', $errors));
    }
    return [
        'range' => $range, 'fromTimestamp' => $fromMs, 'toTimestamp' => $toMs,
        'historyFromTimestamp' => $historyFromMs, 'charts' => $charts, 'errors' => $errors
    ];
}

function chartsDetails(array $query)
{
    $token = chartsToken();
    $hiveId = isset($query['hive']) && is_scalar($query['hive']) ? (string)$query['hive'] : '';
    $signature = isset($query['signature']) && is_string($query['signature']) ? $query['signature'] : '';
    $from = filter_var($query['from'] ?? null, FILTER_VALIDATE_INT);
    $to = filter_var($query['to'] ?? null, FILTER_VALIDATE_INT);
    if ($hiveId === '' || !hash_equals(hash_hmac('sha256', $hiveId, $token), $signature) ||
        $from === false || $to === false || $from < 0 || $from >= $to ||
        $to - $from > 5 * 366 * 86400000) {
        throw new InvalidArgumentException('Invalid data range.');
    }
    $limit = min(10000, max(500, (int)ceil(($to - $from) / 300000) + 100));
    return chartsHivePoints(CHARTS_BASE_URL, $token, $hiveId, $from, $to, $limit, 'raw');
}
