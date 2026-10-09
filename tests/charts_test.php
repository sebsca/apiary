<?php

require_once __DIR__ . '/../charts.php';

putenv('HIVEMONITORING_API_TOKEN=charts-test-token');
$valid = [
    'hive' => '1',
    'signature' => hash_hmac('sha256', '1', 'charts-test-token'),
    'from' => '1000',
    'to' => '2000'
];
$invalid = [
    [],
    array_diff_key($valid, ['from' => true]),
    array_diff_key($valid, ['to' => true]),
    array_merge($valid, ['hive' => '2']),
    array_merge($valid, ['signature' => 'invalid']),
    array_merge($valid, ['hive' => ['1']]),
    array_merge($valid, ['signature' => ['invalid']]),
    array_merge($valid, ['device' => ['scale']]),
    array_merge($valid, ['device' => 'invalid']),
    array_merge($valid, ['device' => 'scale']),
    array_merge($valid, ['sensor' => '11']),
    array_merge($valid, ['from' => ['1000']]),
    array_merge($valid, ['to' => ['2000']]),
    array_merge($valid, ['from' => 'invalid']),
    array_merge($valid, ['from' => '-1']),
    array_merge($valid, ['from' => '2000']),
    array_merge($valid, ['from' => '3000']),
    array_merge($valid, ['to' => (string)(1000 + 5 * 366 * 86400000 + 1)])
];
foreach ($invalid as $query) {
    try {
        chartsDetails($query);
    } catch (InvalidArgumentException $exception) {
        continue;
    }
    throw new RuntimeException('Invalid or unsigned detail requests must be rejected before contacting HiveMonitoring.');
}

function chartsAssert($condition, $message)
{
    if (!$condition) throw new RuntimeException($message);
}

$nowMs = time() * 1000;
$hives = [
    ['id' => 1, 'name' => '01', 'lastMeasurementTime' => $nowMs - 1000],
    ['id' => 2, 'name' => '02', 'lastMeasurementTime' => $nowMs - 30 * 86400000],
    ['id' => 3, 'name' => '03', 'lastMeasurementTime' => $nowMs - 1000]
];
$sensors = [
    '1' => [
        ['sensorId' => 11, 'sensorClass' => 'H', 'sensorType' => 'e', 'address' => 'scale1'],
        ['sensorId' => 21, 'sensorClass' => '*', 'sensorType' => 'G', 'address' => 'sharedGateway']
    ],
    '2' => [
        ['sensorId' => 12, 'sensorClass' => 'H', 'sensorType' => 'e', 'address' => 'scale2'],
        ['sensorId' => 21, 'sensorClass' => '*', 'sensorType' => 'G', 'address' => 'SHAREDGATEWAY']
    ],
    '3' => [
        ['sensorId' => 13, 'sensorClass' => 'H', 'sensorType' => 'e', 'address' => 'scale3'],
        ['sensorId' => 22, 'sensorClass' => '*', 'sensorType' => 'G', 'address' => 'anotherGateway']
    ]
];
$gatewayCatalog = [
    ['id' => 21, 'address' => 'sharedGateway'],
    ['id' => 22, 'address' => 'anotherGateway']
];
$historyRequests = [];
$apiGet = function ($url, $token) use ($hives, $sensors, $gatewayCatalog, $nowMs, &$historyRequests) {
    $path = parse_url($url, PHP_URL_PATH);
    if ($path === '/api/hives') return $hives;
    if ($path === '/api/gateways') return $gatewayCatalog;
    if (preg_match('#^/api/v3/objects/(\d+)/sensors$#', $path, $match)) {
        return $sensors[$match[1]];
    }
    if (preg_match('#^/api/enviro/(\d+)/history$#', $path, $match)) {
        $historyRequests[] = $path;
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        if ($match[1] === '12') return [];
        return [[
            'time' => $nowMs - 1000, 'humidityOut' => 63.24, 'vbatOut' => 4.0174,
            'pressureGw' => 1003.64, 'vbatGw' => 4.0244
        ]];
    }
    throw new RuntimeException('Unexpected upstream request: ' . $path);
};
$overview = chartsOverview(['view' => 'environment', 'range' => '1w'], $apiGet);
chartsAssert(count($overview['charts']) === 5, 'Three scales and two physical gateways must have separate charts.');
chartsAssert(count($historyRequests) === 5 && count(array_unique($historyRequests)) === 5,
    'Shared gateways must be loaded exactly once by their own sensor ID.');
