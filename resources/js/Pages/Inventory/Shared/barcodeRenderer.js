const CODE128_PATTERNS = [
    '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
    '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
    '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
    '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
    '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
    '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
    '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
    '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
    '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
    '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
    '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
];

const START_CODE_B = 104;
const STOP_CODE = 106;

const code128Values = (value) => {
    const text = String(value || '');

    if (!text) {
        return [];
    }

    const values = [START_CODE_B];
    for (const char of text) {
        const code = char.charCodeAt(0);
        if (code < 32 || code > 126) {
            throw new Error('CODE128 supports printable ASCII barcode values only.');
        }
        values.push(code - 32);
    }

    const checksum = values.reduce((sum, code, index) => sum + (index === 0 ? code : code * index), 0) % 103;
    values.push(checksum, STOP_CODE);

    return values;
};

export const code128SvgMarkup = (value, options = {}) => {
    const moduleWidth = Number(options.moduleWidth || 2);
    const height = Number(options.height || 58);
    const quietZone = Number(options.quietZone || 10);
    const values = code128Values(value);

    if (!values.length) {
        return '';
    }

    let x = quietZone;
    const bars = [];

    values.forEach((code) => {
        const pattern = CODE128_PATTERNS[code];
        for (let index = 0; index < pattern.length; index += 1) {
            const width = Number(pattern[index]) * moduleWidth;
            if (index % 2 === 0) {
                bars.push(`<rect x="${x}" y="0" width="${width}" height="${height}" />`);
            }
            x += width;
        }
    });

    const width = x + quietZone;

    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${width} ${height}" width="${width}" height="${height}" preserveAspectRatio="none" shape-rendering="crispEdges" aria-label="Barcode ${String(value).replaceAll('"', '&quot;')}"><rect width="100%" height="100%" fill="#fff"/>${bars.join('')}</svg>`;
};

export const code128DataUri = (value, options = {}) => {
    const markup = code128SvgMarkup(value, options);
    return markup ? `data:image/svg+xml;charset=utf-8,${encodeURIComponent(markup)}` : '';
};

export const code128BarsHtml = (value) => {
    const values = code128Values(value);

    if (!values.length) {
        return '';
    }

    return values.map((code) => {
        const pattern = CODE128_PATTERNS[code];

        return [...pattern].map((width, index) => {
            const moduleWidth = Number(width);
            return index % 2 === 0
                ? `<i style="flex:${moduleWidth} 0 0;background:#000;height:100%;display:block"></i>`
                : `<i style="flex:${moduleWidth} 0 0;background:#fff;height:100%;display:block"></i>`;
        }).join('');
    }).join('');
};
