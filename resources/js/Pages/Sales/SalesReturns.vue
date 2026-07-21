<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import Layout from '../Layout.vue';
import SalesApi from './SalesApi';

defineProps({ page: { type: String, default: 'sales-returns' }, title: { type: String, default: 'Sales Returns' } });

const today = new Date().toISOString().slice(0, 10);
const returns = ref([]);
const references = ref({ customers: [], branches: [], warehouses: [], payment_methods: [] });
const invoiceSearch = ref('');
const invoiceResults = ref([]);
const productSearch = ref('');
const productResults = ref([]);
const loading = ref(false);
const saving = ref(false);
const errors = ref({});
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });
const filters = reactive({ status: '', return_type: '', settlement_type: '', date_from: '', date_to: '' });
const form = reactive({
    id: null, return_type: 'against_sale', sales_voucher_id: '', invoice_number: '', customer_id: '',
    branch_id: '', warehouse_id: '', return_date: today, tax_type: 'intrastate', place_of_supply_state_id: '',
    settlement_type: 'customer_credit', reason: '', remarks: '', items: [], refunds: [],
});

const filteredWarehouses = computed(() => !form.branch_id ? references.value.warehouses || [] : (references.value.warehouses || []).filter((w) => Number(w.branch_id || 0) === Number(form.branch_id)));
const refundMethods = computed(() => references.value.payment_methods || []);
const totals = computed(() => {
    const total = form.items.reduce((sum, item) => sum + Number(item.quantity || 0) * Number(item.selling_rate || 0), 0);
    const refund = form.refunds.reduce((sum, row) => sum + Number(row.amount || 0), 0);
    return { total: Math.round(total), refund, balance: Math.max(0, Math.round(total) - refund) };
});

