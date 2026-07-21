import axios from 'axios';

const salesBase = '/app/sales';
const purchaseBase = '/app/purchase';

const OrderApi = {
    async references(scope = 'sales') {
        const base = scope === 'purchase' ? purchaseBase : salesBase;
        const response = await axios.get(`${base}/order-management/references`);
        return response.data;
    },
    async products(q = '', scope = 'sales') {
        const base = scope === 'purchase' ? purchaseBase : salesBase;
        const response = await axios.get(`${base}/order-management/products/search`, { params: { q } });
        return response.data;
    },
    async dashboard(scope = 'sales') {
        const base = scope === 'purchase' ? purchaseBase : salesBase;
        const response = await axios.get(`${base}/order-management/dashboard`);
        return response.data;
    },
    async reports(scope = 'sales') {
        const base = scope === 'purchase' ? purchaseBase : salesBase;
        const response = await axios.get(`${base}/order-management/reports`);
        return response.data;
    },
    async quotations(params = {}) {
        const response = await axios.get(`${salesBase}/quotations/list`, { params });
        return response.data;
    },
    async saveQuotation(payload, id = null) {
        const response = id ? await axios.put(`${salesBase}/quotations/${id}`, payload) : await axios.post(`${salesBase}/quotations`, payload);
        return response.data;
    },
    async convertQuotation(id) {
        const response = await axios.post(`${salesBase}/quotations/${id}/convert`);
        return response.data;
    },
    async salesOrders(params = {}) {
        const response = await axios.get(`${salesBase}/orders/list`, { params });
        return response.data;
    },
    async saveSalesOrder(payload, id = null) {
        const response = id ? await axios.put(`${salesBase}/orders/${id}`, payload) : await axios.post(`${salesBase}/orders`, payload);
        return response.data;
    },
    async approveSalesOrder(id) {
        const response = await axios.post(`${salesBase}/orders/${id}/approve`);
        return response.data;
    },
    async deliveryChallans(params = {}) {
        const response = await axios.get(`${salesBase}/delivery-challans/list`, { params });
        return response.data;
    },
    async saveDeliveryChallan(payload, id = null) {
        const response = id ? await axios.put(`${salesBase}/delivery-challans/${id}`, payload) : await axios.post(`${salesBase}/delivery-challans`, payload);
        return response.data;
    },
    async dispatchChallan(id) {
        const response = await axios.post(`${salesBase}/delivery-challans/${id}/dispatch`);
        return response.data;
    },
    async requisitions(params = {}) {
        const response = await axios.get(`${purchaseBase}/requisitions/list`, { params });
        return response.data;
    },
    async saveRequisition(payload, id = null) {
        const response = id ? await axios.put(`${purchaseBase}/requisitions/${id}`, payload) : await axios.post(`${purchaseBase}/requisitions`, payload);
        return response.data;
    },
    async purchaseOrders(params = {}) {
        const response = await axios.get(`${purchaseBase}/purchase-orders/list`, { params });
        return response.data;
    },
    async savePurchaseOrder(payload, id = null) {
        const response = id ? await axios.put(`${purchaseBase}/purchase-orders/${id}`, payload) : await axios.post(`${purchaseBase}/purchase-orders`, payload);
        return response.data;
    },
    async confirmPurchaseOrder(id, payload = {}) {
        const response = await axios.post(`${purchaseBase}/purchase-orders/${id}/confirm`, payload);
        return response.data;
    },
    async goodsReceipts(params = {}) {
        const response = await axios.get(`${purchaseBase}/goods-receipts/list`, { params });
        return response.data;
    },
    async saveGoodsReceipt(payload, id = null) {
        const response = id ? await axios.put(`${purchaseBase}/goods-receipts/${id}`, payload) : await axios.post(`${purchaseBase}/goods-receipts`, payload);
        return response.data;
    },
    async receiveGoods(id) {
        const response = await axios.post(`${purchaseBase}/goods-receipts/${id}/receive`);
        return response.data;
    },
};

export default OrderApi;
