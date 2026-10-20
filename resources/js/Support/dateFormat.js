const zone = 'Asia/Kolkata';
const partsFormatter = new Intl.DateTimeFormat('en-IN', { timeZone: zone, day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true });
function parts(value) {
    if (value === null || value === undefined || value === '') return null;
    let text = String(value).trim();
    // Calendar dates have no timezone. Naive application timestamps are Kolkata wall time.
    if (/^\d{4}-\d{2}-\d{2}$/.test(text)) text += 'T00:00:00+05:30';
    else if (/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?$/.test(text)) text = text.replace(' ', 'T') + '+05:30';
    else if (!(value instanceof Date) && !/^\d{4}-\d{2}-\d{2}T.*(?:Z|[+-]\d{2}:?\d{2})$/i.test(text)) return null;
    const date = value instanceof Date ? value : new Date(text);
    if (Number.isNaN(date.getTime())) return null;
    return Object.fromEntries(partsFormatter.formatToParts(date).map(p => [p.type, p.value]));
}
export function formatDate(value, fallback = '—') {
    const p = parts(value);
    return p ? `${p.day}/${p.month}/${p.year}` : fallback;
}
export function formatDateTime(value, fallback = '—') {
    const p = parts(value);
    return p ? `${p.day}/${p.month}/${p.year}, ${p.hour}:${p.minute} ${p.dayPeriod.toUpperCase()}` : fallback;
}
export function formatDateField(key, value) {
    if (/(?:^date$|_date$|^date_)/.test(key)) return formatDate(value);
    if (/_at$/.test(key)) return formatDateTime(value);
    return value;
}