$scales = array_values(array_filter($overview['charts'], function ($chart) {
    return $chart['deviceType'] === 'scale';
}));
$gateways = array_values(array_filter($overview['charts'], function ($chart) {
    return $chart['deviceType'] === 'gateway';
}));
chartsAssert($scales[1]['humidityPoints'] === [] && $scales[1]['batteryPoints'] === [],
    'A scale without recent readings must remain listed.');
chartsAssert($scales[1]['lastMeasurementTimestamp'] === $hives[1]['lastMeasurementTime'],
    'An empty scale chart must retain its actual last measurement time.');
chartsAssert(strpos($gateways[0]['name'], '01, 02') !== false,
    'A shared gateway must identify all of its associated hives.');
chartsAssert($gateways[0]['pressurePoints'] === $gateways[1]['pressurePoints'],
    'Separate gateways with identical measurements must not be merged.');
chartsAssert($scales[0]['batteryPoints'][0]['y'] === 4.017,
    'Battery measurements must preserve millivolt precision.');

$detailQuery = [
    'hive' => $scales[0]['hiveId'], 'sensor' => $scales[0]['sensorId'], 'device' => 'scale',
    'signature' => $scales[0]['signature'], 'from' => $nowMs - 2000, 'to' => $nowMs
];
$details = chartsDetails($detailQuery, $apiGet);
chartsAssert($details['battery'] === $scales[0]['batteryPoints'],
    'Zoom details must load the same scale sensor with the same voltage precision.');
foreach ([['device' => 'gateway'], ['sensor' => '12'], ['sensor' => ['11']], ['hive' => '2']] as $change) {
    $requestCount = count($historyRequests);
    try {
        chartsDetails(array_merge($detailQuery, $change), $apiGet);
        throw new LogicException('Changing a signed device, sensor, or hive must be rejected.');
    } catch (InvalidArgumentException $exception) {
        chartsAssert(count($historyRequests) === $requestCount,
            'Tampered sensor detail requests must fail before contacting the upstream API.');
    }
}

