// GST registration types for customers and suppliers (same three choices as common billing apps).
export const gstTypes = [
    { value: 'unregistered', label: 'Unregistered / Consumer' },
    { value: 'regular', label: 'Registered Business - Regular' },
    { value: 'composition', label: 'Registered Business - Composition' },
];
export const gstTypeLabel = (value) => gstTypes.find((type) => type.value === value)?.label || '-';
// Typing a complete GSTIN means the party is registered.
export const gstTypeForGstin = (type, gstin) => (String(gstin || '').trim().length === 15 && (!type || type === 'unregistered') ? 'regular' : type);