const reset = () => {
    Object.assign(form, { id: null, return_type: 'against_sale', sales_voucher_id: '', invoice_number: '', customer_id: '', branch_id: '', warehouse_id: '', return_date: today, tax_type: 'intrastate', place_of_supply_state_id: '', settlement_type: 'customer_credit', reason: '', remarks: '', items: [], refunds: [] });
    invoiceSearch.value = ''; productSearch.value = ''; invoiceResults.value = []; productResults.value = []; errors.value = {};
};
const loadReferences = async () => { references.value = await SalesApi.returnReferences(); };
const loadReturns = async (page = 1) => {
    loading.value = true;
    try {
        const response = await SalesApi.salesReturns({ ...filters, page });
        returns.value = response.returns || [];
        pagination.value = response.pagination || pagination.value;
    } finally { loading.value = false; }
};
const searchInvoices = async () => {
    if (invoiceSearch.value.trim().length < 2) { invoiceResults.value = []; return; }
    invoiceResults.value = await SalesApi.searchReturnInvoices(invoiceSearch.value.trim());
};
const selectInvoice = async (invoice) => {
    form.sales_voucher_id = invoice.id; form.invoice_number = invoice.invoice_number; form.customer_id = invoice.customer_id || '';
    form.branch_id = invoice.branch_id || ''; form.warehouse_id = invoice.warehouse_id || ''; form.tax_type = invoice.tax_type || 'intrastate';
    form.place_of_supply_state_id = invoice.place_of_supply_state_id || '';
    form.items = await SalesApi.salesReturnItems(invoice.id);
    invoiceSearch.value = invoice.invoice_number; invoiceResults.value = [];
};
const searchProducts = async () => {
    if (productSearch.value.trim().length < 2) { productResults.value = []; return; }
    productResults.value = await SalesApi.searchReturnProducts(productSearch.value.trim(), { branch_id: form.branch_id, warehouse_id: form.warehouse_id });
};
const addProduct = (product) => {
    const batch = (product.batches || [])[0];
    form.items.push({
        sales_item_id: null, product_id: product.id, product: product.name, sku: product.sku, variants: product.variants || [],
        batches: product.batches || [], product_variant_id: '', batch_id: batch?.id || '', unit_id: product.unit_id || '',
        sold_quantity: '-', previously_returned: '-', available_quantity: '-', quantity: 1, selling_rate: product.selling_rate || 0,
        discount_amount: 0, gst_rate: product.gst_rate || 0, cess_rate: product.cess_rate || 0,
        condition_status: 'good', restock_status: 'restock', return_reason: '',
    });
    productSearch.value = ''; productResults.value = [];
};
const addRefund = () => {
    const method = refundMethods.value[0];
    if (!method) return;
    form.refunds.push({ payment_method_id: method.id, amount: totals.value.balance || totals.value.total, refund_date: today, reference_number: '', notes: '' });
};
const removeItem = (index) => form.items.splice(index, 1);
const removeRefund = (index) => form.refunds.splice(index, 1);
const payload = (status) => ({ ...form, status, customer_id: form.customer_id || null, sales_voucher_id: form.return_type === 'against_sale' ? form.sales_voucher_id : null, place_of_supply_state_id: form.place_of_supply_state_id || null });
const saveReturn = async (status = 'draft') => {
    if (saving.value) return;
    saving.value = true; errors.value = {};
    try {
        const response = await SalesApi.saveSalesReturn(payload(status), form.id);
        alert(response.message || 'Sales return saved.');
        if (['confirmed', 'approved'].includes(status)) printCreditNote(response.return);
        reset(); await loadReturns();
    } catch (error) {
        if (error.response?.status === 422) { errors.value = error.response.data.errors || {}; alert(Object.values(errors.value)?.[0]?.[0] || 'Please check return fields.'); return; }
        alert(error.response?.data?.message || 'Sales return save nahi ho saka.');
    } finally { saving.value = false; }
};
const editReturn = (row) => Object.assign(form, { ...row, invoice_number: row.invoice_number || '', items: row.items || [], refunds: row.refunds || [] });
const simpleAction = async (fn, row, promptText) => { if (promptText && !window.confirm(promptText)) return; const response = await fn(row.id); alert(response.message || 'Done.'); await loadReturns(pagination.value.current_page || 1); };
const reverseReturn = async (row) => { const remarks = window.prompt('Reversal remarks'); if (!remarks) return; await simpleAction((id) => SalesApi.reverseSalesReturn(id, remarks), row); };
const printCreditNote = (row) => {
    const html = `<html><head><title>${row.credit_note_number}</title><style>body{font-family:Arial;margin:24px;color:#111}table{width:100%;border-collapse:collapse}td,th{border-bottom:1px solid #ddd;padding:8px;text-align:left}.right{text-align:right}</style></head><body><h2>GST Credit Note</h2><p><b>${row.credit_note_number}</b> | ${row.return_date}</p><p>Invoice: ${row.invoice_number || '-'} | Customer: ${row.customer || '-'}</p><p>Reason: ${row.reason || '-'}</p><table><thead><tr><th>Item</th><th>Qty</th><th>Rate</th><th>GST</th><th>Total</th></tr></thead><tbody>${(row.items || []).map((i) => `<tr><td>${i.product}</td><td>${i.quantity}</td><td>${formatMoney(i.selling_rate)}</td><td>${i.gst_rate}%</td><td>${formatMoney(i.line_total)}</td></tr>`).join('')}</tbody></table><h3 class="right">Credit Total: Rs. ${formatMoney(row.grand_total)}</h3><p>Refund: Rs. ${formatMoney(row.refund_amount)} | Balance/Credit: Rs. ${formatMoney(row.balance_amount)}</p></body></html>`;
    const win = window.open('', '_blank'); win.document.write(html); win.document.close(); win.print();
};
const exportRows = () => {
    const header = ['Credit Note', 'Date', 'Invoice', 'Customer', 'Total', 'Refund', 'Balance', 'Settlement', 'Status'];
    const rows = returns.value.map((r) => [r.credit_note_number, r.return_date, r.invoice_number, r.customer, r.grand_total, r.refund_amount, r.balance_amount, r.settlement_type, r.status]);
    const csv = [header, ...rows].map((row) => row.map((v) => `"${String(v ?? '').replace(/"/g, '""')}"`).join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv' }); const url = URL.createObjectURL(blob); const a = document.createElement('a'); a.href = url; a.download = 'sales-returns.csv'; a.click(); URL.revokeObjectURL(url);
};
const formatMoney = (value) => Number(value || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
onMounted(async () => { await loadReferences(); await loadReturns(); });
</script>

<template>
    <Layout :page="page" :title="title">
        <div class="sales-page">
            <div class="page-heading"><div><span>SALES MANAGEMENT</span><h1>Sales Returns</h1><p>Credit notes, refunds and stock restoration from posted invoices.</p></div><button @click="reset">New Return</button></div>
            <section class="panel">
                <div class="form-grid">
                    <select v-model="form.return_type"><option value="against_sale">Against Sale</option><option value="direct_return">Direct Return</option></select>
                    <select v-model="form.customer_id"><option value="">Customer</option><option v-for="c in references.customers" :key="c.id" :value="c.id">{{ c.customer_name }}</option></select>
                    <select v-model="form.branch_id"><option value="">Branch</option><option v-for="b in references.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select>
                    <select v-model="form.warehouse_id"><option value="">Warehouse</option><option v-for="w in filteredWarehouses" :key="w.id" :value="w.id">{{ w.name }}</option></select>
                    <input v-model="form.return_date" type="date" />
                    <select v-model="form.tax_type"><option value="intrastate">Intrastate</option><option value="interstate">Interstate</option><option value="exempt">Exempt</option><option value="nil_rated">Nil Rated</option></select>
                    <select v-model="form.settlement_type"><option value="customer_credit">Customer Credit</option><option value="cash_refund">Cash Refund</option><option value="bank_refund">Bank Refund</option><option value="upi_refund">UPI Refund</option><option value="card_refund">Card Refund</option><option value="invoice_adjustment">Invoice Adjustment</option><option value="pending">Pending</option></select>
                    <input v-model="form.place_of_supply_state_id" type="number" placeholder="Place of Supply" />
                    <input v-model="form.reason" placeholder="Reason" />
                    <input v-model="form.remarks" placeholder="Remarks" />
                </div>
                <div v-if="form.return_type === 'against_sale'" class="search-box"><input v-model="invoiceSearch" placeholder="Search invoice number, customer, mobile" @input="searchInvoices" /><div v-if="invoiceResults.length" class="search-results"><button v-for="invoice in invoiceResults" :key="invoice.id" @click="selectInvoice(invoice)"><strong>{{ invoice.invoice_number }}</strong><span>{{ invoice.customer }} | {{ invoice.invoice_date }}</span></button></div></div>
                <div v-else class="search-box"><strong class="direct-label">Direct return: not linked to original invoice</strong><input v-model="productSearch" placeholder="Search product by name, SKU or barcode" @input="searchProducts" /><div v-if="productResults.length" class="search-results"><button v-for="product in productResults" :key="product.id" @click="addProduct(product)"><strong>{{ product.name }}</strong><span>{{ product.sku }} | {{ product.barcode || 'No barcode' }}</span></button></div></div>
                <div class="table-wrapper"><table><thead><tr><th>Product</th><th>Variant</th><th>Batch</th><th>Sold</th><th>Returned</th><th>Available</th><th>Qty</th><th>Rate</th><th>Discount</th><th>GST</th><th>Condition</th><th>Restock</th><th>Reason</th><th></th></tr></thead><tbody><tr v-for="(item,index) in form.items" :key="`${item.product_id}-${index}`"><td><strong>{{ item.product }}</strong><span>{{ item.sku }}</span></td><td><span v-if="form.return_type==='against_sale'">{{ item.variant || '-' }}</span><select v-else v-model="item.product_variant_id"><option value="">Default</option><option v-for="v in item.variants || []" :key="v.id" :value="v.id">{{ v.sku || v.barcode }}</option></select></td><td><span v-if="form.return_type==='against_sale'">{{ item.batch || item.batch_id || '-' }}</span><select v-else v-model="item.batch_id"><option value="">Batch</option><option v-for="b in item.batches || []" :key="b.id" :value="b.id">{{ b.batch_no }} | {{ b.expiry_date || '-' }}</option></select></td><td>{{ item.sold_quantity }}</td><td>{{ item.previously_returned }}</td><td>{{ item.available_quantity }}</td><td><input v-model="item.quantity" type="number" step="0.001" /></td><td><input v-model="item.selling_rate" type="number" step="0.01" :disabled="form.return_type==='against_sale'" /></td><td><input v-model="item.discount_amount" type="number" step="0.01" /></td><td><input v-model="item.gst_rate" type="number" step="0.01" :disabled="form.return_type==='against_sale'" /></td><td><select v-model="item.condition_status"><option value="good">Good</option><option value="damaged">Damaged</option><option value="opened">Opened</option><option value="expired">Expired</option><option value="defective">Defective</option></select></td><td><select v-model="item.restock_status"><option value="restock">Restock</option><option value="damaged_stock">Damaged Stock</option><option value="expired_stock">Expired Stock</option><option value="non_restockable">Non Restockable</option></select></td><td><input v-model="item.return_reason" /></td><td><button class="danger" @click="removeItem(index)">Remove</button></td></tr><tr v-if="!form.items.length"><td colspan="14" class="empty">Select an invoice or add direct return products.</td></tr></tbody></table></div>
                <div class="refunds"><button @click="addRefund">Add Refund</button><div v-for="(refund,index) in form.refunds" :key="index"><select v-model="refund.payment_method_id"><option v-for="m in refundMethods" :key="m.id" :value="m.id">{{ m.name }}</option></select><input v-model="refund.amount" type="number" step="0.01" /><input v-model="refund.refund_date" type="date" /><input v-model="refund.reference_number" placeholder="Reference" /><button class="danger" @click="removeRefund(index)">Remove</button></div></div>
                <div class="total-grid"><span>Expected Credit <b>Rs. {{ formatMoney(totals.total) }}</b></span><span>Refund <b>Rs. {{ formatMoney(totals.refund) }}</b></span><span>Balance/Credit <b>Rs. {{ formatMoney(totals.balance) }}</b></span></div>
                <div v-if="Object.keys(errors).length" class="error-box"><span v-for="(messages, field) in errors" :key="field">{{ messages[0] }}</span></div>
                <div class="actions"><button :disabled="saving" @click="saveReturn('draft')">Save Draft</button><button class="primary" :disabled="saving" @click="saveReturn('approved')">{{ saving ? 'Saving...' : 'Approve & Print' }}</button></div>
            </section>
            <section class="panel"><div class="toolbar"><input v-model="filters.date_from" type="date" @change="loadReturns(1)" /><input v-model="filters.date_to" type="date" @change="loadReturns(1)" /><select v-model="filters.status" @change="loadReturns(1)"><option value="">All Status</option><option value="draft">Draft</option><option value="approved">Approved</option><option value="cancelled">Cancelled</option><option value="reversed">Reversed</option></select><select v-model="filters.return_type" @change="loadReturns(1)"><option value="">All Types</option><option value="against_sale">Against Sale</option><option value="direct_return">Direct</option></select><select v-model="filters.settlement_type" @change="loadReturns(1)"><option value="">All Settlements</option><option value="customer_credit">Customer Credit</option><option value="cash_refund">Cash Refund</option><option value="upi_refund">UPI Refund</option><option value="pending">Pending</option></select><button @click="exportRows">Export</button></div><div class="table-wrapper"><table><thead><tr><th>Credit Note</th><th>Date</th><th>Invoice</th><th>Customer</th><th>Branch</th><th>Warehouse</th><th>Type</th><th>Total</th><th>Refund</th><th>Balance</th><th>Settlement</th><th>Status</th><th>Actions</th></tr></thead><tbody><tr v-for="row in returns" :key="row.id"><td>{{ row.credit_note_number }}</td><td>{{ row.return_date }}</td><td>{{ row.invoice_number || '-' }}</td><td>{{ row.customer || '-' }}</td><td>{{ row.branch || '-' }}</td><td>{{ row.warehouse || '-' }}</td><td>{{ row.return_type }}</td><td>Rs. {{ formatMoney(row.grand_total) }}</td><td>Rs. {{ formatMoney(row.refund_amount) }}</td><td>Rs. {{ formatMoney(row.balance_amount) }}</td><td>{{ row.settlement_type }}</td><td><span class="badge" :class="row.status">{{ row.status }}</span></td><td><div class="row-actions"><button @click="printCreditNote(row)">Print</button><button v-if="row.status==='draft'" @click="editReturn(row)">Edit</button><button v-if="row.status==='draft'" @click="simpleAction(SalesApi.approveSalesReturn,row,'Post return?')">Post</button><button v-if="row.status==='draft'" class="danger" @click="simpleAction(SalesApi.cancelSalesReturn,row,'Cancel draft?')">Cancel</button><button v-if="['approved','confirmed'].includes(row.status)" class="danger" @click="reverseReturn(row)">Reverse</button></div></td></tr><tr v-if="!returns.length && !loading"><td colspan="13" class="empty">No sales returns found.</td></tr></tbody></table></div><div class="pagination"><button :disabled="pagination.current_page <= 1" @click="loadReturns(pagination.current_page - 1)">Previous</button><span>{{ pagination.from || 0 }}-{{ pagination.to || 0 }} of {{ pagination.total || 0 }}</span><button :disabled="pagination.current_page >= pagination.last_page" @click="loadReturns(pagination.current_page + 1)">Next</button></div></section>
        </div>
    </Layout>
</template>

<style scoped>
.sales-page{padding:4px 0 28px}.page-heading,.toolbar,.actions,.pagination,.row-actions,.refunds div{display:flex;align-items:center;justify-content:space-between;gap:10px}.page-heading{margin-bottom:18px}.page-heading span{color:#2457d6;font-size:10px;font-weight:800;letter-spacing:1.2px}.page-heading h1{margin:0;color:#142139;font-weight:800}.page-heading p{margin:6px 0 0;color:#758197;font-size:13px}.panel{margin-bottom:18px;padding:18px;background:#fff;border:1px solid #dfe6ef;border-radius:8px}.form-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px}input,select,button{min-height:38px;padding:8px 10px;color:#344159;background:#fff;border:1px solid #d8e0eb;border-radius:8px;font-size:12px}button{font-weight:750;cursor:pointer}.primary{color:#fff;background:#2457d6;border-color:#2457d6}.danger{color:#d23f49;background:#fff3f4;border-color:#ffd6da}.search-box{position:relative;margin:14px 0}.search-box input{width:100%}.direct-label{display:block;margin-bottom:8px;color:#7a5b10;font-size:11px}.search-results{position:absolute;z-index:20;top:44px;left:0;right:0;display:grid;max-height:220px;overflow:auto;background:#fff;border:1px solid #dce4ef;border-radius:9px;box-shadow:0 12px 30px rgba(15,34,66,.12)}.search-results button{display:grid;justify-items:start;border:0;border-bottom:1px solid #eef2f6;border-radius:0}.table-wrapper{overflow-x:auto}table{width:100%;border-collapse:collapse}th{padding:12px 10px;color:#69758a;background:#f8fafc;border-bottom:1px solid #e7ecf2;text-align:left;white-space:nowrap;font-size:10px;font-weight:800;text-transform:uppercase}td{padding:12px 10px;color:#27344c;border-bottom:1px solid #edf1f5;white-space:nowrap;font-size:12px}td input,td select{min-width:84px}td span,.search-results span{display:block;color:#7a869a;font-size:10px}.refunds{display:grid;gap:8px;margin-top:12px}.refunds div{justify-content:flex-start}.total-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:12px}.total-grid span{padding:10px;background:#f8fafc;border:1px solid #e7ecf2;border-radius:8px;color:#69758a;font-size:11px}.total-grid b{display:block;color:#142139;font-size:13px}.actions,.pagination{justify-content:flex-end;margin-top:12px}.toolbar{justify-content:flex-start;margin-bottom:12px;flex-wrap:wrap}.badge{padding:5px 8px;border-radius:7px;background:#edf2ff;color:#2457d6;font-size:10px;font-weight:800;text-transform:capitalize}.badge.approved,.badge.confirmed{color:#168757;background:#eaf8f1}.badge.cancelled,.badge.reversed{color:#d23f49;background:#fff3f4}.empty{padding:28px!important;color:#8490a2;text-align:center}.error-box{display:grid;gap:4px;margin-top:12px;padding:10px;color:#96333a;background:#fff3f4;border:1px solid #ffd4d8;border-radius:8px;font-size:11px}@media(max-width:1100px){.form-grid,.total-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:700px){.page-heading,.toolbar{align-items:stretch;flex-direction:column}.form-grid,.total-grid{grid-template-columns:1fr}}
</style>
