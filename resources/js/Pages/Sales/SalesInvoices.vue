<script setup>
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import Layout from '../Layout.vue';
import SalesApi from './SalesApi';
import RowActionMenu from '../../Components/Common/RowActionMenu.vue';

const props = defineProps({ page: { type: String, default: 'sales' }, title: { type: String, default: 'Sales Invoices' }, endpoints: { type: Object, default: () => ({}) } });
SalesApi.configure(props.endpoints);

const today = new Date().toISOString().slice(0, 10);
const query = () => new URLSearchParams(window.location.search);
const sales = ref([]);
const references = ref({ customers: [], branches: [], warehouses: [], payment_methods: [] });
const products = ref([]);
const productSearch = ref('');
const loading = ref(false);
const saving = ref(false);
const savingAction = ref('');
const openActionMenuId = ref(null);
const viewingSale = ref(null);
const errors = ref({});
const reports = ref({});
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });
const filters = reactive({
    search: query().get('search') || '',
    status: query().get('status') || '',
    payment_status: query().get('payment_status') || '',
    sale_type: query().get('sale_type') || '',
    invoice_type: query().get('invoice_type') || '',
    tax_type: query().get('tax_type') || '',
    branch_id: query().get('branch_id') || '',
    warehouse_id: query().get('warehouse_id') || '',
    customer_id: query().get('customer_id') || '',
    date: query().get('date') || '',
    date_from: query().get('date_from') || '',
    date_to: query().get('date_to') || '',
});
const form = reactive({
    id: null, branch_id: '', warehouse_id: '', customer_id: '', invoice_date: today, due_date: '',
    sale_type: 'cash', invoice_type: 'tax_invoice', tax_type: 'intrastate', place_of_supply_state_id: '',
    voucher_discount_type: '', voucher_discount_value: 0, shipping_amount: 0, other_charges: 0,
    reference_number: '', salesperson_id: '', remarks: '', terms_and_conditions: '', items: [], payments: [],
});

const filteredWarehouses = computed(() => !form.branch_id ? references.value.warehouses || [] : (references.value.warehouses || []).filter((w) => Number(w.branch_id || 0) === Number(form.branch_id)));
const selectedCustomer = computed(() => (references.value.customers || []).find((c) => Number(c.id) === Number(form.customer_id)));
const priceType = computed(() => selectedCustomer.value?.price_type || 'retail');
const defaultPayment = computed(() => (references.value.payment_methods || []).find((p) => p.type === 'cash') || (references.value.payment_methods || [])[0]);
const isEditingDraft = computed(() => Boolean(form.id));
const currentParams = () => Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '' && value !== null && value !== undefined));
const syncUrl = (replace = false) => {
    const params = new URLSearchParams(currentParams());
    const next = params.toString() ? `${window.location.pathname}?${params.toString()}` : window.location.pathname;
    window.history[replace ? 'replaceState' : 'pushState']({}, '', next);
};
const applyFilters = async () => { syncUrl(); await loadSales(1); await loadReports(); };
const clearFilters = async () => {
    Object.assign(filters, { search: '', status: '', payment_status: '', sale_type: '', invoice_type: '', tax_type: '', branch_id: '', warehouse_id: '', customer_id: '', date: '', date_from: '', date_to: '' });
    syncUrl();
    await loadSales(1);
    await loadReports();
};
const fromUrl = () => {
    const params = query();
    Object.keys(filters).forEach((key) => { filters[key] = params.get(key) || ''; });
    loadSales(1);
    loadReports();
};

const line = (item) => {
    const qty = Number(item.quantity || 0);
    const rate = Number(item.selling_rate || 0);
    const gross = qty * rate;
    const discount = item.discount_type === 'percentage' ? gross * Math.min(Number(item.discount_value || 0), 100) / 100 : Math.min(gross, Number(item.discount_value || 0));
    const taxable = Math.max(0, gross - discount);
    const tax = ['exempt', 'nil_rated'].includes(form.tax_type) || form.invoice_type === 'bill_of_supply' ? 0 : taxable * (Number(item.gst_rate || 0) + Number(item.cess_rate || 0)) / 100;
    return { gross, discount, taxable, tax, total: taxable + tax };
};

