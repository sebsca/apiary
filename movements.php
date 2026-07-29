<?php

function load_movement_rows(PDO $pdo, string $fromDate): array {
  $sql = "SELECT
            v.`ID`,
            v.`Hive_ID`,
            COALESCE(h.`Hive_nr`, v.`Hive_ID`) AS Hive_nr,
            h.`inactive`,
            v.`Datum`,
            NULLIF(TRIM(v.`Standort`), '') AS Standort
          FROM Visits v
          JOIN Hives h ON h.`ID` = v.`Hive_ID`
          WHERE h.`inactive` = 0
            AND (
              (
                v.`Datum` < :from_date_before
                AND v.`ID` = (
                  SELECT v2.`ID`
                  FROM Visits v2
                  WHERE v2.`Hive_ID` = v.`Hive_ID`
                    AND v2.`Datum` < :from_date_lookup
                  ORDER BY v2.`Datum` DESC, v2.`ID` DESC
                  LIMIT 1
                )
              )
              OR (
                v.`Datum` >= :from_date
                AND v.`Standort` IS NOT NULL
                AND TRIM(v.`Standort`) <> ''
              )
            )
          ORDER BY v.`Hive_ID` ASC, v.`Datum` ASC, v.`ID` ASC";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([
    'from_date_before' => $fromDate,
    'from_date_lookup' => $fromDate,
    'from_date' => $fromDate
  ]);
  return $stmt->fetchAll();
}

function sankey_node_key(int $column, string $name): string {
  return $column . "\0" . $name;
}

