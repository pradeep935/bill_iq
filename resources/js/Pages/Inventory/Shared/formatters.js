export const formatInventoryQty = (value) => {
    const number = Number.isFinite(Number(value)) ? Number(value) : 0;
    const rounded = Math.round(number * 1000) / 1000;
    const isWhole = Math.abs(rounded - Math.round(rounded)) < 0.0004;

    return rounded.toLocaleString('en-IN', {
        minimumFractionDigits: isWhole ? 0 : 0,
        maximumFractionDigits: 3,
    });
};

export const formatInventoryDateTime = (value) => {
    if (!value) return '-';

    const text = String(value);
    const dateOnlyMatch = text.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    const localDateTimeMatch = text.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/);

    if (dateOnlyMatch || localDateTimeMatch) {
        const parts = localDateTimeMatch || dateOnlyMatch;
        const date = new Date(
            Number(parts[1]),
            Number(parts[2]) - 1,
            Number(parts[3]),
            Number(parts[4] || 0),
            Number(parts[5] || 0),
            Number(parts[6] || 0)
        );

        if (Number.isNaN(date.getTime())) return '-';

        return new Intl.DateTimeFormat('en-IN', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            hour12: true,
        }).format(date).replace(/\b(am|pm)\b/i, (match) => match.toUpperCase());
    }

    const date = new Date(text);

    if (Number.isNaN(date.getTime())) return '-';

    return new Intl.DateTimeFormat('en-IN', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: true,
        timeZone: 'Asia/Kolkata',
    }).format(date).replace(/\b(am|pm)\b/i, (match) => match.toUpperCase());
};