const totals = computed(() => {
    const base = form.items.reduce((sum, item) => {
        const value = line(item);
        sum.subtotal += value.gross; sum.item_discount += value.discount; sum.taxable += value.taxable; sum.tax += value.tax; return sum;
    }, { subtotal: 0, item_discount: 0, taxable: 0, tax: 0 });
    const voucherDiscount = form.voucher_discount_type === 'percentage' ? base.taxable * Math.min(Number(form.voucher_discount_value || 0), 100) / 100 : Math.min(base.taxable, Number(form.voucher_discount_value || 0));
    const beforeRound = Math.max(0, base.taxable - voucherDiscount) + base.tax + Number(form.shipping_amount || 0) + Number(form.other_charges || 0);
    const grand = Math.round(beforeRound);
    const paid = form.payments.reduce((sum, payment) => sum + Number(payment.amount || 0), 0);
    return { ...base, voucherDiscount, roundOff: grand - beforeRound, grand, paid, balance: Math.max(0, grand - paid), change: Math.max(0, paid - grand) };
});

const reset = () => {
    Object.assign(form, { id: null, branch_id: '', warehouse_id: '', customer_id: '', invoice_date: today, due_date: '', sale_type: 'cash', invoice_type: 'tax_invoice', tax_type: 'intrastate', place_of_supply_state_id: '', voucher_discount_type: '', voucher_discount_value: 0, shipping_amount: 0, other_charges: 0, reference_number: '', salesperson_id: '', remarks: '', terms_and_conditions: '', items: [], payments: [] });
    errors.value = {};
};

const loadReferences = async () => { references.value = await SalesApi.references(); };
const loadSales = async (page = 1) => {
    loading.value = true;
    try {
        const response = await SalesApi.sales({ ...filters, page });
        sales.value = response.sales || [];
        pagination.value = response.pagination || pagination.value;
    } finally { loading.value = false; }
};
const loadReports = async () => { reports.value = await SalesApi.reports(filters); };

const searchProducts = async () => {
    if (productSearch.value.trim().length < 2) { products.value = []; return; }
    products.value = await SalesApi.searchProducts(productSearch.value.trim(), { branch_id: form.branch_id, warehouse_id: form.warehouse_id, price_type: priceType.value });
};

const addProduct = (product) => {
    const existing = form.items.find((item) => item.product_id === product.id && !item.product_variant_id && !item.batch_id);
    if (existing && (productSearch.value === product.barcode || productSearch.value === product.sku)) { existing.quantity = Number(existing.quantity || 0) + 1; products.value = []; productSearch.value = ''; return; }
    const batch = (product.batches || []).find((b) => Number(b.available_stock || 0) > 0);
    form.items.push({
        product_id: product.id, product: product.name, sku: product.sku, barcode: product.barcode,
        variants: product.variants || [], batches: product.batches || [], product_variant_id: '',
        batch_id: batch?.id || '', unit_id: product.unit_id || '', available_stock: batch?.available_stock ?? product.available_stock,
        quantity: 1, free_quantity: 0, selling_rate: product.selling_rate || 0, mrp: product.mrp || '',
        discount_type: '', discount_value: 0, gst_rate: product.gst_rate || 0, cess_rate: product.cess_rate || 0,
        tax_inclusive: product.tax_inclusive, batch_required: product.batch_required, remarks: '',
    });
    productSearch.value = ''; products.value = [];
};