function build_sankey_graph(array $rows, string $fromDate): array {
  usort($rows, static function (array $a, array $b): int {
    return [(int)$a['Hive_ID'], (string)$a['Datum'], (int)($a['ID'] ?? 0)]
      <=> [(int)$b['Hive_ID'], (string)$b['Datum'], (int)($b['ID'] ?? 0)];
  });

  $hives = [];
  foreach ($rows as $row) {
    if ((int)($row['inactive'] ?? 0) !== 0) {
      continue;
    }
    $hiveId = (int)$row['Hive_ID'];
    if (!isset($hives[$hiveId])) {
      $hives[$hiveId] = [
        'id' => $hiveId,
        'nr' => (string)$row['Hive_nr'],
        'start' => null,
        'days' => []
      ];
    }

    $location = trim((string)($row['Standort'] ?? ''));
    $location = $location === '' ? null : $location;
    if ((string)$row['Datum'] < $fromDate) {
      $hives[$hiveId]['start'] = $location;
      continue;
    }
    if ($location !== null) {
      // Visits only store a date, not a time. The highest visit ID is therefore
      // the deterministic end-of-day position when several visits share a date.
      $hives[$hiveId]['days'][(string)$row['Datum']] = $location;
    }
  }

  $movements = [];
  $currentPositions = [];
  $periodStarts = [];
  $eventDates = [];

  foreach ($hives as $hive) {
    $lastLocation = $hive['start'];
    $lastEventDate = null;
    $days = $hive['days'];

    if ($lastLocation === null && count($days) > 0) {
      $firstDate = array_key_first($days);
      $lastLocation = $days[$firstDate];
      $lastEventDate = $firstDate;
      $eventDates[$firstDate] = true;
      $periodStarts[] = [
        'hive' => $hive['nr'],
        'hive_id' => $hive['id'],
        'name' => $lastLocation,
        'date' => $firstDate
      ];
      unset($days[$firstDate]);
    }

    foreach ($days as $date => $location) {
      if ($location === $lastLocation) {
        continue;
      }
      $movements[] = [
        'hive' => $hive['nr'],
        'hive_id' => $hive['id'],
        'source_name' => $lastLocation,
        'target_name' => $location,
        'date' => $date,
        'source_date' => $lastEventDate
      ];
      $eventDates[$date] = true;
      $lastLocation = $location;
      $lastEventDate = $date;
    }

    if ($lastLocation !== null) {
      $currentPositions[] = [
        'hive' => $hive['nr'],
        'hive_id' => $hive['id'],
        'source_name' => $lastLocation,
        'target_name' => $lastLocation,
        'source_date' => $lastEventDate
      ];
    }
  }

  $dates = array_keys($eventDates);
  sort($dates);
  $currentDate = count($dates) > 0 ? $dates[count($dates) - 1] : null;
  $columns = ['Start'];
  $dateColumns = [];
  foreach ($dates as $date) {
    $dateColumns[$date] = count($columns);
    $columns[] = $date;
  }

  if ($currentDate !== null) {
    foreach ($currentPositions as &$position) {
      $position['date'] = $currentDate;
    }
    unset($position);
  } else {
    $currentPositions = [];
  }

  $periodStartCounts = [];
  foreach ($periodStarts as $periodStart) {
    $column = $dateColumns[$periodStart['date']];
    $key = sankey_node_key($column, $periodStart['name']);
    if (!isset($periodStartCounts[$key])) {
      $periodStartCounts[$key] = [
        'count' => 0,
        'column' => $column,
        'name' => $periodStart['name'],
        'hives' => [],
        'hive_ids' => []
      ];
    }
    $periodStartCounts[$key]['count']++;
    $periodStartCounts[$key]['hives'][$periodStart['hive']] = true;
    $periodStartCounts[$key]['hive_ids'][(string)$periodStart['hive_id']] = true;
  }

  $nodeMap = [];
  $nodes = [];
  $linkMap = [];
  $ensureNode = static function (int $column, string $name) use (&$nodeMap, &$nodes, $columns): int {
    $key = sankey_node_key($column, $name);
    if (!isset($nodeMap[$key])) {
      $nodeMap[$key] = count($nodes);
      $nodes[] = ['name' => $name, 'column' => $column, 'date' => $columns[$column]];
    }
    return $nodeMap[$key];
  };

  foreach (array_merge($movements, $currentPositions) as $movement) {
    $sourceColumn = $movement['source_date'] ? ($dateColumns[$movement['source_date']] ?? 0) : 0;
    $targetColumn = $dateColumns[$movement['date']];
    if ($targetColumn <= $sourceColumn) {
      continue;
    }

    $source = $ensureNode($sourceColumn, $movement['source_name']);
    $target = $ensureNode($targetColumn, $movement['target_name']);
    $linkKey = $source . '>' . $target;
    if (!isset($linkMap[$linkKey])) {
      $linkMap[$linkKey] = [
        'source' => $source,
        'target' => $target,
        'value' => 0,
        'date' => $movement['date'],
        'hives' => [],
        'hive_ids' => []
      ];
    }
    $linkMap[$linkKey]['value']++;
    $linkMap[$linkKey]['hives'][$movement['hive']] = true;
    $linkMap[$linkKey]['hive_ids'][(string)$movement['hive_id']] = true;
  }

  foreach ($periodStartCounts as $start) {
    $ensureNode($start['column'], $start['name']);
  }

  $incomingValues = [];
  $outgoingValues = [];
  $nodeHives = [];
  $nodeHiveIds = [];
  foreach ($linkMap as $link) {
    $outgoingValues[$link['source']] = ($outgoingValues[$link['source']] ?? 0) + $link['value'];
    $incomingValues[$link['target']] = ($incomingValues[$link['target']] ?? 0) + $link['value'];
    foreach ([$link['source'], $link['target']] as $nodeIndex) {
      foreach ($link['hives'] as $hiveNumber => $_) {
        $nodeHives[$nodeIndex][$hiveNumber] = true;
      }
      foreach ($link['hive_ids'] as $hiveId => $_) {
        $nodeHiveIds[$nodeIndex][$hiveId] = true;
      }
    }
  }
  foreach ($periodStartCounts as $key => $start) {
    $nodeIndex = $nodeMap[$key];
    $nodes[$nodeIndex]['fixedValue'] = max(
      ($incomingValues[$nodeIndex] ?? 0) + $start['count'],
      $outgoingValues[$nodeIndex] ?? 0
    );
    foreach ($start['hives'] as $hiveNumber => $_) {
      $nodeHives[$nodeIndex][$hiveNumber] = true;
    }
    foreach ($start['hive_ids'] as $hiveId => $_) {
      $nodeHiveIds[$nodeIndex][$hiveId] = true;
    }
  }

  foreach ($nodeHives as $nodeIndex => $hiveSet) {
    $hiveNumbers = array_keys($hiveSet);
    $hiveIds = array_keys($nodeHiveIds[$nodeIndex] ?? []);
    sort($hiveNumbers, SORT_NATURAL);
    sort($hiveIds, SORT_NUMERIC);
    $nodes[$nodeIndex]['hives'] = implode(', ', $hiveNumbers);
    $nodes[$nodeIndex]['hive_ids'] = implode(', ', $hiveIds);
  }

  $links = [];
  foreach ($linkMap as $link) {
    $hiveNumbers = array_keys($link['hives']);
    $hiveIds = array_keys($link['hive_ids']);
    sort($hiveNumbers, SORT_NATURAL);
    sort($hiveIds, SORT_NUMERIC);
    $links[] = [
      'source' => $link['source'],
      'target' => $link['target'],
      'value' => $link['value'],
      'date' => $link['date'],
      'hives' => implode(', ', $hiveNumbers),
      'hive_ids' => implode(', ', $hiveIds)
    ];
  }

  return ['nodes' => $nodes, 'links' => $links, 'columns' => $columns];
}
