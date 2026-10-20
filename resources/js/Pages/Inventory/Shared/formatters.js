export const formatInventoryQty = (value) => {
    const number = Number.isFinite(Number(value)) ? Number(value) : 0;
    const rounded = Math.round(number * 1000) / 1000;
    const isWhole = Math.abs(rounded - Math.round(rounded)) < 0.0004;

    return rounded.toLocaleString('en-IN', {
        minimumFractionDigits: isWhole ? 0 : 0,
        maximumFractionDigits: 3,
    });
};

export { formatDateTime as formatInventoryDateTime } from '../../../Support/dateFormat.js';