const addPayment = () => {
    if (!defaultPayment.value) return;
    form.payments.push({ payment_method_id: defaultPayment.value.id, amount: totals.value.balance || totals.value.grand, reference_number: '', payment_date: today, notes: '' });
};
const removeItem = (index) => form.items.splice(index, 1);
const removePayment = (index) => form.payments.splice(index, 1);
const payload = (status) => ({ ...form, status, branch_id: form.branch_id || null, warehouse_id: form.warehouse_id || null, customer_id: form.customer_id || null, place_of_supply_state_id: form.place_of_supply_state_id || null });

const saveSale = async (status = 'draft') => {
    if (saving.value) return;
    saving.value = true; savingAction.value = status; errors.value = {};
    try {
        const response = await SalesApi.saveSale(payload(status), form.id);
        alert(response.message || 'Sale saved.');
        if (['confirmed', 'approved'].includes(status)) printSale(response.sale);
        reset(); await loadSales(); await loadReports();
    } catch (error) {
        if (error.response?.status === 422) { errors.value = error.response.data.errors || {}; alert(Object.values(errors.value)?.[0]?.[0] || 'Please check sale fields.'); return; }
        alert(error.response?.data?.message || 'Sale save nahi ho saka.');
    } finally { saving.value = false; savingAction.value = ''; }
};

