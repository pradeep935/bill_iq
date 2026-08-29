import axios from 'axios';

const PurchaseApi = {
    async suppliers(params = {}) {
        const response = await axios.get('/app/purchase/suppliers/list', { params });
        return response.data;
    },

    async saveSupplier(payload, id = null) {
        const response = id
            ? await axios.put(`/app/purchase/suppliers/${id}`, payload)
            : await axios.post('/app/purchase/suppliers', payload);
        return response.data;
    },

    async deleteSupplier(id) {
        const response = await axios.delete(`/app/purchase/suppliers/${id}`);
        return response.data;
    },

    async restoreSupplier(id) {
        const response = await axios.post(`/app/purchase/suppliers/${id}/restore`);
        return response.data;
    },

    async purchases(params = {}) {
        const response = await axios.get('/app/purchase/bills/list', { params });
        return response.data;
    },

    async references() {
        const response = await axios.get('/app/purchase/bills/references');
        return response.data;
    },

    async searchProducts(q = '') {
        const response = await axios.get('/app/purchase/bills/products/search', { params: { q } });
        return response.data;
    },

    async savePurchase(payload, id = null) {
        const response = id
            ? await axios.put(`/app/purchase/bills/${id}`, payload)
            : await axios.post('/app/purchase/bills', payload);
        return response.data;
    },

    async duplicatePurchase(id) {
        const response = await axios.post(`/app/purchase/bills/${id}/duplicate`);
        return response.data;
    },

    async approvePurchase(id) {
        const response = await axios.post(`/app/purchase/bills/${id}/approve`);
        return response.data;
    },

    async cancelPurchase(id) {
        const response = await axios.post(`/app/purchase/bills/${id}/cancel`);
        return response.data;
    },

    async reversePurchase(id, remarks) {
        const response = await axios.post(`/app/purchase/bills/${id}/reverse`, { remarks });
        return response.data;
    },

    async reorderReferences() {
        const response = await axios.get('/app/purchase/reorder/references');
        return response.data;
    },

    async reorderSuggestions(params = {}) {
        const response = await axios.get('/app/purchase/reorder/list', { params });
        return response.data;
    },

    async createReorderRequisition(items = []) {
        const response = await axios.post('/app/purchase/reorder/requisitions', { items });
        return response.data;
    },

    async createReorderPurchaseOrders(payload = {}) {
        const response = await axios.post('/app/purchase/reorder/purchase-orders', payload);
        return response.data;
    },

    async inventoryOrderReferences() {
        const response = await axios.get('/app/purchase/orders/references');
        return response.data;
    },

    async inventoryOrders(params = {}) {
        const response = await axios.get('/app/purchase/orders/list', { params });
        return response.data;
    },

    async searchInventoryOrderProducts(q = '') {
        const response = await axios.get('/app/purchase/orders/products/search', { params: { q } });
        return response.data;
    },

    async saveInventoryOrder(payload, id = null) {
        const response = id
            ? await axios.put(`/app/purchase/orders/${id}`, payload)
            : await axios.post('/app/purchase/orders', payload);
        return response.data;
    },

    async markInventoryOrderOrdered(id) {
        const response = await axios.post(`/app/purchase/orders/${id}/mark-ordered`);
        return response.data;
    },

    async cancelInventoryOrder(id) {
        const response = await axios.post(`/app/purchase/orders/${id}/cancel`);
        return response.data;
    },

    async purchaseReturns(params = {}) {
        const response = await axios.get('/app/purchase/returns/list', { params });
        return response.data;
    },

    async returnReferences() {
        const response = await axios.get('/app/purchase/returns/references');
        return response.data;
    },

    async searchReturnProducts(q = '') {
        const response = await axios.get('/app/purchase/returns/products/search', { params: { q } });
        return response.data;
    },

    async searchReturnPurchases(q = '') {
        const response = await axios.get('/app/purchase/returns/purchases/search', { params: { q } });
        return response.data;
    },

    async purchaseReturnItems(purchaseId) {
        const response = await axios.get(`/app/purchase/returns/purchases/${purchaseId}/items`);
        return response.data;
    },

    async savePurchaseReturn(payload, id = null) {
        const response = id
            ? await axios.put(`/app/purchase/returns/${id}`, payload)
            : await axios.post('/app/purchase/returns', payload);
        return response.data;
    },

    async approvePurchaseReturn(id) {
        const response = await axios.post(`/app/purchase/returns/${id}/approve`);
        return response.data;
    },

    async cancelPurchaseReturn(id) {
        const response = await axios.post(`/app/purchase/returns/${id}/cancel`);
        return response.data;
    },

    async reversePurchaseReturn(id, remarks) {
        const response = await axios.post(`/app/purchase/returns/${id}/reverse`, { remarks });
        return response.data;
    },
};

export default PurchaseApi;
