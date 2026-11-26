// The server asks before the same goods would be counted in stock twice (or a bill differs from its receipt or challan).
// The person decides; "OK" sends the same request again with the confirmation.
export const installGoodsConfirm = (axios, ask = (message) => window.confirm(message)) => {
    axios.interceptors.response.use(
        (response) => response,
        (error) => {
            const message = error.response?.data?.errors?.confirm_goods?.[0];
            const config = error.config;
            if (!message || !config || config.goodsConfirmed || !ask(message)) {
                return Promise.reject(error);
            }
            const body = typeof config.data === 'string' && config.data ? JSON.parse(config.data) : (config.data || {});

            // A request without a body was labelled as a form, so the type is set again.
            config.headers?.set ? config.headers.set('Content-Type', 'application/json') : (config.headers = { ...config.headers, 'Content-Type': 'application/json' });

            return axios({ ...config, goodsConfirmed: true, data: JSON.stringify({ ...body, confirm_goods: true }) });
        },
    );
};