const editSale = (sale) => {
    Object.assign(form, { ...sale, items: (sale.items || []).map((item) => ({ ...item, product: item.product, variants: [], batches: [] })), payments: sale.payments || [] });
    window.scrollTo({ top: 0, behavior: 'smooth' });
};
const simpleAction = async (fn, row, promptText) => { if (promptText && !window.confirm(promptText)) return; const response = await fn(row.id); alert(response.message || 'Done.'); await loadSales(pagination.value.current_page || 1); await loadReports(); };
const reverseSale = async (row) => { const remarks = window.prompt('Reversal remarks'); if (!remarks) return; await simpleAction((id) => SalesApi.reverseSale(id, remarks), row); };
const toggleActionMenu = (row) => { openActionMenuId.value = openActionMenuId.value === row.id ? null : row.id; };
const closeActionMenu = () => { openActionMenuId.value = null; };
const viewSale = (sale) => {
    viewingSale.value = sale;
    document.body.classList.add('invoice-preview-open');
};
const closeView = () => {
    viewingSale.value = null;
    document.body.classList.remove('invoice-preview-open');
};
const handlePreviewKeydown = (event) => {
    if (event.key === 'Escape' && viewingSale.value) closeView();
};
const printSale = (sale, format = 'a4') => {
    window.open(SalesApi.printUrl(sale.id, format), '_blank', 'noopener');
};
const exportRows = () => { window.location.href = SalesApi.exportUrl(currentParams()); };
const formatMoney = (value) => `₹${Number(value || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
onMounted(async () => { syncUrl(true); window.addEventListener('popstate', fromUrl); window.addEventListener('keydown', handlePreviewKeydown); await loadReferences(); await loadSales(); await loadReports(); });
onUnmounted(() => { window.removeEventListener('popstate', fromUrl); window.removeEventListener('keydown', handlePreviewKeydown); document.body.classList.remove('invoice-preview-open'); });
</script>

<template>
    <Layout :page="props.page" :title="props.title">
        <template #topbar-title>
            <div class="bill-page-title"><span>SALES MANAGEMENT</span><h1>Sales Invoices</h1><p>Draft, hold and post GST invoices with stock ledger integration.</p></div>
        </template>
        <div class="sales-page">
            <div v-if="isEditingDraft" class="edit-banner">
                <strong>Editing {{ form.invoice_number || 'draft invoice' }}</strong>
                <span>Changes can be saved while invoice status is draft or held. Posted invoices should be reversed and copied.</span>
            </div>
            <section class="metrics"><div><span>Outstanding</span><strong>{{ formatMoney(reports.outstanding) }}</strong></div><div><span>Cancelled</span><strong>{{ reports.cancelled || 0 }}</strong></div><div><span>Today Total</span><strong>{{ formatMoney(reports.today_total) }}</strong></div><div><span>Draft / Held</span><strong>{{ reports.draft_count || 0 }} / {{ reports.held_count || 0 }}</strong></div></section>
            <section class="panel">
                <div class="toolbar">
                    <input v-model="filters.search" placeholder="Search invoice, customer, mobile, GSTIN" @keyup.enter="applyFilters" />
                    <input v-model="filters.date_from" type="date" />
                    <input v-model="filters.date_to" type="date" />
                    <select v-model="filters.branch_id"><option value="">All Branches</option><option v-for="b in references.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select>
                    <select v-model="filters.warehouse_id"><option value="">All Warehouses</option><option v-for="w in references.warehouses" :key="w.id" :value="w.id">{{ w.name }}</option></select>
                    <select v-model="filters.status"><option value="">All Status</option><option value="draft">Draft</option><option value="hold">Held</option><option value="approved">Posted</option><option value="cancelled">Cancelled</option><option value="reversed">Reversed</option></select>
                    <select v-model="filters.payment_status"><option value="">All Payments</option><option value="unpaid">Unpaid</option><option value="partial">Partial</option><option value="paid">Paid</option><option value="unpaid,partial">Outstanding</option><option value="overpaid">Overpaid</option></select>
                    <select v-model="filters.invoice_type"><option value="">All Types</option><option value="tax_invoice">Tax Invoice</option><option value="bill_of_supply">Bill of Supply</option><option value="retail_invoice">Retail Invoice</option></select>
                    <button class="primary" @click="applyFilters">Apply</button><button @click="clearFilters">Clear Filters</button><button @click="exportRows">Export</button>
                </div>
                <div class="table-wrapper"><table><thead><tr><th>Invoice</th><th>Date</th><th>Customer</th><th>Mobile</th><th>Branch</th><th>Warehouse</th><th>Type</th><th>Total</th><th>Paid</th><th>Balance</th><th>Payment</th><th>Status</th><th>Actions</th></tr></thead><tbody><tr v-for="row in sales" :key="row.id"><td>{{ row.invoice_number }}</td><td>{{ row.invoice_date }}</td><td>{{ row.customer }}</td><td>{{ row.customer_mobile || '-' }}</td><td>{{ row.branch || '-' }}</td><td>{{ row.warehouse || '-' }}</td><td>{{ row.invoice_type }}</td><td>{{ formatMoney(row.grand_total) }}</td><td>{{ formatMoney(row.paid_amount) }}</td><td>{{ formatMoney(row.balance_amount) }}</td><td>{{ row.payment_status }}</td><td><span class="badge" :class="row.status">{{ row.status }}</span></td><td><div class="row-actions"><RowActionMenu :open="openActionMenuId === row.id" :show-view="false" more-label="Actions" more-title="Invoice actions" placement="top" @toggle="toggleActionMenu(row)"><button type="button" @click="viewSale(row); closeActionMenu()">View</button><button type="button" @click="printSale(row); closeActionMenu()">Print A4</button><button type="button" @click="printSale(row, 'thermal'); closeActionMenu()">Print 80mm</button><button v-if="['draft','hold'].includes(row.status)" type="button" @click="editSale(row); closeActionMenu()">Edit</button><button v-if="['draft','hold'].includes(row.status)" type="button" @click="simpleAction(SalesApi.approveSale,row,'Post invoice?'); closeActionMenu()">Post</button><button type="button" @click="simpleAction(SalesApi.duplicateSale,row); closeActionMenu()">Copy</button><button v-if="['draft','hold'].includes(row.status)" type="button" class="danger" @click="simpleAction(SalesApi.cancelSale,row,'Cancel invoice?'); closeActionMenu()">Cancel</button><button v-if="['approved','confirmed'].includes(row.status)" type="button" class="danger" @click="reverseSale(row); closeActionMenu()">Reverse</button></RowActionMenu></div></td></tr><tr v-if="!sales.length && !loading"><td colspan="13" class="empty">No sales invoices found for the selected filters.</td></tr></tbody></table></div>
                <div class="pagination"><button :disabled="pagination.current_page <= 1" @click="loadSales(pagination.current_page - 1)">Previous</button><span>{{ pagination.from || 0 }}-{{ pagination.to || 0 }} of {{ pagination.total || 0 }}</span><button :disabled="pagination.current_page >= pagination.last_page" @click="loadSales(pagination.current_page + 1)">Next</button></div>
            </section>
            <div v-if="viewingSale" class="invoice-modal-backdrop" @click.self="closeView">
                <button type="button" class="invoice-floating-close" title="Close invoice preview" @click="closeView">Close</button>
                <section class="invoice-modal invoice-modal-full" role="dialog" aria-modal="true" :aria-label="`Invoice ${viewingSale.invoice_number}`">
                    <header class="invoice-modal-head">
                        <div class="invoice-modal-title">
                            <span>INVOICE PREVIEW</span>
                            <h2>{{ viewingSale.invoice_number }}</h2>
                            <p>{{ viewingSale.invoice_date || '-' }} | {{ viewingSale.branch || 'Default Branch' }}</p>
                        </div>
                        <div class="invoice-modal-actions">
                            <button type="button" class="primary" @click="printSale(viewingSale)">Print A4</button>
                            <button type="button" @click="printSale(viewingSale, 'thermal')">Print 80mm</button>
                            <button type="button" class="invoice-close" title="Close invoice preview" @click="closeView">Close</button>
                        </div>
                    </header>
                    <div class="invoice-modal-summary invoice-modal-summary-compact">
                        <div class="summary-main">
                            <span>Customer</span>
                            <strong>{{ viewingSale.customer || 'Walk-in Customer' }}</strong>
                            <small>{{ viewingSale.customer_mobile || 'No mobile number' }}</small>
                        </div>
                        <div>
                            <span>Total</span>
                            <strong>{{ formatMoney(viewingSale.grand_total) }}</strong>
                            <small>Paid {{ formatMoney(viewingSale.paid_amount) }}</small>
                        </div>
                        <div>
                            <span>Balance</span>
                            <strong>{{ formatMoney(viewingSale.balance_amount) }}</strong>
                            <small>{{ viewingSale.payment_status || 'payment status' }}</small>
                        </div>
                        <div>
                            <span>Status</span>
                            <strong><span class="badge" :class="viewingSale.status">{{ viewingSale.status }}</span></strong>
                            <small>{{ viewingSale.invoice_type || 'Invoice' }}</small>
                        </div>
                    </div>
                    <div class="invoice-preview-frame invoice-preview-full">
                        <iframe :src="SalesApi.printUrl(viewingSale.id, 'a4')" :title="`Invoice ${viewingSale.invoice_number}`"></iframe>
                    </div>
                </section>
            </div>
        </div>
    </Layout>
</template>

<style scoped>
.sales-page{padding:4px 0 28px}.page-heading,.toolbar,.actions,.pagination,.row-actions,.payments div,.metrics,.edit-banner{display:flex;align-items:center;justify-content:space-between;gap:10px}.edit-banner{margin-bottom:14px;padding:12px 14px;background:#fffaf0;border:1px solid #f1d898;border-radius:8px;color:#465269}.edit-banner strong{color:#142139;font-size:13px}.edit-banner span{flex:1;color:#69758a;font-size:12px}.page-heading{margin-bottom:18px}.page-heading span{color:#2457d6;font-size:10px;font-weight:800;letter-spacing:1.2px}.page-heading h1{margin:0;color:#142139;font-weight:800}.page-heading p{margin:6px 0 0;color:#758197;font-size:13px}.metrics{margin-bottom:14px}.metrics div{flex:1;padding:12px;background:#fff;border:1px solid #dfe6ef;border-radius:8px}.metrics span{color:#69758a;font-size:11px}.metrics strong{display:block;color:#142139}.panel{margin-bottom:18px;padding:18px;background:#fff;border:1px solid #dfe6ef;border-radius:8px}.form-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px}input,select,button{min-height:38px;padding:8px 10px;color:#344159;background:#fff;border:1px solid #d8e0eb;border-radius:8px;font-size:12px}button{font-weight:750;cursor:pointer}.primary{color:#fff;background:#2457d6;border-color:#2457d6;box-shadow:0 6px 14px rgba(36,87,214,.16)}.danger{color:#d23f49;background:#fff3f4;border-color:#ffd6da}.product-search{position:relative;margin:14px 0}.product-search input{width:100%}.search-results{position:absolute;z-index:20;top:44px;left:0;right:0;display:grid;max-height:220px;overflow:auto;background:#fff;border:1px solid #dce4ef;border-radius:9px;box-shadow:0 12px 30px rgba(15,34,66,.12)}.search-results button{display:grid;justify-items:start;border:0;border-bottom:1px solid #eef2f6;border-radius:0}.table-wrapper{overflow-x:auto}table{width:100%;border-collapse:collapse}th{padding:12px 10px;color:#69758a;background:#f8fafc;border-bottom:1px solid #e7ecf2;text-align:left;white-space:nowrap;font-size:10px;font-weight:800;text-transform:uppercase}td{padding:12px 10px;color:#27344c;border-bottom:1px solid #edf1f5;white-space:nowrap;font-size:12px}td input,td select{min-width:84px}td span,.search-results span{display:block;color:#7a869a;font-size:10px}.payments{display:grid;gap:8px;margin-top:12px}.payments div{justify-content:flex-start}.total-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-top:12px}.total-grid span{padding:10px;background:#f8fafc;border:1px solid #e7ecf2;border-radius:8px;color:#69758a;font-size:11px}.total-grid b{display:block;color:#142139;font-size:13px}.actions,.pagination{justify-content:flex-end;margin-top:12px}.toolbar{justify-content:flex-start;margin-bottom:12px;flex-wrap:wrap}.badge{padding:5px 8px;border-radius:7px;background:#edf2ff;color:#2457d6;font-size:10px;font-weight:800;text-transform:capitalize}.badge.approved,.badge.confirmed{color:#168757;background:#eaf8f1}.badge.cancelled,.badge.reversed{color:#d23f49;background:#fff3f4}.empty{padding:28px!important;color:#8490a2;text-align:center}.error-box{display:grid;gap:4px;margin-top:12px;padding:10px;color:#96333a;background:#fff3f4;border:1px solid #ffd4d8;border-radius:8px;font-size:11px}.invoice-modal-backdrop{position:fixed;inset:0;z-index:1200;display:grid;place-items:center;padding:18px;background:rgba(15,23,42,.54)}.invoice-modal{display:grid;grid-template-rows:auto minmax(0,1fr);width:min(1120px,96vw);height:min(860px,92vh);overflow:hidden;background:#fff;border:1px solid #d8e0eb;border-radius:8px;box-shadow:0 24px 60px rgba(15,23,42,.28)}.invoice-modal header{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 14px;background:#f8fafc;border-bottom:1px solid #e4eaf2}.invoice-modal header span{color:#2457d6;font-size:10px;font-weight:850;letter-spacing:1px}.invoice-modal header h2{margin:2px 0 0;color:#142139;font-size:16px}.invoice-modal-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.invoice-modal iframe{width:100%;height:100%;border:0;background:#fff}@media(max-width:1100px){.form-grid,.total-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:700px){.page-heading,.toolbar,.metrics,.edit-banner,.invoice-modal header{align-items:stretch;flex-direction:column}.form-grid,.total-grid{grid-template-columns:1fr}.invoice-modal-backdrop{padding:8px}.invoice-modal{width:100vw;height:100vh;border-radius:0}.invoice-modal-actions button{width:100%}}
.invoice-modal-full{grid-template-rows:auto auto minmax(0,1fr);width:100vw;height:100vh;background:#f3f6fa;border:0;border-radius:0}.invoice-modal-head{background:#fff!important;padding:10px 18px!important}.invoice-modal-title{min-width:0}.invoice-modal-title span{display:block}.invoice-modal-title h2{font-size:18px!important;line-height:1.1}.invoice-modal-title p{margin:3px 0 0;color:#69758a;font-size:12px;font-weight:750}.invoice-modal-actions button{min-height:34px;padding:7px 12px;border-radius:8px}.invoice-modal-actions .invoice-close{color:#d23f49;background:#fff3f4;border-color:#ffd6da}.invoice-modal-summary-compact{display:grid;grid-template-columns:minmax(260px,1.8fr) repeat(3,minmax(140px,1fr));gap:8px;padding:8px 18px;background:#eef3f9;border-bottom:1px solid #dfe6ef}.invoice-modal-summary-compact>div{min-width:0;padding:8px 10px;background:#fff;border:1px solid #dfe6ef;border-radius:8px}.invoice-modal-summary-compact span{display:block;color:#69758a;font-size:9px;font-weight:850;text-transform:uppercase}.invoice-modal-summary-compact strong{display:block;margin-top:3px;color:#142139;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.invoice-modal-summary-compact small{display:block;margin-top:2px;color:#7a869a;font-size:10px;font-weight:750;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.invoice-modal-summary-compact .badge{display:inline-flex;margin-top:1px}.invoice-preview-full{min-height:0;margin:0;overflow:hidden;background:#e8edf4;border:0;border-radius:0;box-shadow:none}.invoice-preview-full iframe{display:block;width:100%;height:100%;border:0;background:#fff}@media(max-width:900px){.invoice-modal-summary-compact{grid-template-columns:repeat(2,minmax(0,1fr));padding:8px 10px}}@media(max-width:700px){.invoice-modal-title h2{font-size:16px!important}.invoice-modal-summary-compact{grid-template-columns:1fr;padding:8px}.invoice-modal-actions{display:grid;grid-template-columns:1fr 1fr 1fr}.invoice-modal-actions button{width:100%;padding:8px 6px}.invoice-preview-full{min-height:0}}
:global(body.invoice-preview-open){overflow:hidden}.invoice-modal-backdrop{display:flex!important;align-items:stretch!important;justify-content:center!important;padding:0!important;background:#eef3f9!important}.invoice-modal.invoice-modal-full{position:relative!important;display:flex!important;flex-direction:column!important;width:100vw!important;height:100dvh!important;max-height:100dvh!important;overflow:hidden!important;background:#f3f6fa!important}.invoice-modal-head{flex:0 0 auto}.invoice-modal-summary-compact{flex:0 0 auto}.invoice-preview-full{flex:1 1 auto!important;min-height:0!important;overflow:auto!important;-webkit-overflow-scrolling:touch!important;padding:18px!important}.invoice-preview-full iframe{display:block!important;width:min(100%,1120px)!important;min-height:calc(100dvh - 170px)!important;height:1280px!important;margin:0 auto!important;background:#fff!important;border:1px solid #d8e0eb!important;border-radius:8px!important;box-shadow:0 14px 34px rgba(15,23,42,.12)!important}@media(max-width:700px){.invoice-modal-actions{grid-template-columns:1fr 1fr auto}.invoice-preview-full{padding:8px!important}.invoice-preview-full iframe{width:100%!important;height:1180px!important;border-radius:0!important}}
.invoice-floating-close{position:fixed;top:14px;right:18px;z-index:1305;min-height:36px;padding:8px 13px;color:#fff;background:#d23f49;border-color:#d23f49;box-shadow:0 12px 28px rgba(15,23,42,.22)}
</style>
