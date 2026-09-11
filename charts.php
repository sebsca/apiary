<?php
require_once __DIR__ . '/api-bootstrap.php';

apiary_start_session();
$pdo = get_pdo();
$authenticatedUser = apiary_refresh_authenticated_user($pdo);
if ($authenticatedUser === null) {
    http_response_code(401);
    header('Cache-Control: private, no-store');
    if (isset($_GET['data'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Unauthorized']);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="de"><meta charset="utf-8"><title>Anmeldung erforderlich</title>' .
            '<body><p role="alert">Bitte melde dich an, um die Diagramme anzuzeigen.</p></body></html>';
    }
    exit;
}
session_write_close();

$token = trim((string)(getenv('HIVEMONITORING_API_TOKEN') ?: ''));
if ($token === '') {
    throw new RuntimeException('HiveMonitoring API token fehlt.');
}
$baseUrl = 'https://main.beehivemonitoring.com';
$ranges = [
    '5y' => ['label' => '5 Jahre', 'interval' => 'P5Y', 'title' => 'letzte 5 Jahre'],
    '1y' => ['label' => '1 Jahr', 'interval' => 'P1Y', 'title' => 'letztes Jahr'],
    '1m' => ['label' => '1 Monat', 'interval' => 'P1M', 'title' => 'letzter Monat'],
    '1w' => ['label' => '1 Woche', 'interval' => 'P7D', 'title' => 'letzte Woche'],
    '1d' => ['label' => '1 Tag', 'interval' => 'P1D', 'title' => 'letzter Tag']
];
$range = isset($_GET['range']) && is_string($_GET['range']) && isset($ranges[$_GET['range']])
    ? $_GET['range'] : '1m';
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$fromMs = $now->sub(new DateInterval($ranges[$range]['interval']))->getTimestamp() * 1000;
$toMs = $now->getTimestamp() * 1000;
$historyFromMs = $now->sub(new DateInterval('P5Y'))->getTimestamp() * 1000;
// Fünf-Minuten-Messungen plus Reserve; volle Seiten werden weiter abgefragt.
$limit = min(10000, max(500, (int)ceil(($toMs - $fromMs) / 300000) + 100));

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store');

function chartsApiGet($url, $token)
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Die PHP-Erweiterung cURL fehlt.');
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
        throw new RuntimeException('HiveMonitoring ist zurzeit nicht erreichbar.');
    }
    if ($httpCode !== 200) {
        throw new RuntimeException('HiveMonitoring API Fehler: HTTP ' . $httpCode);
    }

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        throw new RuntimeException('HiveMonitoring hat keine gültigen JSON-Daten geliefert.');
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
    // Lange Zeiträume kompakt zeichnen; Minima und Maxima bleiben erhalten.
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
            throw new RuntimeException('Die historischen Daten konnten nicht vollständig geladen werden.');
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

// Liefert ausschließlich unverdichtete Rohdaten für einen bereits autorisierten Chart.
if (isset($_GET['data'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    try {
        $hiveId = isset($_GET['hive']) && is_scalar($_GET['hive']) ? (string)$_GET['hive'] : '';
        $signature = isset($_GET['signature']) && is_string($_GET['signature']) ? $_GET['signature'] : '';
        $requestedFrom = filter_input(INPUT_GET, 'from', FILTER_VALIDATE_INT);
        $requestedTo = filter_input(INPUT_GET, 'to', FILTER_VALIDATE_INT);
        if ($hiveId === '' || !hash_equals(hash_hmac('sha256', $hiveId, $token), $signature) ||
            $requestedFrom === false || $requestedTo === false || $requestedFrom >= $requestedTo ||
            $requestedFrom < 0 || $requestedTo - $requestedFrom > 5 * 366 * 86400000) {
            throw new InvalidArgumentException('Ungültiger Datenbereich.');
        }
        $detailLimit = min(10000, max(
            500, (int)ceil(($requestedTo - $requestedFrom) / 300000) + 100
        ));
        echo json_encode(
            chartsHivePoints(
                $baseUrl, $token, $hiveId, $requestedFrom, $requestedTo, $detailLimit, 'raw'
            ),
            JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
    } catch (InvalidArgumentException $exception) {
        http_response_code(400);
        echo json_encode(['error' => $exception->getMessage()]);
    } catch (RuntimeException $exception) {
        http_response_code(502);
        echo json_encode(['error' => $exception->getMessage()]);
    }
    exit;
}

$charts = [];
$errors = [];
try {
    $hives = chartsRows(chartsApiGet($baseUrl . '/api/hives?culture=de-DE', $token));
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
        $name = $name !== '' ? $name : 'Bienenstock ' . $hiveId;
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
            // Ein fehlerhafter Bienenstock verhindert nicht die anderen Diagramme.
            $errors[] = $name . ': ' . $exception->getMessage();
        }
    }
} catch (RuntimeException $exception) {
    $errors[] = $exception->getMessage();
}
if (!$charts && $errors) {
    http_response_code(502);
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gewicht und Außentemperatur der Bienenstöcke – ApexCharts</title>
    <style>
        html, body { margin: 0; padding: 0; background: transparent; }
        body { font-family: system-ui, sans-serif; color: #27313b; }
        #chart-container { width: 100%; min-width: 0; min-height: 400px; }
        h1 { margin: 0; padding: 12px 12px 0; font-size: 16px; font-weight: 600; }
        .hive-chart { box-sizing: border-box; width: 100%; min-width: 0; margin: 20px 0; padding: 12px 0 4px; border: 1px solid #cbd5dc; border-radius: 8px; }
        .hive-chart h2 { display: flex; flex-wrap: wrap; align-items: baseline; gap: 4px 12px; margin: 0; padding: 0 12px; font-size: 16px; font-weight: 600; overflow-wrap: anywhere; }
        .hive-latest { color: #53616f; font-size: 14px; font-weight: 500; }
        .weight-chart { width: 100%; min-height: 365px; }
        .ranges { display: flex; flex-wrap: wrap; gap: 8px; margin: 12px; }
        .ranges button { padding: 9px 15px; min-height: 40px; border: 1px solid #cbd5dc; border-radius: 6px; background: #fff; color: #27313b; font: inherit; cursor: pointer; }
        .ranges button:hover { background: #eef5f8; }
        .ranges button[aria-pressed="true"] { background: #277b9b; border-color: #277b9b; color: #fff; }
        .ranges button:focus-visible { outline: 3px solid #277b9b; outline-offset: 3px; }
        .notice { margin: 12px; padding: 14px; border-radius: 6px; background: #f2f4f6; line-height: 1.5; }
        .error { background: #fff0ee; color: #8b251b; }
        .chart-tooltip { min-width: 230px; padding: 10px 12px; background: #fff; box-shadow: 0 2px 8px rgba(39, 49, 59, .18); }
        .chart-tooltip-date { margin-bottom: 7px; color: #53616f; font-weight: 600; }
        .chart-tooltip-row { display: grid; grid-template-columns: 10px 1fr auto; align-items: center; gap: 7px; margin-top: 4px; }
        .chart-tooltip-dot { width: 8px; height: 8px; border-radius: 50%; }
        .chart-tooltip-value { font-weight: 600; }
        .chart-tooltip-measured { grid-column: 2 / 4; color: #71808d; font-size: 11px; }
        .apexcharts-gridlines-horizontal line { stroke: #edf0f2; stroke-width: 1; }
        .apexcharts-yaxis-annotations line { stroke-width: 1.35; }
        [hidden] { display: none !important; }
    </style>
</head>
<body>
<main id="chart-container">
    <h1 id="chart-title">Gewicht und Außentemperatur – <?= $ranges[$range]['title'] ?></h1>
    <form class="ranges" method="get" aria-label="Zeitraum auswählen">
        <?php foreach ($ranges as $key => $option): ?>
            <button type="submit" name="range" value="<?= $key ?>" aria-pressed="<?= $range === $key ? 'true' : 'false' ?>"><?= $option['label'] ?></button>
        <?php endforeach; ?>
    </form>
    <?php foreach ($errors as $error): ?>
        <p class="notice error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
    <?php endforeach; ?>
    <?php if (!$charts && !$errors): ?>
        <p class="notice" role="status">Keine Gewichtsmessungen im gewählten Zeitraum vorhanden.</p>
    <?php endif; ?>
    <?php foreach ($charts as $index => $hiveChart): ?>
        <?php
        $latestWeight = $hiveChart['weightPoints'][array_key_last($hiveChart['weightPoints'])]['y'];
        $latestTemperature = $hiveChart['temperaturePoints']
            ? $hiveChart['temperaturePoints'][array_key_last($hiveChart['temperaturePoints'])]['y']
            : null;
        ?>
        <section class="hive-chart" aria-labelledby="hive-title-<?= $index ?>">
            <h2 id="hive-title-<?= $index ?>">
                <span><?= htmlspecialchars($hiveChart['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <span class="hive-latest">
                    Gewicht <?= number_format($latestWeight, 2, ',', '.') ?> kg<?php if ($latestTemperature !== null): ?>
                        · Außen <?= number_format($latestTemperature, 1, ',', '.') ?> °C<?php endif; ?>
                </span>
            </h2>
            <div id="gewichtChart-<?= $index ?>" class="weight-chart" role="region" aria-labelledby="hive-title-<?= $index ?>"></div>
            <p id="chart-error-<?= $index ?>" class="notice error" role="alert" hidden></p>
        </section>
    <?php endforeach; ?>
    <?php if ($charts): ?>
        <noscript><p class="notice">Bitte JavaScript aktivieren, um die Diagramme anzuzeigen.</p></noscript>
    <?php endif; ?>
</main>
<?php if ($charts): ?>
<!-- Feste Version; wie die Vorlage über CDN geladen. Keine Projektabhängigkeiten. -->
<script src="https://cdn.jsdelivr.net/npm/apexcharts@5.3.6/dist/apexcharts.min.js"></script>
<script>
(() => {
    const hiveCharts = <?= json_encode($charts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) ?>;
    const weightFormat = new Intl.NumberFormat('de-AT', {
        minimumFractionDigits: 2, maximumFractionDigits: 2
    });
    const temperatureFormat = new Intl.NumberFormat('de-AT', {
        minimumFractionDigits: 1, maximumFractionDigits: 1
    });
    const axisFormat = new Intl.NumberFormat('de-AT', {
        maximumFractionDigits: 0
    });
    const weightAxisFormat = new Intl.NumberFormat('de-AT', {
        maximumFractionDigits: 0
    });
    const range = <?= json_encode($range) ?>;
    const dayMs = 24 * 60 * 60 * 1000;
    const xAxisFormats = {
        hours: new Intl.DateTimeFormat('de-AT', {
            day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit'
        }),
        days: new Intl.DateTimeFormat('de-AT', { day: '2-digit', month: '2-digit' }),
        months: new Intl.DateTimeFormat('de-AT', { month: '2-digit', year: 'numeric' }),
        years: new Intl.DateTimeFormat('de-AT', { year: 'numeric' })
    };
    const dateTimeFormat = new Intl.DateTimeFormat('de-AT', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit'
    });
    const fromTimestamp = <?= $fromMs ?>;
    const toTimestamp = <?= $toMs ?>;
    const historyFromTimestamp = <?= $historyFromMs ?>;
    function xAxisLabels(span) {
        const formatter = span <= 2 * dayMs ? xAxisFormats.hours
            : span <= 60 * dayMs ? xAxisFormats.days
                : span <= 2 * 365 * dayMs ? xAxisFormats.months : xAxisFormats.years;
        return {
            datetimeUTC: false,
            hideOverlappingLabels: true,
            formatter: (value, timestamp) =>
                formatter.format(new Date(timestamp ?? Number(value)))
        };
    }
    // Bei langen Zeiträumen entsprechen zwei Verdichtungsintervalle der zulässigen Nähe.
    const tooltipTolerance = Math.max(30 * 60 * 1000, (toTimestamp - fromTimestamp) / 2000);
    function nearestPoint(points, timestamp) {
        if (!points.length) {
            return null;
        }
        let low = 0;
        let high = points.length - 1;
        while (low < high) {
            const middle = Math.floor((low + high) / 2);
            if (points[middle].x < timestamp) {
                low = middle + 1;
            } else {
                high = middle;
            }
        }
        const candidates = [points[low], points[low - 1]].filter(Boolean);
        const nearest = candidates.reduce((best, point) =>
            !best || Math.abs(point.x - timestamp) < Math.abs(best.x - timestamp) ? point : best, null);
        return nearest && Math.abs(nearest.x - timestamp) <= tooltipTolerance ? nearest : null;
    }
    function tooltipRow(label, point, color, formatter, timestamp) {
        if (!point) {
            return '';
        }
        const measured = point.x === timestamp ? ''
            : `<span class="chart-tooltip-measured">Messzeit: ${dateTimeFormat.format(new Date(point.x))}</span>`;
        return `<span class="chart-tooltip-row">
            <span class="chart-tooltip-dot" style="background:${color}"></span>
            <span>${label}</span><span class="chart-tooltip-value">${formatter(point.y)}</span>
            ${measured}
        </span>`;
    }
    function visibleRange(points, minX, maxX) {
        let min = Infinity;
        let max = -Infinity;
        let low = 0;
        let high = points.length;
        while (low < high) {
            const middle = Math.floor((low + high) / 2);
            if (points[middle].x < minX) low = middle + 1;
            else high = middle;
        }
        for (let index = low; index < points.length && points[index].x <= maxX; index += 1) {
            min = Math.min(min, points[index].y);
            max = Math.max(max, points[index].y);
        }
        if (!Number.isFinite(min) || !Number.isFinite(max)) {
            return null;
        }
        const axisMin = Math.floor(min);
        const axisMax = Math.ceil(max);
        return {
            min: axisMin,
            max: axisMax > axisMin ? axisMax : axisMin + 1
        };
    }
    function mergeRanges(ranges) {
        return ranges.slice().sort((a, b) => a[0] - b[0]).reduce((merged, range) => {
            const last = merged.at(-1);
            if (!last || range[0] > last[1] + 1) merged.push(range.slice());
            else last[1] = Math.max(last[1], range[1]);
            return merged;
        }, []);
    }
    function missingRanges(minX, maxX, ranges) {
        const missing = [];
        let cursor = minX;
        for (const [from, to] of mergeRanges(ranges)) {
            if (to < cursor || from > maxX) continue;
            if (from > cursor) missing.push([cursor, Math.min(from - 1, maxX)]);
            cursor = Math.max(cursor, to + 1);
            if (cursor > maxX) break;
        }
        if (cursor <= maxX) missing.push([cursor, maxX]);
        return missing;
    }
    function compactPoints(points, minX, maxX) {
        if (points.length <= 1502) return points;
        const bucketMs = Math.max(1, Math.ceil((maxX - minX) / 750));
        const buckets = new Map();
        points.forEach(point => {
            const key = Math.floor((point.x - minX) / bucketMs);
            const bucket = buckets.get(key);
            if (!bucket) buckets.set(key, { min: point, max: point });
            else {
                if (point.y < bucket.min.y) bucket.min = point;
                if (point.y > bucket.max.y) bucket.max = point;
            }
        });
        const result = new Map([
            [points[0].x, points[0]],
            [points[points.length - 1].x, points[points.length - 1]]
        ]);
        buckets.forEach(bucket => {
            result.set(bucket.min.x, bucket.min);
            result.set(bucket.max.x, bucket.max);
        });
        return [...result.values()].sort((a, b) => a.x - b.x);
    }
    function showError(index) {
        document.getElementById(`gewichtChart-${index}`).hidden = true;
        const notice = document.getElementById(`chart-error-${index}`);
        notice.textContent = 'Das Diagramm konnte nicht geladen werden. Bitte die Verbindung prüfen und die Seite neu laden.';
        notice.hidden = false;
    }
    if (typeof window.ApexCharts !== 'function') {
        hiveCharts.forEach((hive, index) => showError(index));
        return;
    }
    hiveCharts.forEach((hive, index) => {
        const overviewWeightPoints = hive.weightPoints;
        const overviewTemperaturePoints = hive.temperaturePoints;
        let weightPoints = overviewWeightPoints;
        let temperaturePoints = overviewTemperaturePoints;
        const hasTemperature = temperaturePoints.length > 0;
        const series = [{ name: 'Gewicht', data: weightPoints }];
        let scaleTimer;
        let loadTimer;
        let requestVersion = 0;
        let requestController;
        let updateGuardTimer;
        let programmaticUpdate = false;
        let rawRanges = [];
        const rawPoints = { weight: new Map(), temperature: new Map() };
        const overviewResolution = range === '1y' || range === '5y'
            ? Math.ceil((toTimestamp - fromTimestamp) / 750) : 0;
        if (overviewResolution === 0) {
            overviewWeightPoints.forEach(point => rawPoints.weight.set(point.x, point));
            overviewTemperaturePoints.forEach(point => rawPoints.temperature.set(point.x, point));
            rawRanges = [[fromTimestamp, toTimestamp]];
        }
        const fullWeightRange = visibleRange(
            overviewWeightPoints, fromTimestamp, toTimestamp
        );
        const fullTemperatureRange = visibleRange(
            overviewTemperaturePoints, fromTimestamp, toTimestamp
        );
        function weightScale(minX, maxX) {
            const weightRange = visibleRange(weightPoints, minX, maxX)
                ?? fullWeightRange;
            const weightMin = Math.floor(weightRange.min);
            const weightMax = Math.max(weightMin + 1, Math.ceil(weightRange.max));
            const weightSpan = weightMax - weightMin;
            return {
                min: weightMin,
                max: weightMax,
                tickAmount: weightSpan,
                forceNiceScale: false
            };
        }
        function buildYAxes(minX = fromTimestamp, maxX = toTimestamp) {
            const weightAxis = weightScale(minX, maxX);
            const temperatureRange = hasTemperature
                ? visibleRange(temperaturePoints, minX, maxX) ?? fullTemperatureRange
                : null;
            const axes = [{
                seriesName: 'Gewicht',
                title: { text: 'Gewicht (kg)' },
                decimalsInFloat: 0,
                labels: {
                    formatter: value => {
                        const step = weightAxis.max - weightAxis.min > 5 ? 5 : 1;
                        const isGridValue = Math.abs(value / step - Math.round(value / step)) < 1e-6;
                        return isGridValue ? weightAxisFormat.format(value) + ' kg' : '';
                    }
                },
                ...weightAxis
            }];
            if (temperatureRange) {
                const temperatureMin = Math.floor(temperatureRange.min / 5) * 5;
                const temperatureMax = Math.max(
                    temperatureMin + 5, Math.ceil(temperatureRange.max / 5) * 5
                );
                axes.push({
                    seriesName: 'Außentemperatur', opposite: true,
                    title: { text: 'Außentemperatur (°C)' },
                    decimalsInFloat: 0,
                    labels: { formatter: value => axisFormat.format(value) + ' °C' },
                    min: temperatureMin,
                    max: temperatureMax,
                    tickAmount: (temperatureMax - temperatureMin) / 5
                });
            }
            return axes;
        }
        function buildWeightGridLines(minX = fromTimestamp, maxX = toTimestamp) {
            const scale = weightScale(minX, maxX);
            const lines = [];
            const step = scale.max - scale.min > 5 ? 5 : 1;
            for (let value = Math.ceil(scale.min / step) * step; value <= scale.max; value += step) {
                lines.push({
                    y: value,
                    borderColor: value % 10 === 0 ? '#c4cbd1' : '#edf0f2',
                    strokeDashArray: 0
                });
            }
            return { yaxis: lines };
        }
        function updateVisibleAxes(chartContext, minX, maxX) {
            if (!Number.isFinite(minX) || !Number.isFinite(maxX)) {
                return;
            }
            clearTimeout(scaleTimer);
            scaleTimer = setTimeout(() => {
                guardChartUpdate();
                chartContext.updateOptions({
                    xaxis: {
                        min: minX,
                        max: maxX,
                        labels: xAxisLabels(maxX - minX)
                    },
                    yaxis: buildYAxes(minX, maxX),
                    annotations: buildWeightGridLines(minX, maxX)
                }, false, false, false).catch(() => showError(index));
            }, 60);
        }
        function renderedPoints(overview, cache, minX, maxX) {
            const cached = [...cache.values()].sort((a, b) => a.x - b.x);
            if (!cached.length) return overview;
            const visible = cached.filter(point => point.x >= minX && point.x <= maxX);
            const outside = cached.filter(point => point.x < minX || point.x > maxX);
            const points = new Map();
            overview.forEach(point => {
                if (!rawRanges.some(([from, to]) => point.x >= from && point.x <= to)) {
                    points.set(point.x, point);
                }
            });
            compactPoints(outside, historyFromTimestamp, toTimestamp)
                .concat(compactPoints(visible, minX, maxX))
                .forEach(point => points.set(point.x, point));
            return [...points.values()].sort((a, b) => a.x - b.x);
        }
        function detailUrl(from, to) {
            const url = new URL(window.location.href);
            url.search = '';
            url.searchParams.set('data', '1');
            url.searchParams.set('hive', hive.hiveId);
            url.searchParams.set('signature', hive.signature);
            url.searchParams.set('from', String(from));
            url.searchParams.set('to', String(to));
            return url;
        }
        function detailNotice(message = '') {
            const notice = document.getElementById(`chart-error-${index}`);
            notice.textContent = message;
            notice.hidden = !message;
        }
        function guardChartUpdate() {
            clearTimeout(updateGuardTimer);
            programmaticUpdate = true;
            updateGuardTimer = setTimeout(() => { programmaticUpdate = false; }, 150);
        }
        function applyRawDetails(chartContext, minX, maxX) {
            weightPoints = renderedPoints(overviewWeightPoints, rawPoints.weight, minX, maxX);
            temperaturePoints = renderedPoints(
                overviewTemperaturePoints, rawPoints.temperature, minX, maxX
            );
            const updatedSeries = [{ name: 'Gewicht', data: weightPoints }];
            if (hasTemperature) {
                updatedSeries.push({ name: 'Außentemperatur', data: temperaturePoints });
            }
            clearTimeout(scaleTimer);
            guardChartUpdate();
            chartContext.updateOptions({
                series: updatedSeries,
                xaxis: {
                    min: minX,
                    max: maxX,
                    labels: xAxisLabels(maxX - minX)
                },
                yaxis: buildYAxes(minX, maxX),
                annotations: buildWeightGridLines(minX, maxX)
            }, false, false, false).catch(() => showError(index));
        }
        function historyRange(minX, maxX) {
            const maximumSpan = toTimestamp - historyFromTimestamp;
            const span = Math.min(maximumSpan, Math.max(1, maxX - minX));
            let from = minX;
            let to = from + span;
            if (to > toTimestamp) {
                to = toTimestamp;
                from = to - span;
            }
            if (from < historyFromTimestamp) {
                from = historyFromTimestamp;
                to = from + span;
            }
            return { from, to };
        }
        async function loadRawDetails(chartContext, minX, maxX, version) {
            const cacheStep = 5 * 60 * 1000;
            const visible = historyRange(minX, maxX);
            const visibleFrom = visible.from;
            const visibleTo = visible.to;
            const requestFrom = Math.max(
                historyFromTimestamp, Math.floor(visibleFrom / cacheStep) * cacheStep
            );
            const requestTo = Math.min(
                toTimestamp, Math.ceil(visibleTo / cacheStep) * cacheStep
            );
            if (visibleFrom >= visibleTo || version !== requestVersion) return;
            const desiredResolution = Math.max(1, (visibleTo - visibleFrom) / 750);
            const overviewSufficient = overviewResolution === 0 ||
                overviewResolution <= desiredResolution * 1.5;
            const availableRanges = overviewSufficient
                ? rawRanges.concat([[fromTimestamp, toTimestamp]]) : rawRanges;
            const gaps = missingRanges(requestFrom, requestTo, availableRanges);
            if (!gaps.length) {
                if (!missingRanges(visibleFrom, visibleTo, rawRanges).length) {
                    applyRawDetails(chartContext, visibleFrom, visibleTo);
                }
                return;
            }
            requestController = new AbortController();
            try {
                const results = await Promise.all(gaps.map(async ([from, to]) => {
                    const response = await fetch(detailUrl(from, to), {
                        headers: { Accept: 'application/json' }, signal: requestController.signal
                    });
                    const data = await response.json();
                    if (!response.ok || !Array.isArray(data.weight) || !Array.isArray(data.temperature)) {
                        throw new Error(data.error || `HTTP ${response.status}`);
                    }
                    return { range: [from, to], data };
                }));
                if (version !== requestVersion) return;
                results.forEach(result => {
                    result.data.weight.forEach(point => rawPoints.weight.set(point.x, point));
                    result.data.temperature.forEach(point => rawPoints.temperature.set(point.x, point));
                    rawRanges.push(result.range);
                });
                rawRanges = mergeRanges(rawRanges);
                detailNotice();
                applyRawDetails(chartContext, visibleFrom, visibleTo);
            } catch (error) {
                if (error.name !== 'AbortError' && version === requestVersion) {
                    detailNotice('Detaildaten konnten nicht nachgeladen werden; die Übersicht bleibt sichtbar.');
                }
            }
        }
        function handleVisibleRange(chartContext, minX, maxX) {
            if (programmaticUpdate || !Number.isFinite(minX) || !Number.isFinite(maxX)) return;
            const visible = historyRange(minX, maxX);
            updateVisibleAxes(chartContext, visible.from, visible.to);
            clearTimeout(loadTimer);
            requestController?.abort();
            const version = ++requestVersion;
            const desiredResolution = Math.max(1, (visible.to - visible.from) / 750);
            const overviewSufficient = overviewResolution === 0 ||
                overviewResolution <= desiredResolution * 1.5;
            const availableRanges = overviewSufficient
                ? rawRanges.concat([[fromTimestamp, toTimestamp]]) : rawRanges;
            const gaps = missingRanges(visible.from, visible.to, availableRanges);
            if (!gaps.length) {
                if (!missingRanges(visible.from, visible.to, rawRanges).length) {
                    loadTimer = setTimeout(() => loadRawDetails(
                        chartContext, visible.from, visible.to, version
                    ), 100);
                }
            } else {
                loadTimer = setTimeout(() => loadRawDetails(
                    chartContext, visible.from, visible.to, version
                ), 250);
            }
        }
        function resetDetails(chartContext) {
            clearTimeout(loadTimer);
            requestController?.abort();
            requestController = null;
            requestVersion += 1;
            weightPoints = overviewWeightPoints;
            temperaturePoints = overviewTemperaturePoints;
            const overviewSeries = [{ name: 'Gewicht', data: weightPoints }];
            if (hasTemperature) {
                overviewSeries.push({ name: 'Außentemperatur', data: temperaturePoints });
            }
            clearTimeout(scaleTimer);
            guardChartUpdate();
            chartContext.updateOptions({
                series: overviewSeries,
                xaxis: {
                    min: fromTimestamp,
                    max: toTimestamp,
                    labels: xAxisLabels(toTimestamp - fromTimestamp)
                },
                yaxis: buildYAxes(fromTimestamp, toTimestamp),
                annotations: buildWeightGridLines(fromTimestamp, toTimestamp)
            }, false, false, false).catch(() => showError(index));
        }
        if (hasTemperature) {
            series.push({ name: 'Außentemperatur', data: temperaturePoints });
        }
        try {
            const chart = new ApexCharts(document.getElementById(`gewichtChart-${index}`), {
                chart: {
                    type: 'line', height: 365, background: 'transparent',
                    fontFamily: 'system-ui, sans-serif', foreColor: '#53616f',
                    animations: { enabled: false },
                    zoom: {
                        enabled: true, type: 'x', autoScaleYaxis: false,
                        allowMouseWheelZoom: false
                    },
                    events: {
                        zoomed: (chartContext, { xaxis }) =>
                            handleVisibleRange(chartContext, xaxis.min, xaxis.max),
                        scrolled: (chartContext, { xaxis }) =>
                            handleVisibleRange(chartContext, xaxis.min, xaxis.max),
                        beforeResetZoom: chartContext => {
                            resetDetails(chartContext);
                            return { xaxis: { min: fromTimestamp, max: toTimestamp } };
                        }
                    },
                    toolbar: {
                        show: true,
                        tools: { download: false, selection: false, zoom: true, zoomin: true, zoomout: true, pan: true, reset: true },
                        autoSelected: 'zoom'
                    }
                },
                series,
                annotations: buildWeightGridLines(),
                colors: hasTemperature ? ['#277b9b', '#e67e22'] : ['#277b9b'],
                stroke: {
                    curve: 'straight',
                    width: hasTemperature ? [2, 1.5] : 2,
                    dashArray: hasTemperature ? [0, 4] : 0
                },
                markers: {
                    size: hasTemperature
                        ? [weightPoints.length === 1 ? 4 : 0, temperaturePoints.length === 1 ? 4 : 0]
                        : weightPoints.length === 1 ? 4 : 0,
                    hover: { size: 4 }
                },
                dataLabels: { enabled: false },
                legend: { show: hasTemperature, position: 'top' },
                grid: {
                    borderColor: '#e5e9ed',
                    xaxis: { lines: { show: false } },
                    yaxis: { lines: { show: false } }
                },
                xaxis: {
                    type: 'datetime',
                    min: fromTimestamp,
                    max: toTimestamp,
                    labels: xAxisLabels(toTimestamp - fromTimestamp),
                    tooltip: { enabled: false }
                },
                yaxis: buildYAxes(),
                tooltip: {
                    shared: false, intersect: false,
                    custom: ({ seriesIndex, dataPointIndex, w }) => {
                        const timestamp = w.globals.seriesX[seriesIndex]?.[dataPointIndex];
                        if (!Number.isFinite(timestamp)) {
                            return '';
                        }
                        const weight = nearestPoint(weightPoints, timestamp);
                        const temperature = nearestPoint(temperaturePoints, timestamp);
                        return `<div class="chart-tooltip">
                            <div class="chart-tooltip-date">${dateTimeFormat.format(new Date(timestamp))}</div>
                            ${tooltipRow('Gewicht', weight, '#277b9b', value => weightFormat.format(value) + ' kg', timestamp)}
                            ${tooltipRow('Außentemperatur', temperature, '#e67e22', value => temperatureFormat.format(value) + ' °C', timestamp)}
                        </div>`;
                    }
                }
            });
            chart.render().catch(() => showError(index));
        } catch (error) {
            showError(index);
        }
    });
})();
</script>
<?php endif; ?>
</body>
</html>