$changingHives = [];
$changingSensors = [];
$changingGateways = [];
$changingRequests = [];
$failGatewayList = false;
$failAssignments = false;
$failHistorySensor = null;
$changingApi = function ($url) use (&$changingHives, &$changingSensors, &$changingGateways,
    &$changingRequests, &$failGatewayList, &$failAssignments, &$failHistorySensor, $nowMs) {
    $path = parse_url($url, PHP_URL_PATH);
    $changingRequests[] = $path;
    if ($path === '/api/hives') return $changingHives;
    if ($path === '/api/gateways') {
        if ($failGatewayList) throw new RuntimeException('Gateway catalog unavailable.');
        return $changingGateways;
    }
    if (preg_match('#^/api/v3/objects/(\d+)/sensors$#', $path, $match)) {
        if ($failAssignments) throw new RuntimeException('Assignments unavailable.');
        return $changingSensors[$match[1]];
    }
    if (preg_match('#^/api/(enviro|hives)/(\d+)/history$#', $path, $match)) {
        if ($match[2] === $failHistorySensor) throw new RuntimeException('Sensor unavailable.');
        return [[
            'time' => $nowMs - 1000, 'humidityOut' => 65, 'vbatOut' => 4.1,
            'pressureGw' => 1005, 'vbatGw' => 4.2
        ]];
    }
    throw new RuntimeException('Unexpected changing-catalog request: ' . $path);
};
foreach ([0, 1, 12, 2, 0] as $hiveCount) {
    $changingHives = $changingSensors = $changingGateways = $changingRequests = [];
    for ($id = 1; $id <= $hiveCount; $id++) {
        $changingHives[] = ['id' => $id, 'name' => 'Hive ' . $id,
            'lastMeasurementTime' => $nowMs + 10000];
        $gatewayId = 200 + (int)floor(($id - 1) / 3);
        $changingSensors[$id] = [
            ['sensorId' => 100 + $id * 2, 'sensorClass' => 'H', 'sensorType' => 'e'],
            ['sensorId' => 101 + $id * 2, 'sensorClass' => 'H', 'sensorType' => 'e'],
            ['sensorId' => $gatewayId, 'sensorClass' => '*', 'sensorType' => 'G',
                'address' => 'gateway' . $gatewayId]
        ];
        $changingGateways[$gatewayId] = ['id' => $gatewayId, 'address' => 'gateway' . $gatewayId];
    }
    if ($hiveCount) $changingGateways[299] = ['id' => 299, 'address' => 'unassigned'];
    $changing = chartsOverview(['view' => 'environment'], $changingApi);
    $expected = $hiveCount * 2 + count($changingGateways);
    chartsAssert(count($changing['charts']) === $expected && $changing['errors'] === [],
        'Charts must reflect a growing, shrinking or empty token catalog without a fixed device limit.');
    chartsAssert(count(array_unique(array_column($changing['charts'], 'name'))) === $expected,
        'Multiple scales on one hive must retain distinct chart titles.');
    $histories = array_values(array_filter($changingRequests, function ($path) {
        return substr($path, -8) === '/history';
    }));
    chartsAssert(count($histories) === $expected && count(array_unique($histories)) === $expected,
        'Every current physical device must be fetched once, including shared and unassigned gateways.');
    foreach ($changing['charts'] as $chart) {
        chartsAssert($chart['lastMeasurementTimestamp'] === $nowMs - 1000,
            'Multiple scales must use their own reading times instead of ambiguous hive metadata.');
    }
}

// A gateway remains discoverable and zoomable even when the token has no hives.
$changingGateways = [['id' => 299]];
$standalone = chartsOverview(['view' => 'environment'], $changingApi);
chartsAssert(count($standalone['charts']) === 1 && $standalone['charts'][0]['sensorId'] === '299',
    'Gateways without a hive assignment must have their own chart.');
$standaloneChart = $standalone['charts'][0];
$standaloneDetails = chartsDetails([
    'hive' => $standaloneChart['hiveId'], 'sensor' => $standaloneChart['sensorId'],
    'device' => 'gateway', 'signature' => $standaloneChart['signature'],
    'from' => $nowMs - 2000, 'to' => $nowMs
], $changingApi);
chartsAssert($standaloneDetails['pressure'] === $standaloneChart['pressurePoints'],
    'An unassigned gateway must retain signed sensor-specific zoom details.');

$changingHives = [['id' => 1, 'name' => '01']];
$changingSensors = ['1' => [
    ['sensorId' => 201, 'sensorClass' => '*', 'sensorType' => 'G']
]];
$changingGateways = [['id' => 201, 'address' => 'GATEWAY201'], ['id' => 299]];
$gatewayOnly = chartsOverview(['view' => 'environment'], $changingApi);
chartsAssert(count($gatewayOnly['charts']) === 2 &&
    array_column($gatewayOnly['charts'], 'deviceType') === ['gateway', 'gateway'],
    'Removing the last scale must not leave an invented empty scale chart.');
chartsAssert(in_array('Gateway · 01', array_column($gatewayOnly['charts'], 'name'), true),
    'A gateway assignment without an address must match the token catalog by sensor ID.');
