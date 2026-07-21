<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import Layout from '../Layout.vue';
import SalesApi from './SalesApi';

defineProps({ page: { type: String, default: 'sales' }, title: { type: String, default: 'Sales Invoices' } });

const today = new Date().toISOString().slice(0, 10);
const sales = ref([]);
const references = ref({ customers: [], branches: [], warehouses: [], payment_methods: [] });
const products = ref([]);
const productSearch = ref('');
const loading = ref(false);
const saving = ref(false);
const errors = ref({});
const reports = ref({});
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });
const filters = reactive({ status: '', payment_status: '', sale_type: '', invoice_type: '', tax_type: '', date_from: '', date_to: '' });
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
    saving.value = true; errors.value = {};
    try {
        const response = await SalesApi.saveSale(payload(status), form.id);
        alert(response.message || 'Sale saved.');
        if (['confirmed', 'approved'].includes(status)) printSale(response.sale);
        reset(); await loadSales(); await loadReports();
    } catch (error) {
        if (error.response?.status === 422) { errors.value = error.response.data.errors || {}; alert(Object.values(errors.value)?.[0]?.[0] || 'Please check sale fields.'); return; }
        alert(error.response?.data?.message || 'Sale save nahi ho saka.');
    } finally { saving.value = false; }
};

const editSale = (sale) => {
    Object.assign(form, { ...sale, items: (sale.items || []).map((item) => ({ ...item, product: item.product, variants: [], batches: [] })), payments: sale.payments || [] });
};
const simpleAction = async (fn, row, promptText) => { if (promptText && !window.confirm(promptText)) return; const response = await fn(row.id); alert(response.message || 'Done.'); await loadSales(pagination.value.current_page || 1); await loadReports(); };
const reverseSale = async (row) => { const remarks = window.prompt('Reversal remarks'); if (!remarks) return; await simpleAction((id) => SalesApi.reverseSale(id, remarks), row); };
const printSale = (sale) => {
    const html = `<html><head><title>${sale.invoice_number}</title><style>body{font-family:Arial;margin:24px;color:#111}table{width:100%;border-collapse:collapse}td,th{border-bottom:1px solid #ddd;padding:8px;text-align:left}.right{text-align:right}</style></head><body><h2>Tax Invoice</h2><p><b>${sale.invoice_number}</b> | ${sale.invoice_date}</p><p>${sale.customer || 'Walk-in Customer'} ${sale.customer_mobile || ''}</p><table><thead><tr><th>Item</th><th>HSN</th><th class="right">Qty</th><th class="right">Rate</th><th class="right">Total</th></tr></thead><tbody>${(sale.items || []).map((i) => `<tr><td>${i.product}</td><td>${i.hsn_code_snapshot || ''}</td><td class="right">${i.quantity}</td><td class="right">${formatMoney(i.selling_rate)}</td><td class="right">${formatMoney(i.line_total)}</td></tr>`).join('')}</tbody></table><h3 class="right">Grand Total: Rs. ${formatMoney(sale.grand_total)}</h3><p>Paid: Rs. ${formatMoney(sale.paid_amount)} | Balance: Rs. ${formatMoney(sale.balance_amount)}</p></body></html>`;
    const win = window.open('', '_blank'); win.document.write(html); win.document.close(); win.print();
};
const exportRows = () => {
    const header = ['Invoice', 'Date', 'Customer', 'Grand Total', 'Paid', 'Balance', 'Payment', 'Status'];
    const rows = sales.value.map((s) => [s.invoice_number, s.invoice_date, s.customer, s.grand_total, s.paid_amount, s.balance_amount, s.payment_status, s.status]);
    const csv = [header, ...rows].map((row) => row.map((v) => `"${String(v ?? '').replace(/"/g, '""')}"`).join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv' }); const url = URL.createObjectURL(blob); const a = document.createElement('a'); a.href = url; a.download = 'sales-invoices.csv'; a.click(); URL.revokeObjectURL(url);
};
const formatMoney = (value) => Number(value || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
onMounted(async () => { await loadReferences(); await loadSales(); await loadReports(); });
</script>

<template>
    <Layout :page="page" :title="title">
        <div class="sales-page">
            <div class="page-heading"><div><span>SALES MANAGEMENT</span><h1>Sales Invoices</h1><p>Draft, hold and post GST invoices with stock ledger integration.</p></div><button @click="reset">New Sale</button></div>
            <section class="metrics"><div><span>Outstanding</span><strong>Rs. {{ formatMoney(reports.outstanding) }}</strong></div><div><span>Cancelled</span><strong>{{ reports.cancelled || 0 }}</strong></div><div><span>Today Total</span><strong>Rs. {{ formatMoney((reports.daily_sales || [])[0]?.total) }}</strong></div></section>
            <section class="panel">
                <div class="form-grid">
                    <select v-model="form.branch_id"><option value="">Branch</option><option v-for="b in references.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select>
                    <select v-model="form.warehouse_id"><option value="">Warehouse</option><option v-for="w in filteredWarehouses" :key="w.id" :value="w.id">{{ w.name }}</option></select>
                    <select v-model="form.customer_id"><option value="">Walk-in Customer</option><option v-for="c in references.customers" :key="c.id" :value="c.id">{{ c.customer_name }} | {{ c.mobile || c.customer_code }}</option></select>
                    <input v-model="form.invoice_date" type="date" /><input v-model="form.due_date" type="date" />
                    <select v-model="form.sale_type"><option value="cash">Cash</option><option value="credit">Credit</option></select>
                    <select v-model="form.invoice_type"><option value="tax_invoice">Tax Invoice</option><option value="bill_of_supply">Bill of Supply</option><option value="retail_invoice">Retail Invoice</option></select>
                    <select v-model="form.tax_type"><option value="intrastate">Intrastate</option><option value="interstate">Interstate</option><option value="exempt">Exempt</option><option value="nil_rated">Nil Rated</option></select>
                    <input v-model="form.place_of_supply_state_id" type="number" placeholder="Place of Supply State" />
                    <select v-model="form.voucher_discount_type"><option value="">No Voucher Discount</option><option value="percentage">%</option><option value="amount">Amount</option></select>
                    <input v-model="form.voucher_discount_value" type="number" placeholder="Voucher Discount" />
                    <input v-model="form.shipping_amount" type="number" placeholder="Shipping" /><input v-model="form.other_charges" type="number" placeholder="Other Charges" /><input v-model="form.reference_number" placeholder="Reference No" /><input v-model="form.remarks" placeholder="Remarks" />
                </div>
                <div class="product-search"><input v-model="productSearch" placeholder="Scan barcode or search product / SKU" @keyup.enter="products[0] && addProduct(products[0])" @input="searchProducts" /><div v-if="products.length" class="search-results"><button v-for="product in products" :key="product.id" @click="addProduct(product)"><strong>{{ product.name }}</strong><span>{{ product.sku }} | {{ product.barcode || 'No barcode' }} | Stock {{ product.available_stock ?? 'Service' }}</span></button></div></div>
                <div class="table-wrapper"><table><thead><tr><th>Product</th><th>Variant</th><th>Batch</th><th>Stock</th><th>Qty</th><th>Free</th><th>Rate</th><th>MRP</th><th>Disc</th><th>GST</th><th>Total</th><th></th></tr></thead><tbody><tr v-for="(item,index) in form.items" :key="`${item.product_id}-${index}`"><td><strong>{{ item.product }}</strong><span>{{ item.sku }}</span></td><td><select v-model="item.product_variant_id"><option value="">Default</option><option v-for="v in item.variants" :key="v.id" :value="v.id">{{ v.sku || v.barcode }}</option></select></td><td><select v-model="item.batch_id"><option value="">Batch</option><option v-for="b in item.batches" :key="b.id" :value="b.id">{{ b.batch_no }} | {{ b.expiry_date || '-' }} | {{ b.available_stock }}</option></select></td><td>{{ item.available_stock ?? '-' }}</td><td><input v-model="item.quantity" type="number" step="0.001" /></td><td><input v-model="item.free_quantity" type="number" step="0.001" /></td><td><input v-model="item.selling_rate" type="number" step="0.01" /></td><td><input v-model="item.mrp" type="number" step="0.01" /></td><td><select v-model="item.discount_type"><option value="">No</option><option value="percentage">%</option><option value="amount">Amt</option></select><input v-model="item.discount_value" type="number" step="0.01" /></td><td><input v-model="item.gst_rate" type="number" step="0.01" /></td><td>Rs. {{ formatMoney(line(item).total) }}</td><td><button class="danger" @click="removeItem(index)">Remove</button></td></tr><tr v-if="!form.items.length"><td colspan="12" class="empty">Search or scan products to add invoice lines.</td></tr></tbody></table></div>
                <div class="payments"><button @click="addPayment">Add Payment</button><div v-for="(payment,index) in form.payments" :key="index"><select v-model="payment.payment_method_id"><option v-for="m in references.payment_methods" :key="m.id" :value="m.id">{{ m.name }}</option></select><input v-model="payment.amount" type="number" step="0.01" /><input v-model="payment.reference_number" placeholder="Payment Ref" /><button class="danger" @click="removePayment(index)">Remove</button></div></div>
                <div class="total-grid"><span>Subtotal <b>Rs. {{ formatMoney(totals.subtotal) }}</b></span><span>Discount <b>Rs. {{ formatMoney(totals.item_discount + totals.voucherDiscount) }}</b></span><span>Tax <b>Rs. {{ formatMoney(totals.tax) }}</b></span><span>Round Off <b>Rs. {{ formatMoney(totals.roundOff) }}</b></span><span>Grand <b>Rs. {{ formatMoney(totals.grand) }}</b></span><span>Paid <b>Rs. {{ formatMoney(totals.paid) }}</b></span><span>Balance <b>Rs. {{ formatMoney(totals.balance) }}</b></span><span>Change <b>Rs. {{ formatMoney(totals.change) }}</b></span></div>
                <div v-if="Object.keys(errors).length" class="error-box"><span v-for="(messages, field) in errors" :key="field">{{ messages[0] }}</span></div>
                <div class="actions"><button :disabled="saving" @click="saveSale('draft')">Save Draft</button><button :disabled="saving" @click="saveSale('hold')">Hold</button><button class="primary" :disabled="saving" @click="saveSale('approved')">{{ saving ? 'Saving...' : 'Confirm & Print' }}</button></div>
            </section>
            <section class="panel"><div class="toolbar"><input v-model="filters.date_from" type="date" @change="loadSales(1)" /><input v-model="filters.date_to" type="date" @change="loadSales(1)" /><select v-model="filters.status" @change="loadSales(1)"><option value="">All Status</option><option value="draft">Draft</option><option value="hold">Held</option><option value="approved">Approved</option><option value="cancelled">Cancelled</option><option value="reversed">Reversed</option></select><select v-model="filters.payment_status" @change="loadSales(1)"><option value="">All Payments</option><option value="unpaid">Unpaid</option><option value="partial">Partial</option><option value="paid">Paid</option><option value="overpaid">Overpaid</option></select><button @click="exportRows">Export</button></div><div class="table-wrapper"><table><thead><tr><th>Invoice</th><th>Date</th><th>Customer</th><th>Mobile</th><th>Branch</th><th>Warehouse</th><th>Type</th><th>Total</th><th>Paid</th><th>Balance</th><th>Payment</th><th>Status</th><th>Actions</th></tr></thead><tbody><tr v-for="row in sales" :key="row.id"><td>{{ row.invoice_number }}</td><td>{{ row.invoice_date }}</td><td>{{ row.customer }}</td><td>{{ row.customer_mobile || '-' }}</td><td>{{ row.branch || '-' }}</td><td>{{ row.warehouse || '-' }}</td><td>{{ row.invoice_type }}</td><td>Rs. {{ formatMoney(row.grand_total) }}</td><td>Rs. {{ formatMoney(row.paid_amount) }}</td><td>Rs. {{ formatMoney(row.balance_amount) }}</td><td>{{ row.payment_status }}</td><td><span class="badge" :class="row.status">{{ row.status }}</span></td><td><div class="row-actions"><button @click="printSale(row)">Print</button><button v-if="['draft','hold'].includes(row.status)" @click="editSale(row)">Edit</button><button v-if="['draft','hold'].includes(row.status)" @click="simpleAction(SalesApi.approveSale,row,'Post invoice?')">Post</button><button @click="simpleAction(SalesApi.duplicateSale,row)">Copy</button><button v-if="['draft','hold'].includes(row.status)" class="danger" @click="simpleAction(SalesApi.cancelSale,row,'Cancel invoice?')">Cancel</button><button v-if="['approved','confirmed'].includes(row.status)" class="danger" @click="reverseSale(row)">Reverse</button></div></td></tr><tr v-if="!sales.length && !loading"><td colspan="13" class="empty">No sales invoices found.</td></tr></tbody></table></div><div class="pagination"><button :disabled="pagination.current_page <= 1" @click="loadSales(pagination.current_page - 1)">Previous</button><span>{{ pagination.from || 0 }}-{{ pagination.to || 0 }} of {{ pagination.total || 0 }}</span><button :disabled="pagination.current_page >= pagination.last_page" @click="loadSales(pagination.current_page + 1)">Next</button></div></section>
        </div>
    </Layout>
</template>

<style scoped>
.sales-page{padding:4px 0 28px}.page-heading,.toolbar,.actions,.pagination,.row-actions,.payments div,.metrics{display:flex;align-items:center;justify-content:space-between;gap:10px}.page-heading{margin-bottom:18px}.page-heading span{color:#2457d6;font-size:10px;font-weight:800;letter-spacing:1.2px}.page-heading h1{margin:0;color:#142139;font-weight:800}.page-heading p{margin:6px 0 0;color:#758197;font-size:13px}.metrics{margin-bottom:14px}.metrics div{flex:1;padding:12px;background:#fff;border:1px solid #dfe6ef;border-radius:8px}.metrics span{color:#69758a;font-size:11px}.metrics strong{display:block;color:#142139}.panel{margin-bottom:18px;padding:18px;background:#fff;border:1px solid #dfe6ef;border-radius:8px}.form-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px}input,select,button{min-height:38px;padding:8px 10px;color:#344159;background:#fff;border:1px solid #d8e0eb;border-radius:8px;font-size:12px}button{font-weight:750;cursor:pointer}.primary{color:#fff;background:#2457d6;border-color:#2457d6}.danger{color:#d23f49;background:#fff3f4;border-color:#ffd6da}.product-search{position:relative;margin:14px 0}.product-search input{width:100%}.search-results{position:absolute;z-index:20;top:44px;left:0;right:0;display:grid;max-height:220px;overflow:auto;background:#fff;border:1px solid #dce4ef;border-radius:9px;box-shadow:0 12px 30px rgba(15,34,66,.12)}.search-results button{display:grid;justify-items:start;border:0;border-bottom:1px solid #eef2f6;border-radius:0}.table-wrapper{overflow-x:auto}table{width:100%;border-collapse:collapse}th{padding:12px 10px;color:#69758a;background:#f8fafc;border-bottom:1px solid #e7ecf2;text-align:left;white-space:nowrap;font-size:10px;font-weight:800;text-transform:uppercase}td{padding:12px 10px;color:#27344c;border-bottom:1px solid #edf1f5;white-space:nowrap;font-size:12px}td input,td select{min-width:84px}td span,.search-results span{display:block;color:#7a869a;font-size:10px}.payments{display:grid;gap:8px;margin-top:12px}.payments div{justify-content:flex-start}.total-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-top:12px}.total-grid span{padding:10px;background:#f8fafc;border:1px solid #e7ecf2;border-radius:8px;color:#69758a;font-size:11px}.total-grid b{display:block;color:#142139;font-size:13px}.actions,.pagination{justify-content:flex-end;margin-top:12px}.toolbar{justify-content:flex-start;margin-bottom:12px;flex-wrap:wrap}.badge{padding:5px 8px;border-radius:7px;background:#edf2ff;color:#2457d6;font-size:10px;font-weight:800;text-transform:capitalize}.badge.approved,.badge.confirmed{color:#168757;background:#eaf8f1}.badge.cancelled,.badge.reversed{color:#d23f49;background:#fff3f4}.empty{padding:28px!important;color:#8490a2;text-align:center}.error-box{display:grid;gap:4px;margin-top:12px;padding:10px;color:#96333a;background:#fff3f4;border:1px solid #ffd4d8;border-radius:8px;font-size:11px}@media(max-width:1100px){.form-grid,.total-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:700px){.page-heading,.toolbar,.metrics{align-items:stretch;flex-direction:column}.form-grid,.total-grid{grid-template-columns:1fr}}
</style>
