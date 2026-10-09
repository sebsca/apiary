import ApexCharts from 'https://cdn.jsdelivr.net/npm/apexcharts@5.3.6/dist/apexcharts.esm.js';
import { monitoringSeries, formatMonitoringValue } from './monitoring-series.js';

export function renderMonitoringCharts(container, data, { apiGet, signal }) {
    if (signal.aborted) return;
    const { charts: hiveCharts, range, fromTimestamp, toTimestamp, historyFromTimestamp } = data;
    const styles = getComputedStyle(container);
    const theme = {
        fontFamily: styles.fontFamily,
        text: styles.getPropertyValue('--color-text-secondary').trim(),
        border: styles.getPropertyValue('--color-border').trim(),
        grid: styles.getPropertyValue('--color-surface-strong').trim(),
        weight: styles.getPropertyValue('--color-accent').trim(),
        temperature: styles.getPropertyValue('--color-warning').trim(),
        humidity: styles.getPropertyValue('--color-info').trim(),
        pressure: styles.getPropertyValue('--color-accent').trim(),
        battery: styles.getPropertyValue('--color-warning').trim()
    };
    const axisFormat = new Intl.NumberFormat(undefined, {
        maximumFractionDigits: 0
    });
    const dayMs = 24 * 60 * 60 * 1000;
    const xAxisFormats = {
        hours: new Intl.DateTimeFormat(undefined, {
            day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit'
        }),
        days: new Intl.DateTimeFormat(undefined, { day: '2-digit', month: '2-digit' }),
        months: new Intl.DateTimeFormat(undefined, { month: '2-digit', year: 'numeric' }),
        years: new Intl.DateTimeFormat(undefined, { year: 'numeric' })
    };
    const dateTimeFormat = new Intl.DateTimeFormat(undefined, {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit'
    });
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
    // For long time ranges, two aggregation intervals define the permitted proximity.
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
            : `<span class="chart-tooltip-measured">Measured at: ${dateTimeFormat.format(new Date(point.x))}</span>`;
        return `<span class="chart-tooltip-row">
            <span class="chart-tooltip-dot chart-tooltip-${color}"></span>
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
        return { min, max };
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
        if (signal.aborted) return;
        container.querySelector(`#monitoring-chart-${index}`).hidden = true;
        const notice = container.querySelector(`#chart-error-${index}`);
        notice.dataset.state = 'error';
        notice.setAttribute('role', 'alert');
        notice.textContent = 'The chart could not be loaded. Please check your connection and reload the page.';
        notice.hidden = false;
    }
    hiveCharts.forEach((hive, index) => {
        const measurements = monitoringSeries(hive).filter(series => series.points.length);
        if (!measurements.length) return;
        const [primary, secondary] = measurements;
        const overviewPrimaryPoints = primary.points;
        const overviewSecondaryPoints = secondary?.points ?? [];
        let primaryPoints = overviewPrimaryPoints;
        let secondaryPoints = overviewSecondaryPoints;
        const hasSecondary = secondaryPoints.length > 0;
        const series = [{ name: primary.label, data: primaryPoints }];
        let scaleTimer;
        let loadTimer;
        let requestVersion = 0;
        let requestController;
        let updateGuardTimer;
        let programmaticUpdate = false;
        let rawRanges = [];
        const rawPoints = Object.fromEntries(measurements.map(series => [series.key, new Map()]));
        const overviewResolution = range === '1y' || range === '5y'
            ? Math.ceil((toTimestamp - fromTimestamp) / 750) : 0;
        if (overviewResolution === 0) {
            overviewPrimaryPoints.forEach(point => rawPoints[primary.key].set(point.x, point));
            overviewSecondaryPoints.forEach(point => rawPoints[secondary.key].set(point.x, point));
            rawRanges = [[fromTimestamp, toTimestamp]];
        }
        const fullPrimaryRange = visibleRange(
            overviewPrimaryPoints, fromTimestamp, toTimestamp
        );
        const fullSecondaryRange = visibleRange(
            overviewSecondaryPoints, fromTimestamp, toTimestamp
        );
        function primaryScale(minX, maxX) {
            const primaryRange = visibleRange(primaryPoints, minX, maxX)
                ?? fullPrimaryRange;
            if (primary.key !== 'weight') return measurementScale(primary, primaryRange);
            const primaryMin = Math.floor(primaryRange.min);
            const primaryMax = Math.max(primaryMin + 1, Math.ceil(primaryRange.max));
            const primarySpan = primaryMax - primaryMin;
            return {
                min: primaryMin,
                max: primaryMax,
                tickAmount: primarySpan,
                forceNiceScale: false
            };
        }
        function measurementScale(measurement, values) {
            const step = measurement.step;
            let min = Math.floor(values.min / step + 1e-9) * step;
            let max = Math.max(min + step, Math.ceil(values.max / step - 1e-9) * step);
            if (measurement.key === 'humidity') {
                min = Math.max(0, min);
                max = Math.min(100, Math.max(min + step, max));
                if (min >= max) min = Math.max(0, max - step);
            }
            return {
                min: Number(min.toFixed(3)), max: Number(max.toFixed(3)),
                tickAmount: Math.min(10, Math.max(1, Math.round((max - min) / step))),
                forceNiceScale: false
            };
        }
        function axisLabels(measurement, scale) {
            const format = new Intl.NumberFormat(undefined, { maximumFractionDigits: measurement.axisDecimals });
            return value => {
                if (measurement.key === 'weight') {
                    const step = scale.max - scale.min > 5 ? 5 : 1;
                    const isGridValue = Math.abs(value / step - Math.round(value / step)) < 1e-6;
                    return isGridValue ? axisFormat.format(value) + ' kg' : '';
                }
                return format.format(value) + ' ' + measurement.unit;
            };
        }
        function buildYAxes(minX = fromTimestamp, maxX = toTimestamp) {
            const primaryAxis = primaryScale(minX, maxX);
            const secondaryRange = hasSecondary
                ? visibleRange(secondaryPoints, minX, maxX) ?? fullSecondaryRange
                : null;
            const axes = [{
                seriesName: primary.label,
                title: { text: `${primary.label} (${primary.unit})` },
                decimalsInFloat: primary.axisDecimals,
                labels: { formatter: axisLabels(primary, primaryAxis) },
                ...primaryAxis
            }];
            if (secondaryRange) {
                const secondaryAxis = measurementScale(secondary, secondaryRange);
                axes.push({
                    seriesName: secondary.label, opposite: true,
                    title: { text: `${secondary.label} (${secondary.unit})` },
                    decimalsInFloat: secondary.axisDecimals,
                    labels: { formatter: axisLabels(secondary, secondaryAxis) },
                    ...secondaryAxis
                });
            }
            return axes;
        }
        function buildPrimaryGridLines(minX = fromTimestamp, maxX = toTimestamp) {
            if (primary.key !== 'weight') return { yaxis: [] };
            const scale = primaryScale(minX, maxX);
            const lines = [];
            const step = scale.max - scale.min > 5 ? 5 : 1;
            for (let value = Math.ceil(scale.min / step) * step; value <= scale.max; value += step) {
                lines.push({
                    y: value,
                    borderColor: value % 10 === 0 ? theme.border : theme.grid,
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
                    annotations: buildPrimaryGridLines(minX, maxX)
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
        function detailNotice(message = '') {
            if (signal.aborted) return;
            const notice = container.querySelector(`#chart-error-${index}`);
            notice.dataset.state = 'warning';
            notice.setAttribute('role', 'status');
            notice.textContent = message;
            notice.hidden = !message;
        }
        function guardChartUpdate() {
            clearTimeout(updateGuardTimer);
            programmaticUpdate = true;
            updateGuardTimer = setTimeout(() => { programmaticUpdate = false; }, 150);
        }
        function applyRawDetails(chartContext, minX, maxX) {
            primaryPoints = renderedPoints(overviewPrimaryPoints, rawPoints[primary.key], minX, maxX);
            secondaryPoints = secondary ? renderedPoints(
                overviewSecondaryPoints, rawPoints[secondary.key], minX, maxX
            ) : [];
            const updatedSeries = [{ name: primary.label, data: primaryPoints }];
            if (hasSecondary) {
                updatedSeries.push({ name: secondary.label, data: secondaryPoints });
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
                annotations: buildPrimaryGridLines(minX, maxX)
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
            if (signal.aborted || visibleFrom >= visibleTo || version !== requestVersion) return;
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
                    const data = await apiGet({
                        action: 'charts_data', hive: hive.hiveId, signature: hive.signature,
                        device: hive.deviceType ?? 'hive',
                        ...(hive.sensorId ? { sensor: hive.sensorId } : {}), from, to
                    }, { signal: requestController.signal });
                    if (!measurements.every(series => Array.isArray(data[series.key]))) {
                        throw new Error('Invalid chart data.');
                    }
                    return { range: [from, to], data };
                }));
                if (signal.aborted || version !== requestVersion) return;
                results.forEach(result => {
                    measurements.forEach(series => result.data[series.key].forEach(point =>
                        rawPoints[series.key].set(point.x, point)));
                    rawRanges.push(result.range);
                });
                rawRanges = mergeRanges(rawRanges);
                detailNotice();
                applyRawDetails(chartContext, visibleFrom, visibleTo);
            } catch (error) {
                if (error.name !== 'AbortError' && version === requestVersion) {
                    detailNotice('Detailed data could not be loaded; the overview remains visible.');
                }
            }
        }
        function handleVisibleRange(chartContext, minX, maxX) {
            if (signal.aborted || programmaticUpdate || !Number.isFinite(minX) || !Number.isFinite(maxX)) return;
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
            detailNotice();
            clearTimeout(loadTimer);
            requestController?.abort();
            requestController = null;
            requestVersion += 1;
            primaryPoints = overviewPrimaryPoints;
            secondaryPoints = overviewSecondaryPoints;
            const overviewSeries = [{ name: primary.label, data: primaryPoints }];
            if (hasSecondary) {
                overviewSeries.push({ name: secondary.label, data: secondaryPoints });
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
                annotations: buildPrimaryGridLines(fromTimestamp, toTimestamp)
            }, false, false, false).catch(() => showError(index));
        }
        if (hasSecondary) {
            series.push({ name: secondary.label, data: secondaryPoints });
        }
        let chart;
        let rendered;
        const dispose = () => {
            clearTimeout(scaleTimer);
            clearTimeout(loadTimer);
            clearTimeout(updateGuardTimer);
            requestController?.abort();
            requestVersion += 1;
            // Let a pending mount settle before destroying ApexCharts' internal listeners.
            rendered?.catch(() => {}).then(() => chart.destroy());
        };
        signal.addEventListener('abort', dispose, { once: true });
        try {
            chart = new ApexCharts(container.querySelector(`#monitoring-chart-${index}`), {
                chart: {
                    type: 'line', height: 365, background: 'transparent',
                    fontFamily: theme.fontFamily, foreColor: theme.text,
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
                annotations: buildPrimaryGridLines(),
                colors: measurements.map(series => theme[series.color]),
                stroke: {
                    curve: 'straight',
                    width: hasSecondary ? [2, 1.5] : 2,
                    dashArray: hasSecondary ? [0, 4] : 0
                },
                markers: {
                    size: hasSecondary
                        ? [primaryPoints.length === 1 ? 4 : 0, secondaryPoints.length === 1 ? 4 : 0]
                        : primaryPoints.length === 1 ? 4 : 0,
                    hover: { size: 4 }
                },
                dataLabels: { enabled: false },
                legend: { show: hasSecondary, position: primary.key === 'weight' ? 'top' : 'bottom' },
                responsive: [{
                    breakpoint: 600,
                    options: { legend: { position: 'bottom' } }
                }],
                grid: {
                    borderColor: theme.border,
                    xaxis: { lines: { show: false } },
                    yaxis: { lines: { show: primary.key !== 'weight' } }
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
                        const primaryPoint = nearestPoint(primaryPoints, timestamp);
                        const secondaryPoint = nearestPoint(secondaryPoints, timestamp);
                        return `<div class="chart-tooltip">
                            <div class="chart-tooltip-date">${dateTimeFormat.format(new Date(timestamp))}</div>
                            ${tooltipRow(primary.label, primaryPoint, primary.color, value => formatMonitoringValue(primary, value), timestamp)}
                            ${secondary ? tooltipRow(secondary.label, secondaryPoint, secondary.color, value => formatMonitoringValue(secondary, value), timestamp) : ''}
                        </div>`;
                    }
                }
            });
            rendered = chart.render();
            rendered.catch(() => showError(index));
        } catch (error) {
            signal.removeEventListener('abort', dispose);
            chart?.destroy();
            showError(index);
        }
    });
}
