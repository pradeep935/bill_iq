import axios from 'axios';

const InventoryApi = {
    async openingStockList(params = {}) {
        const response = await axios.get('/app/inventory/opening-stock/list', { params });

        return response.data;
    },

    async openingStockReferences() {
        const response = await axios.get('/app/inventory/opening-stock/references');

        return response.data;
    },

    async searchOpeningStockProducts(q = '') {
        const response = await axios.get('/app/inventory/opening-stock/products/search', {
            params: { q },
        });

        return response.data;
    },

    async saveOpeningStock(payload, id = null) {
        const response = id
            ? await axios.put(`/app/inventory/opening-stock/${id}`, payload)
            : await axios.post('/app/inventory/opening-stock', payload);

        return response.data;
    },

    async approveOpeningStock(id) {
        const response = await axios.post(`/app/inventory/opening-stock/${id}/approve`);

        return response.data;
    },

    async reverseOpeningStock(id, remarks) {
        const response = await axios.post(`/app/inventory/opening-stock/${id}/reverse`, {
            remarks,
        });

        return response.data;
    },

    async stockSummary(params = {}) {
        const response = await axios.get('/app/inventory/current-stock/list', { params });

        return response.data;
    },

    async stockReferences() {
        const response = await axios.get('/app/inventory/current-stock/references');

        return response.data;
    },

    async controlReferences() {
        const response = await axios.get('/app/inventory/control/references');
        return response.data;
    },

    async searchProducts(q = '') {
        const response = await axios.get('/app/inventory/control/products/search', { params: { q } });
        return response.data;
    },

    async inventoryDashboard(params = {}) {
        const response = await axios.get('/app/inventory/control/dashboard', { params });
        return response.data;
    },

    async inventoryReports(params = {}) {
        const response = await axios.get('/app/inventory/control/reports', { params });
        return response.data;
    },

    async adjustmentReasons(params = {}) {
        const response = await axios.get('/app/inventory/adjustment-reasons/list', { params });
        return response.data;
    },

    async saveAdjustmentReason(payload, id = null) {
        const response = id ? await axios.put(`/app/inventory/adjustment-reasons/${id}`, payload) : await axios.post('/app/inventory/adjustment-reasons', payload);
        return response.data;
    },

    async stockAdjustments(params = {}) {
        const response = await axios.get('/app/inventory/stock-adjustments/list', { params });
        return response.data;
    },

    async saveStockAdjustment(payload, id = null) {
        const response = id ? await axios.put(`/app/inventory/stock-adjustments/${id}`, payload) : await axios.post('/app/inventory/stock-adjustments', payload);
        return response.data;
    },

    async postStockAdjustment(id) {
        const response = await axios.post(`/app/inventory/stock-adjustments/${id}/post`);
        return response.data;
    },

    async reverseStockAdjustment(id, remarks) {
        const response = await axios.post(`/app/inventory/stock-adjustments/${id}/reverse`, { remarks });
        return response.data;
    },

    async stockCounts(params = {}) {
        const response = await axios.get('/app/inventory/stock-counts/list', { params });
        return response.data;
    },

    async saveStockCount(payload, id = null) {
        const response = id ? await axios.put(`/app/inventory/stock-counts/${id}`, payload) : await axios.post('/app/inventory/stock-counts', payload);
        return response.data;
    },

    async scanStockCount(session, payload) {
        const response = await axios.post(`/app/inventory/stock-counts/${session}/scan`, payload);
        return response.data;
    },

    async postCountVariance(session) {
        const response = await axios.post(`/app/inventory/stock-counts/${session}/post-variance`);
        return response.data;
    },

    async stockTransfers(params = {}) {
        const response = await axios.get('/app/inventory/stock-transfers/list', { params });
        return response.data;
    },

    async saveStockTransfer(payload, id = null) {
        const response = id ? await axios.put(`/app/inventory/stock-transfers/${id}`, payload) : await axios.post('/app/inventory/stock-transfers', payload);
        return response.data;
    },

    async dispatchStockTransfer(id) {
        const response = await axios.post(`/app/inventory/stock-transfers/${id}/dispatch`);
        return response.data;
    },

    async receiveStockTransfer(id, payload) {
        const response = await axios.post(`/app/inventory/stock-transfers/${id}/receive`, payload);
        return response.data;
    },

    async locationTransfers(params = {}) {
        const response = await axios.get('/app/inventory/location-transfers/list', { params });
        return response.data;
    },

    async saveLocationTransfer(payload, id = null) {
        const response = id ? await axios.put(`/app/inventory/location-transfers/${id}`, payload) : await axios.post('/app/inventory/location-transfers', payload);
        return response.data;
    },
};

export default InventoryApi;
