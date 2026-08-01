const humanizeField = (field = '') => String(field)
    .replace(/\.\d+\./g, ' row ')
    .replace(/\.\d+$/g, '')
    .replaceAll('_', ' ')
    .replaceAll('.', ' ')
    .replace(/\s+/g, ' ')
    .trim();

export const responseErrors = (error) => error?.response?.data?.errors || {};

export const flattenErrors = (errors = {}) => {
    return Object.entries(errors)
        .flatMap(([field, messages]) => {
            const list = Array.isArray(messages) ? messages : [messages];

            return list
                .filter(Boolean)
                .map((message) => {
                    const text = String(message);

                    return text.includes('field is required') && field
                        ? `Please check ${humanizeField(field)}.`
                        : text;
                });
        });
};

export const firstErrorMessage = (error, fallback = 'Please check the form and try again.') => {
    const errors = responseErrors(error);
    const first = flattenErrors(errors)[0];

    return first || error?.response?.data?.message || error?.message || fallback;
};

export const normalizeAxiosError = (error) => {
    if (!error?.response) {
        return Promise.reject(error);
    }

    const data = error.response.data || {};
    const first = firstErrorMessage(error, data.message || 'Request failed.');

    error.response.data = {
        ...data,
        message: first,
        readable_message: first,
        readable_errors: flattenErrors(data.errors || {}),
    };

    return Promise.reject(error);
};
