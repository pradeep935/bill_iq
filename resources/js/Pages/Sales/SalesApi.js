import axios from 'axios';

const SalesApi = {
    async customers(params = {}) {
        const response = await axios.get('/app/sales/customers/list', { params });
        return response.data;
    },

    async searchCustomers(q = '') {
        const response = await axios.get('/app/sales/customers/search', { params: { q } });
        return response.data;
    },

    async saveCustomer(payload, id = null) {
        const response = id
            ? await axios.put(`/app/sales/customers/${id}`, payload)
            : await axios.post('/app/sales/customers', payload);
        return response.data;
    },

    async deleteCustomer(id) {
        const response = await axios.delete(`/app/sales/customers/${id}`);
        return response.data;
    },

    async restoreCustomer(id) {
        const response = await axios.post(`/app/sales/customers/${id}/restore`);
        return response.data;
    },

    async sales(params = {}) {
        const response = await axios.get('/app/sales/invoices/list', { params });
        return response.data;
    },

    async references() {
        const response = await axios.get('/app/sales/invoices/references');
        return response.data;
    },

    async searchProducts(q = '', scope = {}) {
        const response = await axios.get('/app/sales/invoices/products/search', { params: { q, ...scope } });
        return response.data;
    },

    async saveSale(payload, id = null) {
        const response = id
            ? await axios.put(`/app/sales/invoices/${id}`, payload)
            : await axios.post('/app/sales/invoices', payload);
        return response.data;
    },

    async getSale(id) {
        const response = await axios.get(`/app/sales/invoices/${id}`);
        return response.data;
    },

    async duplicateSale(id) {
        const response = await axios.post(`/app/sales/invoices/${id}/duplicate`);
        return response.data;
    },

    async approveSale(id) {
        const response = await axios.post(`/app/sales/invoices/${id}/approve`);
        return response.data;
    },

    async cancelSale(id, reason = '') {
        const response = await axios.post(`/app/sales/invoices/${id}/cancel`, { reason });
        return response.data;
    },

    async reverseSale(id, remarks) {
        const response = await axios.post(`/app/sales/invoices/${id}/reverse`, { remarks });
        return response.data;
    },

    async reports(params = {}) {
        const response = await axios.get('/app/sales/invoices/reports', { params });
        return response.data;
    },

    async salesReturns(params = {}) {
        const response = await axios.get('/app/sales/returns/list', { params });
        return response.data;
    },

    async returnReferences() {
        const response = await axios.get('/app/sales/returns/references');
        return response.data;
    },

    async searchReturnProducts(q = '', scope = {}) {
        const response = await axios.get('/app/sales/returns/products/search', { params: { q, ...scope } });
        return response.data;
    },

    async searchReturnInvoices(q = '') {
        const response = await axios.get('/app/sales/returns/invoices/search', { params: { q } });
        return response.data;
    },

    async salesReturnItems(saleId) {
        const response = await axios.get(`/app/sales/returns/invoices/${saleId}/items`);
        return response.data;
    },

    async saveSalesReturn(payload, id = null) {
        const response = id
            ? await axios.put(`/app/sales/returns/${id}`, payload)
            : await axios.post('/app/sales/returns', payload);
        return response.data;
    },

    async approveSalesReturn(id) {
        const response = await axios.post(`/app/sales/returns/${id}/approve`);
        return response.data;
    },

    async cancelSalesReturn(id) {
        const response = await axios.post(`/app/sales/returns/${id}/cancel`);
        return response.data;
    },

    async reverseSalesReturn(id, remarks) {
        const response = await axios.post(`/app/sales/returns/${id}/reverse`, { remarks });
        return response.data;
    },
};

export default SalesApi;
