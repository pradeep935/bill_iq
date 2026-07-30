import axios from 'axios';

let endpoints = {};
const fallbackBase = () => `${window.location.pathname.split('/sales')[0]}/sales`;
const url = (key, fallback) => endpoints[key] || `${fallbackBase()}${fallback}`;
const withId = (key, fallback, id) => url(key, fallback).replace('__ID__', id);

const SalesApi = {
    configure(routes = {}) { endpoints = routes || {}; },

    async customers(params = {}) {
        const response = await axios.get('/app/sales/customers/list', { params });
        return response.data;
    },

    async customerReferences() {
        const response = await axios.get('/app/sales/customers/references');
        return response.data;
    },

    async searchCustomers(q = '') {
        const response = await axios.get('/app/sales/customers/search', { params: { q } });
        return response.data;
    },

    async saveCustomer(payload, id = null) {
        const response = id
            ? await axios.patch(`/app/sales/customers/${id}`, payload)
            : await axios.post('/app/sales/customers', payload);
        return response.data;
    },

    async getCustomer(id) {
        const response = await axios.get(`/app/sales/customers/${id}`);
        return response.data;
    },

    async customerLedger(id, params = {}) {
        const response = await axios.get(`/app/sales/customers/${id}/ledger`, { params });
        return response.data;
    },

    async customerOutstanding(id) {
        const response = await axios.get(`/app/sales/customers/${id}/outstanding`);
        return response.data;
    },

    async activateCustomer(id) {
        const response = await axios.post(`/app/sales/customers/${id}/activate`);
        return response.data;
    },

    async deactivateCustomer(id) {
        const response = await axios.post(`/app/sales/customers/${id}/deactivate`);
        return response.data;
    },

    async importCustomers(file) {
        const form = new FormData();
        form.append('file', file);
        const response = await axios.post('/app/sales/customers/import', form, { headers: { 'Content-Type': 'multipart/form-data' } });
        return response.data;
    },

    customerExportUrl(params = {}) {
        const target = new URL('/app/sales/customers/export', window.location.origin);
        Object.entries(params).forEach(([key, value]) => { if (value !== '' && value !== null && value !== undefined) target.searchParams.set(key, value); });
        return target.pathname + target.search;
    },

    customerImportTemplateUrl() { return '/app/sales/customers/import-template'; },

    async deleteCustomer(id) {
        const response = await axios.delete(`/app/sales/customers/${id}`);
        return response.data;
    },

    async restoreCustomer(id) {
        const response = await axios.post(`/app/sales/customers/${id}/restore`);
        return response.data;
    },

    async sales(params = {}) {
        const response = await axios.get(url('list', '/invoices/list'), { params });
        return response.data;
    },

    async references() {
        const response = await axios.get(url('references', '/invoices/references'));
        return response.data;
    },

    async searchProducts(q = '', scope = {}) {
        const response = await axios.get(url('productSearch', '/invoices/products/search'), { params: { q, ...scope } });
        return response.data;
    },

    async scanProduct(barcode = '', scope = {}) {
        const response = await axios.get(url('productScan', '/invoices/products/scan'), { params: { barcode, ...scope } });
        return response.data;
    },

    async saveSale(payload, id = null) {
        const response = id
            ? await axios.put(withId('update', '/invoices/__ID__', id), payload)
            : await axios.post(url('store', '/invoices'), payload);
        return response.data;
    },

    async getSale(id) {
        const response = await axios.get(withId('show', '/invoices/__ID__', id));
        return response.data;
    },

    async duplicateSale(id) {
        const response = await axios.post(withId('duplicate', '/invoices/__ID__/duplicate', id));
        return response.data;
    },

    async approveSale(id) {
        const response = await axios.post(withId('approve', '/invoices/__ID__/approve', id));
        return response.data;
    },

    async holdSale(id) {
        const response = await axios.post(withId('hold', '/invoices/__ID__/hold', id));
        return response.data;
    },

    async addPayment(id, payload) {
        const response = await axios.post(withId('paymentStore', '/invoices/__ID__/payments', id), payload);
        return response.data;
    },

    async cancelSale(id, reason = '') {
        const response = await axios.post(withId('cancel', '/invoices/__ID__/cancel', id), { reason });
        return response.data;
    },

    async reverseSale(id, remarks) {
        const response = await axios.post(withId('reverse', '/invoices/__ID__/reverse', id), { remarks });
        return response.data;
    },

    printUrl(id) { return withId('print', '/invoices/__ID__/print', id); },
    exportUrl(params = {}) {
        const target = new URL(url('export', '/invoices/export'), window.location.origin);
        Object.entries(params).forEach(([key, value]) => { if (value !== '' && value !== null && value !== undefined) target.searchParams.set(key, value); });
        return target.pathname + target.search;
    },

    async reports(params = {}) {
        const response = await axios.get(url('reports', '/invoices/reports'), { params });
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

    async searchReturnInvoices(q = '', scope = {}) {
        const response = await axios.get('/app/sales/returns/invoice-search', { params: { q, ...scope } });
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

    async addSalesReturnRefund(id, payload) {
        const response = await axios.post(`/app/sales/returns/${id}/refunds`, payload);
        return response.data;
    },

    salesReturnPrintUrl(id) { return `/app/sales/returns/${id}/print`; },
    salesReturnExportUrl(params = {}) {
        const target = new URL('/app/sales/returns/export', window.location.origin);
        Object.entries(params).forEach(([key, value]) => { if (value !== '' && value !== null && value !== undefined) target.searchParams.set(key, value); });
        return target.pathname + target.search;
    },
};

export default SalesApi;
