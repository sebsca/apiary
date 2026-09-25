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

putenv('HIVEMONITORING_API_TOKEN=');
try {
    chartsOverview([]);
    throw new LogicException('Missing credentials must fail without contacting HiveMonitoring.');
} catch (RuntimeException $exception) {
    if ($exception->getMessage() !== 'HiveMonitoring API token is missing.') {
        throw $exception;
    }
}

echo 'OK (' . count($invalid) . " invalid detail requests and missing credentials)\n";