$changingGateways = [];
$removed = chartsOverview(['view' => 'environment'], $changingApi);
chartsAssert($removed['charts'] === [],
    'A removed gateway must disappear even if a hive retains an old gateway assignment.');
$changingSensors = ['1' => []];
$emptyHive = chartsOverview(['view' => 'environment'], $changingApi);
chartsAssert($emptyHive['charts'] === [],
    'An explicitly empty sensor list must not invent scales or gateways.');

$changingSensors = ['1' => $sensors['1']];
$failGatewayList = true;
$catalogFallback = chartsOverview(['view' => 'environment'], $changingApi);
chartsAssert(count($catalogFallback['charts']) === 2 && count($catalogFallback['errors']) === 1,
    'A catalog failure must use available hive assignments and report the incomplete gateway list.');
$failGatewayList = false;
$failAssignments = true;
$changingGateways = $gatewayCatalog;
$assignmentFallback = chartsOverview(['view' => 'environment'], $changingApi);
chartsAssert(count($assignmentFallback['charts']) === 3 && count($assignmentFallback['errors']) === 1,
    'A failed assignment request must retain available gateways and a reported hive-level scale fallback.');
$failAssignments = false;
$failHistorySensor = '11';
$partial = chartsOverview(['view' => 'environment'], $changingApi);
chartsAssert(count($partial['charts']) === 2 && count($partial['errors']) === 1,
    'An unavailable sensor must not prevent the other device charts from loading.');

$start = 1700000000000;
$pages = [
    [['time' => $start, 'humidityOut' => 60, 'vbatOut' => 4.1],
     ['time' => $start + 1, 'humidityOut' => 40, 'vbatOut' => 3.7]],
    [['time' => $start + 2, 'humidityOut' => 80, 'vbatOut' => 4.2],
     ['time' => $start + 3, 'humidityOut' => 65, 'vbatOut' => 'invalid']],
    [['time' => $start + 4, 'humidityOut' => 61, 'vbatOut' => 4.05]]
];
$cursors = [];
$pageApi = function ($url, $token) use (&$pages, &$cursors) {
    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    $cursors[] = (int)$query['from'];
    return array_shift($pages);
};
$points = chartsHivePoints(CHARTS_BASE_URL, 'test', '1', $start, $start + 1000000,
    2, '1y', 'scale', $pageApi);
chartsAssert($cursors === [$start, $start + 2, $start + 4],
    'History pagination must progress past each completed page without losing readings.');
chartsAssert(array_column($points['battery'], 'y') === [4.1, 3.7, 4.2, 4.05],
    'Long-range aggregation must retain first, last, minimum and maximum voltage, and skip invalid values.');
chartsAssert(array_column($points['humidity'], 'y') === [60.0, 40.0, 80.0, 61.0],
    'Humidity aggregation must retain extrema independently from battery measurements.');

$legacy = chartsHivePoints(CHARTS_BASE_URL, 'test', '1', $start, $start + 1000, 10, 'raw',
    'hive', function () use ($start) {
        return [['time' => $start, 'weight' => 41.512, 'tempOut' => 16.54]];
    });
chartsAssert($legacy === [
    'weight' => [['x' => $start, 'y' => 41.51]],
    'temperature' => [['x' => $start, 'y' => 16.5]]
], 'The existing weight and temperature charts must retain their response and precision.');

putenv('HIVEMONITORING_API_TOKEN=');
try {
    chartsOverview([]);
    throw new LogicException('Missing credentials must fail without contacting HiveMonitoring.');
} catch (RuntimeException $exception) {
    if ($exception->getMessage() !== 'HiveMonitoring API token is missing.') {
        throw $exception;
    }
}

echo 'OK (' . count($invalid) . " invalid detail requests; changing device counts, multiple scales, shared/unassigned gateways, removals, partial failures, signatures, zoom, pagination, extrema, legacy data and missing credentials)\n";
