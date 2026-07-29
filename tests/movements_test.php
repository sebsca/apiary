<?php

require_once __DIR__ . '/../movements.php';

$tests = 0;

function assert_same($expected, $actual, string $message): void {
  global $tests;
  $tests++;
  if ($expected !== $actual) {
    throw new RuntimeException(
      $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true)
    );
  }
}

function movement_row(
  int $id,
  int $hiveId,
  string $hiveNumber,
  string $date,
  ?string $location,
  bool $inactive=false
): array {
  return [
    'ID' => $id,
    'Hive_ID' => $hiveId,
    'Hive_nr' => $hiveNumber,
    'inactive' => $inactive ? 1 : 0,
    'Datum' => $date,
    'Standort' => $location
  ];
}

$graph = build_sankey_graph([
  movement_row(1, 10, '10', '2025-12-20', 'A'),
  movement_row(2, 10, '10', '2026-01-05', 'A'),
  movement_row(3, 10, '10', '2026-02-01', 'B')
], '2026-01-01');
assert_same(['Start', '2026-02-01'], $graph['columns'], 'A movement creates one dated column.');
assert_same(1, count($graph['links']), 'A single location change creates one link.');
assert_same('A', $graph['nodes'][$graph['links'][0]['source']]['name'], 'The previous-period location is the source.');
assert_same('B', $graph['nodes'][$graph['links'][0]['target']]['name'], 'The changed location is the target.');
assert_same('10', $graph['links'][0]['hive_ids'], 'Links expose the underlying hive IDs.');
assert_same('10', $graph['nodes'][$graph['links'][0]['source']]['hives'], 'Source nodes expose hive numbers.');
assert_same('10', $graph['nodes'][$graph['links'][0]['target']]['hives'], 'Target nodes expose hive numbers.');

$graph = build_sankey_graph([
  movement_row(4, 20, '20', '2026-01-31', 'GRB')
], '2026-01-01');
assert_same(['Start', '2026-01-31'], $graph['columns'], 'A new hive starts on its first visit date.');
assert_same(0, count($graph['links']), 'A first visit alone is not a movement.');
assert_same('GRB', $graph['nodes'][0]['name'], 'A new hive starts at its first known location.');
assert_same(1, $graph['nodes'][0]['fixedValue'], 'A standalone first-visit node remains visible.');
assert_same('20', $graph['nodes'][0]['hive_ids'], 'A first-visit node exposes its hive IDs.');
assert_same(false, in_array('—', array_column($graph['nodes'], 'name'), true), 'Unknown placeholder locations are never rendered.');

$graph = build_sankey_graph([
  movement_row(5, 30, '30', '2026-01-31', 'GRB', true)
], '2026-01-01');
assert_same([], $graph['nodes'], 'Inactive hives are excluded from the graph.');
assert_same([], $graph['links'], 'Inactive hives create no links.');

$graph = build_sankey_graph([
  movement_row(6, 40, '40', '2026-03-01', 'NEW'),
  movement_row(7, 40, '40', '2026-03-01', 'Wald')
], '2026-01-01');
assert_same('Wald', $graph['nodes'][0]['name'], 'The highest visit ID defines the end-of-day location.');
assert_same(false, in_array('NEW', array_column($graph['nodes'], 'name'), true), 'An earlier same-day location is not rendered.');

echo "OK ({$tests} assertions)\n";
