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

    async searchOpeningStockProducts(q = '', scope = {}) {
        const response = await axios.get('/app/inventory/opening-stock/products/search', {
            params: { q, ...scope },
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

    async deleteOpeningStock(id) {
        const response = await axios.delete(`/app/inventory/opening-stock/${id}`);

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

    async stockProductDetail(productId, params = {}) {
        const response = await axios.get(`/app/inventory/current-stock/products/${productId}`, { params });

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

    async inventoryValuation(params = {}) {
        const response = await axios.get('/app/inventory/control/valuation', { params });
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

    async warehouseLocations(params = {}) {
        const response = await axios.get('/app/inventory/warehouse-locations/list', { params });
        return response.data;
    },

    async saveWarehouseLocation(payload, id = null) {
        const response = id ? await axios.put(`/app/inventory/warehouse-locations/${id}`, payload) : await axios.post('/app/inventory/warehouse-locations', payload);
        return response.data;
    },

    async batchReferences() {
        const response = await axios.get('/app/inventory/batches/references');
        return response.data;
    },

    async batchList(params = {}) {
        const response = await axios.get('/app/inventory/batches/list', { params });
        return response.data;
    },

    async batchDetail(batch, params = {}) {
        const response = await axios.get(`/app/inventory/batches/${batch}`, { params });
        return response.data;
    },

    async batchLedger(batch, params = {}) {
        const response = await axios.get(`/app/inventory/batches/${batch}/ledger`, { params });
        return response.data;
    },

    async batchReports(params = {}) {
        const response = await axios.get('/app/inventory/batches/reports', { params });
        return response.data;
    },

    async batchFefo(params = {}) {
        const response = await axios.get('/app/inventory/batches/fefo', { params });
        return response.data;
    },

    async updateBatchStatus(batch, payload) {
        const response = await axios.post(`/app/inventory/batches/${batch}/status`, payload);
        return response.data;
    },

    async transferBatch(batch, payload) {
        const response = await axios.post(`/app/inventory/batches/${batch}/transfer`, payload);
        return response.data;
    },

    async splitBatch(batch, payload) {
        const response = await axios.post(`/app/inventory/batches/${batch}/split`, payload);
        return response.data;
    },

    async mergeBatch(batch, payload) {
        const response = await axios.post(`/app/inventory/batches/${batch}/merge`, payload);
        return response.data;
    },

    async serialReferences() {
        const response = await axios.get('/app/inventory/serials/references');
        return response.data;
    },

    async serialList(params = {}) {
        const response = await axios.get('/app/inventory/serials/list', { params });
        return response.data;
    },

    async serialDetail(id) {
        const response = await axios.get(`/app/inventory/serials/${id}`);
        return response.data;
    },

    async saveSerial(payload, id = null) {
        const response = id ? await axios.put(`/app/inventory/serials/${id}`, payload) : await axios.post('/app/inventory/serials', payload);
        return response.data;
    },

    async bulkSerials(payload) {
        const response = await axios.post('/app/inventory/serials/bulk', payload);
        return response.data;
    },

    async serialStatus(id, payload) {
        const response = await axios.post(`/app/inventory/serials/${id}/status`, payload);
        return response.data;
    },

    async serialTransfer(id, payload) {
        const response = await axios.post(`/app/inventory/serials/${id}/transfer`, payload);
        return response.data;
    },

    async deleteSerial(id) {
        const response = await axios.delete(`/app/inventory/serials/${id}`);
        return response.data;
    },

    async serialReports(params = {}) {
        const response = await axios.get('/app/inventory/serials/reports', { params });
        return response.data;
    },

    async barcodeReferences() {
        const response = await axios.get('/app/inventory/barcode-center/references');
        return response.data;
    },

    async barcodeList(params = {}) {
        const response = await axios.get('/app/inventory/barcode-center/list', { params });
        return response.data;
    },

    async assignBarcode(payload) {
        const response = await axios.post('/app/inventory/barcode-center/assign', payload);
        return response.data;
    },

    async generateBarcode(payload) {
        const response = await axios.post('/app/inventory/barcode-center/generate', payload);
        return response.data;
    },

    async bulkGenerateBarcodes(payload) {
        const response = await axios.post('/app/inventory/barcode-center/bulk-generate', payload);
        return response.data;
    },

    async scanBarcode(barcode) {
        const response = await axios.post('/app/inventory/barcode-center/scan', { barcode });
        return response.data;
    },

    async printBarcode(payload) {
        const response = await axios.post('/app/inventory/barcode-center/print', payload);
        return response.data;
    },

    async setPrimaryBarcode(id) {
        const response = await axios.post(`/app/inventory/barcode-center/${id}/primary`);
        return response.data;
    },

    async toggleBarcode(id, is_active) {
        const response = await axios.post(`/app/inventory/barcode-center/${id}/toggle`, { is_active });
        return response.data;
    },

    async barcodeReports(params = {}) {
        const response = await axios.get('/app/inventory/barcode-center/reports', { params });
        return response.data;
    },

    async manufacturingReferences() {
        const response = await axios.get('/app/inventory/manufacturing/references');
        return response.data;
    },

    async manufacturingDashboard(params = {}) {
        const response = await axios.get('/app/inventory/manufacturing/dashboard', { params });
        return response.data;
    },

    async bomList(params = {}) {
        const response = await axios.get('/app/inventory/manufacturing/boms/list', { params });
        return response.data;
    },

    async saveBom(payload, id = null) {
        const response = id ? await axios.put(`/app/inventory/manufacturing/boms/${id}`, payload) : await axios.post('/app/inventory/manufacturing/boms', payload);
        return response.data;
    },

    async duplicateBom(id) {
        const response = await axios.post(`/app/inventory/manufacturing/boms/${id}/duplicate`);
        return response.data;
    },

    async activateBom(id, active = true) {
        const response = await axios.post(`/app/inventory/manufacturing/boms/${id}/activate`, { active });
        return response.data;
    },

    async productionOrders(params = {}) {
        const response = await axios.get('/app/inventory/manufacturing/orders/list', { params });
        return response.data;
    },

    async saveProductionOrder(payload, id = null) {
        const response = id ? await axios.put(`/app/inventory/manufacturing/orders/${id}`, payload) : await axios.post('/app/inventory/manufacturing/orders', payload);
        return response.data;
    },

    async checkProductionMaterials(id) {
        const response = await axios.get(`/app/inventory/manufacturing/orders/${id}/materials`);
        return response.data;
    },

    async transitionProductionOrder(id, status) {
        const response = await axios.post(`/app/inventory/manufacturing/orders/${id}/transition`, { status });
        return response.data;
    },

    async completeProductionOrder(id, payload) {
        const response = await axios.post(`/app/inventory/manufacturing/orders/${id}/complete`, payload);
        return response.data;
    },

    async manufacturingReports(params = {}) {
        const response = await axios.get('/app/inventory/manufacturing/reports', { params });
        return response.data;
    },
};

export default InventoryApi;
