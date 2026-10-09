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

function chartsMeasurements($deviceType)
{
    $measurements = [
        'hive' => ['weight' => ['weight', 2], 'tempOut' => ['temperature', 1]],
        'scale' => ['humidityOut' => ['humidity', 1], 'vbatOut' => ['battery', 3]],
        'gateway' => ['pressureGw' => ['pressure', 1], 'vbatGw' => ['battery', 3]]
    ];
    if (!is_string($deviceType) || !isset($measurements[$deviceType])) {
        throw new InvalidArgumentException('Invalid monitoring device type.');
    }
    return $measurements[$deviceType];
}

function chartsSignature($hiveId, $deviceType, $token, $sensorId = null)
{
    $scope = $deviceType === 'hive' ? $hiveId : $deviceType . ':' . $hiveId;
    if ($sensorId !== null) {
        $scope .= ':sensor:' . $sensorId;
    }
    return hash_hmac('sha256', $scope, $token);
}

function chartsHivePoints($baseUrl, $token, $hiveId, $fromMs, $toMs, $limit, $range,
    $deviceType = 'hive', ?callable $apiGet = null, $sensorId = null)
{
    $apiGet = $apiGet ?? 'chartsApiGet';
    $attributes = chartsMeasurements($deviceType);
    $seriesNames = array_column(array_values($attributes), 0);
    $points = array_fill_keys($seriesNames, []);
    $cursor = $fromMs;
    $buckets = array_fill_keys($seriesNames, []);
    $firstPoints = array_fill_keys($seriesNames, null);
    $lastPoints = array_fill_keys($seriesNames, null);
    // Render long time ranges compactly while preserving minimum and maximum values.
    $bucketMs = in_array($range, ['5y', '1y'], true)
        ? max(1, (int)ceil(($toMs - $fromMs) / 750)) : 0;
    do {
        $rows = chartsRows($apiGet(
            $baseUrl . ($sensorId === null ? '/api/hives/' : '/api/enviro/') .
            rawurlencode((string)($sensorId ?? $hiveId)) . '/history?' .
            http_build_query([
                'from' => $cursor, 'to' => $toMs, 'limit' => $limit,
                'reverse' => 'false', 'attributes' => implode(',', array_keys($attributes))
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

function chartsSensorIdentity(array $sensor)
{
    $sensorId = $sensor['sensorId'] ?? $sensor['id'] ?? null;
    if (!is_scalar($sensorId) || !preg_match('/^[1-9][0-9]*$/D', (string)$sensorId)) {
        return null;
    }
    $address = isset($sensor['address']) && is_scalar($sensor['address'])
        ? strtoupper(trim((string)$sensor['address'])) : '';
    return [
        'key' => $address !== '' ? 'address:' . $address : 'id:' . $sensorId,
        'sensorId' => (string)$sensorId,
        'address' => $address
    ];
}

function chartsEnvironmentDevices(array $hives, array $sensorLists, ?array $gatewayList = null)
{
    $devices = [];
    $gatewayKeys = [];
    foreach ($gatewayList ?? [] as $gateway) {
        if (!is_array($gateway) || !($identity = chartsSensorIdentity($gateway))) {
            continue;
        }
        $key = 'gateway:' . $identity['key'];
        $gatewayKeys[$identity['sensorId']] = $key;
        $devices[$key] = [
            'deviceType' => 'gateway',
            // An unassigned gateway still needs a stable scope for signed detail requests.
            'hiveId' => 'sensor:' . $identity['sensorId'],
            'sensorId' => $identity['sensorId'],
            'address' => $identity['address'],
            'hiveNames' => [],
            'lastMeasurementTimestamp' => null
        ];
    }
    foreach ($hives as $hive) {
        if (!is_array($hive) || !isset($hive['id']) || !is_scalar($hive['id'])) {
            continue;
        }
        $hiveId = (string)$hive['id'];
        $name = isset($hive['name']) && is_scalar($hive['name']) ? trim((string)$hive['name']) : '';
        $name = $name !== '' ? $name : 'Hive ' . $hiveId;
        $hiveDevices = [];
        $scaleCount = 0;
        foreach ($sensorLists[$hiveId] ?? [] as $sensor) {
            if (!is_array($sensor) || !($identity = chartsSensorIdentity($sensor))) {
                continue;
            }
            $class = $sensor['sensorClass'] ?? null;
            $type = $sensor['sensorType'] ?? null;
            if ($class === 'H' && $type === 'e') {
                $key = 'scale:' . $identity['key'];
                $hiveDevices[$key] = $identity + ['deviceType' => 'scale'];
            } elseif ($class === '*' && $type === 'G') {
                $key = $gatewayKeys[$identity['sensorId']] ?? 'gateway:' . $identity['key'];
                // A successfully loaded token catalog is authoritative, including removals.
                if ($gatewayList === null || isset($devices[$key])) {
                    $hiveDevices[$key] = $identity + ['deviceType' => 'gateway'];
                }
            }
        }
        if (!array_key_exists($hiveId, $sensorLists)) {
            // A failed assignment request is different from an explicitly empty sensor list.
            $hiveDevices['scale:hive:' . $hiveId] = [
                'deviceType' => 'scale', 'sensorId' => null, 'address' => ''
            ];
        }
        foreach ($hiveDevices as $identity) {
            $scaleCount += $identity['deviceType'] === 'scale' ? 1 : 0;
        }
        foreach ($hiveDevices as $key => $identity) {
            if (!isset($devices[$key])) {
                $devices[$key] = [
                    'deviceType' => $identity['deviceType'],
                    'hiveId' => $hiveId,
                    'sensorId' => $identity['sensorId'],
                    'address' => $identity['address'],
                    'hiveNames' => [],
                    'lastMeasurementTimestamp' => null
                ];
            }
            if (!$devices[$key]['hiveNames']) {
                $devices[$key]['hiveId'] = $hiveId;
            }
            $devices[$key]['hiveNames'][$hiveId] = $name;
            // Hive-level metadata cannot identify the last reading of multiple scales.
            if ($devices[$key]['deviceType'] === 'scale' && $scaleCount === 1) {
                $timestamp = chartsTimestamp(['time' => $hive['lastMeasurementTime'] ?? null]);
                if ($timestamp !== null) {
                    $devices[$key]['lastMeasurementTimestamp'] = max(
                        $devices[$key]['lastMeasurementTimestamp'] ?? 0, (int)round($timestamp * 1000)
                    );
                }
            }
        }
    }
    $devices = array_values($devices);
    usort($devices, function ($a, $b) {
        return (($a['deviceType'] === 'gateway') <=> ($b['deviceType'] === 'gateway'))
            ?: strnatcasecmp(implode(', ', $a['hiveNames']), implode(', ', $b['hiveNames']))
            ?: strnatcasecmp($a['address'], $b['address'])
            ?: strnatcasecmp((string)$a['sensorId'], (string)$b['sensorId']);
    });
    $nameCounts = [];
    foreach ($devices as &$device) {
        $device['name'] = ($device['deviceType'] === 'scale' ? 'Scale · ' : 'Gateway · ')
            . ($device['hiveNames'] ? implode(', ', $device['hiveNames'])
                : ($device['address'] !== '' ? $device['address'] : 'Sensor ' . $device['sensorId']));
        $nameCounts[$device['name']] = ($nameCounts[$device['name']] ?? 0) + 1;
    }
    unset($device);
    foreach ($devices as &$device) {
        if ($nameCounts[$device['name']] > 1) {
            $device['name'] .= ' · ' . ($device['address'] !== ''
                ? $device['address'] : 'Sensor ' . $device['sensorId']);
        }
        unset($device['address']);
        unset($device['hiveNames']);
    }
    unset($device);
    return $devices;
}

function chartsOverview(array $query, ?callable $apiGet = null)
{
    $apiGet = $apiGet ?? 'chartsApiGet';
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
    $view = ($query['view'] ?? null) === 'environment' ? 'environment' : 'weight';
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
        $hivesQuery = $culture === null ? [] : ['culture' => $culture];
        if ($view === 'environment') {
            $hivesQuery['attributes'] = '*,lastMeasurementTime';
        }
        $hivesUrl = $baseUrl . '/api/hives' . ($hivesQuery
            ? '?' . http_build_query($hivesQuery) : '');
        $hives = chartsRows($apiGet($hivesUrl, $token));
        $seenHives = [];
        $devices = [];
        $sensorLists = [];
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
            if ($view === 'environment') {
                try {
                    $sensorLists[$hiveId] = chartsRows($apiGet(
                        $baseUrl . '/api/v3/objects/' . rawurlencode($hiveId) . '/sensors', $token
                    ));
                } catch (RuntimeException $exception) {
                    $errors[] = $name . ': Sensor assignments could not be loaded.';
                }
            } else {
                $devices[] = ['name' => $name, 'hiveId' => $hiveId, 'deviceType' => 'hive'];
            }
        }
        if ($view === 'environment') {
            $gatewayList = null;
            try {
                $gatewayList = chartsRows($apiGet($baseUrl . '/api/gateways', $token));
            } catch (RuntimeException $exception) {
                $errors[] = 'Gateway list could not be loaded; using hive assignments.';
            }
            $devices = chartsEnvironmentDevices($hives, $sensorLists, $gatewayList);
        }
        foreach ($devices as $device) {
            $hiveId = $device['hiveId'];
            $deviceType = $device['deviceType'];
            try {
                $points = chartsHivePoints($baseUrl, $token, $hiveId, $fromMs, $toMs, $limit,
                    $range, $deviceType, $apiGet, $device['sensorId'] ?? null);
                if ($deviceType === 'hive' && !$points['weight']) {
                    continue;
                }
                $chart = $device + ['signature' => chartsSignature(
                    $hiveId, $deviceType, $token, $device['sensorId'] ?? null
                )];
                foreach ($points as $series => $values) {
                    $chart[$series . 'Points'] = $values;
                    if ($values) {
                        $chart['lastMeasurementTimestamp'] = max(
                            $chart['lastMeasurementTimestamp'] ?? 0, $values[count($values) - 1]['x']
                        );
                    }
                }
                $charts[] = $chart;
            } catch (RuntimeException $exception) {
                // A faulty hive does not prevent the other charts from loading.
                $errors[] = $device['name'] . ': ' . $exception->getMessage();
            }
        }
    } catch (RuntimeException $exception) {
        $errors[] = $exception->getMessage();
    }
    if (!$charts && $errors) {
        throw new RuntimeException(implode(' ', $errors));
    }
    return [
        'view' => $view, 'range' => $range, 'fromTimestamp' => $fromMs, 'toTimestamp' => $toMs,
        'historyFromTimestamp' => $historyFromMs, 'charts' => $charts, 'errors' => $errors
    ];
}

function chartsDetails(array $query, ?callable $apiGet = null)
{
    $token = chartsToken();
    $deviceType = $query['device'] ?? 'hive';
    chartsMeasurements($deviceType);
    $sensorId = $query['sensor'] ?? null;
    if ($sensorId !== null && ($deviceType === 'hive' || !is_scalar($sensorId) ||
        !preg_match('/^[1-9][0-9]*$/D', (string)$sensorId))) {
        throw new InvalidArgumentException('Invalid monitoring sensor.');
    }
    $hiveId = isset($query['hive']) && is_scalar($query['hive']) ? (string)$query['hive'] : '';
    $signature = isset($query['signature']) && is_string($query['signature']) ? $query['signature'] : '';
    $from = filter_var($query['from'] ?? null, FILTER_VALIDATE_INT);
    $to = filter_var($query['to'] ?? null, FILTER_VALIDATE_INT);
    if ($hiveId === '' || !hash_equals(chartsSignature($hiveId, $deviceType, $token, $sensorId), $signature) ||
        $from === false || $to === false || $from < 0 || $from >= $to ||
        $to - $from > 5 * 366 * 86400000) {
        throw new InvalidArgumentException('Invalid data range.');
    }
    $limit = min(10000, max(500, (int)ceil(($to - $from) / 300000) + 100));
    return chartsHivePoints(CHARTS_BASE_URL, $token, $hiveId, $from, $to, $limit, 'raw',
        $deviceType, $apiGet, $sensorId);
}
