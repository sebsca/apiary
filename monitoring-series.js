const measurements = {
    weight: { label: 'Weight', unit: 'kg', decimals: 2, axisDecimals: 0, step: 1, color: 'weight' },
    temperature: { label: 'Outside temperature', unit: '°C', decimals: 1, axisDecimals: 0, step: 5, color: 'temperature' },
    humidity: { label: 'Outside humidity', unit: '%', decimals: 1, axisDecimals: 0, step: 10, color: 'humidity' },
    pressure: { label: 'Air pressure', unit: 'hPa', decimals: 1, axisDecimals: 0, step: 5, color: 'pressure' },
    battery: { label: 'Battery voltage', unit: 'V', decimals: 3, axisDecimals: 2, step: 0.05, color: 'battery' }
};

export function monitoringSeries(device) {
    const keys = device.deviceType === 'scale' ? ['humidity', 'battery']
        : device.deviceType === 'gateway' ? ['pressure', 'battery'] : ['weight', 'temperature'];
    return keys.map(key => ({ key, ...measurements[key], points: device[`${key}Points`] ?? [] }));
}

export function formatMonitoringValue(series, value) {
    return new Intl.NumberFormat(undefined, {
        minimumFractionDigits: series.decimals, maximumFractionDigits: series.decimals
    }).format(value) + ' ' + series.unit;
}
