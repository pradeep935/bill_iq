<script setup>
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import Layout from './Layout.vue';
import SalesApi from './Sales/SalesApi';
import ActionFooter from '@/Components/Billing/ActionFooter.vue';
import CustomerSelector from '@/Components/Billing/CustomerSelector.vue';
import FilterCard from '@/Components/Billing/FilterCard.vue';
import InvoiceHeader from '@/Components/Billing/InvoiceHeader.vue';
import PaymentPanel from '@/Components/Billing/PaymentPanel.vue';
import ProductTable from '@/Components/Billing/ProductTable.vue';
import SummaryCard from '@/Components/Billing/SummaryCard.vue';

const props = defineProps({
    page: { type: String, default: 'pos' },
    title: { type: String, default: 'POS Billing' },
    endpoints: { type: Object, default: () => ({}) },
    context: { type: Object, default: () => ({}) },
    pos: { type: Object, default: () => ({ categories: [], recent_products: [], held_bills: [] }) },
});

const today = new Date().toISOString().slice(0, 10);
const scanInput = ref(null);
const customerSelect = ref(null);
const search = ref('');
const productResults = ref([]);
const references = ref({ customers: [], branches: [], warehouses: [], payment_methods: [] });
const heldBills = ref([...(props.pos.held_bills || [])]);
const recentProducts = ref([...(props.pos.recent_products || [])]);
const activeCategory = ref('');
const paymentMode = ref('cash');
const saving = ref(false);
const savingAction = ref('');
const message = ref('');
const lastSale = ref(null);
const invoiceStatus = ref('draft');

const form = reactive({
    id: null,
    branch_id: props.context?.branch?.id || '',
    warehouse_id: '',
    customer_id: '',
    invoice_date: today,
    sale_type: 'cash',
    invoice_type: 'retail_invoice',
    tax_type: 'intrastate',
    voucher_discount_type: '',
    voucher_discount_value: '',
    shipping_amount: '',
    other_charges: '',
    remarks: '',
    items: [],
    payments: [],
});

const categories = computed(() => props.pos.categories || []);
const contextSettings = computed(() => props.context?.settings || {});
const currencySymbol = computed(() => contextSettings.value.currency_symbol || 'Rs. ');
const customers = computed(() => references.value.customers || []);
const paymentMethods = computed(() => references.value.payment_methods || []);
const selectedCustomer = computed(() => customers.value.find((customer) => Number(customer.id) === Number(form.customer_id)));
const priceType = computed(() => selectedCustomer.value?.price_type || 'retail');
const filteredWarehouses = computed(() => {
    const all = references.value.warehouses || [];
    return form.branch_id ? all.filter((warehouse) => Number(warehouse.branch_id || 0) === Number(form.branch_id)) : all;
});
const methodByType = (type) => paymentMethods.value.find((method) => method.type === type) || null;

const line = (item) => {
    const quantity = Number(item.quantity || 0);
    const gross = quantity * Number(item.selling_rate || 0);
    const discount = item.discount_type === 'percentage'
        ? gross * Math.min(Number(item.discount_value || 0), 100) / 100
        : Math.min(gross, Number(item.discount_value || 0));
    const taxable = Math.max(0, gross - discount);
    const tax = taxable * (Number(item.gst_rate || 0) + Number(item.cess_rate || 0)) / 100;
    return { gross, discount, taxable, tax, total: taxable + tax };
};

const totals = computed(() => {
    const subtotal = form.items.reduce((sum, item) => sum + line(item).gross, 0);
    const lineDiscount = form.items.reduce((sum, item) => sum + line(item).discount, 0);
    const taxableBeforeVoucher = form.items.reduce((sum, item) => sum + line(item).taxable, 0);
    const voucherDiscount = form.voucher_discount_type === 'percentage'
        ? taxableBeforeVoucher * Math.min(Number(form.voucher_discount_value || 0), 100) / 100
        : Math.min(taxableBeforeVoucher, Number(form.voucher_discount_value || 0));
    const tax = form.items.reduce((sum, item) => sum + line(item).tax, 0);
    const beforeRound = Math.max(0, subtotal - lineDiscount - voucherDiscount + tax + Number(form.shipping_amount || 0) + Number(form.other_charges || 0));
    const grand = Math.round(beforeRound);
    const paid = form.payments.reduce((sum, payment) => sum + Number(payment.amount || 0), 0);
    return {
        subtotal,
        discount: lineDiscount + voucherDiscount,
        tax,
        roundOff: grand - beforeRound,
        grand,
        paid,
        balance: Math.max(0, grand - paid),
        change: Math.max(0, paid - grand),
    };
});

const paymentStatus = computed(() => {
    if (invoiceStatus.value === 'cancelled') return 'cancelled';
    if (invoiceStatus.value === 'hold') return 'hold';
    if (totals.value.grand <= 0) return 'draft';
    if (totals.value.paid >= totals.value.grand) return 'paid';
    if (totals.value.paid > 0) return 'partial';
    return 'draft';
});
const statusLabel = computed(() => ({ draft: 'Draft', hold: 'Hold', paid: 'Paid', partial: 'Partial', cancelled: 'Cancelled' }[paymentStatus.value] || 'Draft'));
const summaryRows = computed(() => [
    { label: 'Subtotal', value: formatMoney(totals.value.subtotal) },
    { label: 'Discount', value: formatMoney(totals.value.discount) },
    { label: 'Tax', value: formatMoney(totals.value.tax) },
    { label: 'Round-off', value: formatMoney(totals.value.roundOff) },
    { label: 'Grand Total', value: formatMoney(totals.value.grand), grand: true },
]);

const formatMoney = (value) => `${currencySymbol.value}${Number(value || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const productLineTotal = (item) => formatMoney(line(item).total);

const loadReferences = async () => {
    references.value = await SalesApi.references();
    form.branch_id = props.context?.branch?.id || references.value.branches?.[0]?.id || '';
    form.warehouse_id = filteredWarehouses.value?.[0]?.id || references.value.warehouses?.[0]?.id || '';
    form.customer_id = customers.value.find((customer) => customer.customer_type === 'walk_in')?.id || customers.value[0]?.id || '';
    setPaymentMode('cash');
};

const searchProducts = async () => {
    const q = search.value.trim();
    if (q.length < 2) {
        productResults.value = [];
        return;
    }
    productResults.value = await SalesApi.searchProducts(q, {
        branch_id: form.branch_id,
        warehouse_id: form.warehouse_id,
        price_type: priceType.value,
        category_id: activeCategory.value || undefined,
    });
};

const addProduct = (product) => {
    const batch = (product.batches || []).find((item) => Number(item.available_stock || 0) > 0);
    const existing = form.items.find((item) => Number(item.product_id) === Number(product.id) && Number(item.batch_id || 0) === Number(batch?.id || 0));
    if (existing) {
        existing.quantity = Number(existing.quantity || 0) + 1;
    } else {
        form.items.push({
            product_id: product.id,
            product: product.name,
            sku: product.sku,
            barcode: product.barcode,
            image_url: product.image_url,
            product_variant_id: '',
            batch_id: batch?.id || '',
            unit_id: product.unit_id || '',
            quantity: 1,
            free_quantity: 0,
            selling_rate: product.selling_rate || '',
            mrp: product.mrp || '',
            discount_type: '',
            discount_value: '',
            gst_rate: product.gst_rate || '',
            cess_rate: product.cess_rate || '',
            batches: product.batches || [],
            available_stock: batch?.available_stock ?? product.available_stock,
        });
    }
    search.value = '';
    productResults.value = [];
    message.value = '';
    syncPaymentAmount();
    nextTick(() => scanInput.value?.focus());
};

const scan = async () => {
    await searchProducts();
    if (productResults.value.length) addProduct(productResults.value[0]);
    else message.value = 'Barcode or product not found.';
};

const updateQty = (item, amount) => {
    item.quantity = Math.max(1, Number(item.quantity || 0) + amount);
    syncPaymentAmount();
};
const removeItem = (index) => {
    form.items.splice(index, 1);
    syncPaymentAmount();
};

const paymentPayload = (method, amount) => ({
    payment_method_id: method?.id || '',
    amount: Number(amount || 0),
    reference_number: '',
    payment_date: today,
    notes: '',
});

const setPaymentMode = (mode) => {
    paymentMode.value = mode;
    form.sale_type = mode === 'credit' ? 'credit' : 'cash';
    if (mode === 'credit') {
        form.payments = [];
        return;
    }
    if (mode === 'split') {
        const cash = methodByType('cash') || paymentMethods.value[0];
        const upi = methodByType('upi') || paymentMethods.value[1] || cash;
        const first = Math.ceil(totals.value.grand / 2);
        form.payments = [paymentPayload(cash, first), paymentPayload(upi, Math.max(0, totals.value.grand - first))];
        return;
    }
    const method = methodByType(mode) || paymentMethods.value[0];
    form.payments = method ? [paymentPayload(method, totals.value.grand)] : [];
};

const syncPaymentAmount = () => {
    if (paymentMode.value === 'credit') return;
    if (paymentMode.value !== 'split' && form.payments[0]) {
        form.payments[0].amount = totals.value.grand;
    }
};

const newBill = () => {
    Object.assign(form, {
        id: null,
        customer_id: customers.value.find((customer) => customer.customer_type === 'walk_in')?.id || customers.value[0]?.id || '',
        invoice_date: today,
        sale_type: 'cash',
        invoice_type: 'retail_invoice',
        tax_type: 'intrastate',
        voucher_discount_type: '',
        voucher_discount_value: '',
        shipping_amount: '',
        other_charges: '',
        remarks: '',
        items: [],
        payments: [],
    });
    invoiceStatus.value = 'draft';
    paymentMode.value = 'cash';
    lastSale.value = null;
    message.value = '';
    setPaymentMode('cash');
    nextTick(() => scanInput.value?.focus());
};

const recallBill = async (bill) => {
    try {
        const sale = await SalesApi.getSale(bill.id);
        form.id = sale.id;
        form.branch_id = sale.branch_id || form.branch_id;
        form.warehouse_id = sale.warehouse_id || form.warehouse_id;
        form.customer_id = sale.customer_id || form.customer_id;
        form.invoice_date = sale.invoice_date || today;
        form.sale_type = sale.sale_type || 'cash';
        form.invoice_type = sale.invoice_type || 'retail_invoice';
        form.tax_type = sale.tax_type || 'intrastate';
        form.voucher_discount_type = sale.voucher_discount_type || '';
        form.voucher_discount_value = sale.voucher_discount_value || '';
        form.items = (sale.items || []).map((item) => ({
            product_id: item.product_id,
            product: item.product,
            sku: item.sku_snapshot || item.sku || '',
            barcode: item.barcode_snapshot || item.barcode || '',
            image_url: item.image_url || '',
            product_variant_id: item.product_variant_id || '',
            batch_id: item.batch_id || '',
            unit_id: item.unit_id || '',
            quantity: item.quantity,
            free_quantity: item.free_quantity || 0,
            selling_rate: item.selling_rate,
            mrp: item.mrp || '',
            discount_type: item.discount_type || '',
            discount_value: item.discount_value || '',
            gst_rate: item.gst_rate || '',
            cess_rate: item.cess_rate || '',
            available_stock: null,
            batches: [],
        }));
        invoiceStatus.value = 'hold';
        setPaymentMode(form.sale_type === 'credit' ? 'credit' : 'cash');
        message.value = `Recalled ${sale.invoice_number}.`;
    } catch (error) {
        message.value = error.response?.data?.message || 'Held bill could not be recalled.';
    }
};

const payload = (status) => ({
    ...form,
    due_date: '',
    place_of_supply_state_id: null,
    status,
    branch_id: form.branch_id || null,
    warehouse_id: form.warehouse_id || null,
    customer_id: form.customer_id || null,
    payments: paymentMode.value === 'credit' ? [] : form.payments.filter((payment) => payment.payment_method_id && Number(payment.amount || 0) > 0),
});

const save = async (status, options = {}) => {
    if (saving.value || !form.items.length) return null;
    saving.value = true;
    savingAction.value = status;
    message.value = '';
    try {
        if (status === 'approved' && paymentMode.value !== 'credit' && !form.payments.length) setPaymentMode('cash');
        const response = await SalesApi.saveSale(payload(status), form.id);
        lastSale.value = response.sale;
        invoiceStatus.value = status === 'approved' ? 'paid' : status;
        message.value = response.message || 'Sale saved.';
        if (status === 'hold') {
            heldBills.value = [{ id: response.sale.id, invoice_number: response.sale.invoice_number, customer: response.sale.customer, grand_total: response.sale.grand_total, created_at: response.sale.invoice_date }, ...heldBills.value.filter((bill) => bill.id !== response.sale.id)].slice(0, 10);
        }
        if (options.print) printInvoice(response.sale);
        if (options.reset) newBill();
        return response.sale;
    } catch (error) {
        message.value = error.response?.data?.message || Object.values(error.response?.data?.errors || {})?.[0]?.[0] || 'POS sale could not be saved.';
        return null;
    } finally {
        saving.value = false;
        savingAction.value = '';
        nextTick(() => scanInput.value?.focus());
    }
};

const printInvoice = (sale = lastSale.value) => {
    if (!sale?.id) {
        message.value = 'Complete or save a sale before printing.';
        return;
    }
    window.open(SalesApi.printUrl(sale.id), '_blank');
};

const printAndNew = async () => {
    const sale = await save('approved', { print: true });
    if (sale) newBill();
};

const handleShortcut = (event) => {
    if (!['F1', 'F2', 'F3', 'F4', 'F5', 'F6'].includes(event.key)) return;
    event.preventDefault();
    if (event.key === 'F1') newBill();
    if (event.key === 'F2') customerSelect.value?.focus();
    if (event.key === 'F3') scanInput.value?.focus();
    if (event.key === 'F4') save('hold');
    if (event.key === 'F5') document.getElementById('payment-panel')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    if (event.key === 'F6') printInvoice();
};

watch(() => totals.value.grand, syncPaymentAmount);
watch(() => form.branch_id, () => {
    form.warehouse_id = filteredWarehouses.value?.[0]?.id || '';
});

onMounted(async () => {
    SalesApi.configure(props.endpoints);
    window.addEventListener('keydown', handleShortcut);
    await loadReferences();
    scanInput.value?.focus();
});

onUnmounted(() => window.removeEventListener('keydown', handleShortcut));
</script>

<template>
    <Layout page="pos" title="POS Billing">
        <template #topbar-title>
            <div class="bill-page-title">
                <span>SALES</span>
                <h1>POS Billing</h1>
                <p>Counter-ready GST billing with stock, payment, hold and print workflows.</p>
            </div>
        </template>

        <div class="pos-saas-page">
            <InvoiceHeader title="POS Billing" subtitle="Create fast counter invoices with the same SaaS invoice layout used across BillIQ." />

            <div v-if="message" class="pos-message">{{ message }}</div>

            <section class="pos-saas-layout">
                <main class="pos-saas-main">
                    <FilterCard title="Invoice Information" eyebrow="INVOICE">
                        <label class="bill-field">
                            <span>Branch</span>
                            <select v-model="form.branch_id" title="Select branch">
                                <option value="">Select branch</option>
                                <option v-for="branch in references.branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                            </select>
                        </label>
                        <label class="bill-field">
                            <span>Warehouse</span>
                            <select v-model="form.warehouse_id" title="Select warehouse">
                                <option value="">Select warehouse</option>
                                <option v-for="warehouse in filteredWarehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option>
                            </select>
                        </label>
                        <label class="bill-field">
                            <span>Invoice Date</span>
                            <input v-model="form.invoice_date" type="date" />
                        </label>
                        <label class="bill-field">
                            <span>Status</span>
                            <span class="bill-status-badge" :class="paymentStatus">{{ statusLabel }}</span>
                        </label>
                    </FilterCard>

                    <CustomerSelector ref="customerSelect" v-model="form.customer_id" :customers="customers" />

                    <FilterCard title="Product Entry" eyebrow="BARCODE">
                        <template #actions>
                            <div class="pos-category-row">
                                <button type="button" :class="{ active: !activeCategory }" title="Show all categories" @click="activeCategory = ''; searchProducts()">All</button>
                                <button v-for="category in categories" :key="category.id" type="button" :class="{ active: Number(activeCategory) === Number(category.id) }" :title="`Filter ${category.name}`" @click="activeCategory = category.id; searchProducts()">{{ category.name }}</button>
                            </div>
                        </template>
                        <label class="bill-field pos-product-search">
                            <span>Barcode Search</span>
                            <input ref="scanInput" v-model="search" type="search" placeholder="Scan barcode or search product / SKU" @keyup.enter="scan" @input="searchProducts" />
                            <div v-if="productResults.length" class="pos-autocomplete">
                                <button v-for="product in productResults" :key="product.id" type="button" :title="`Add ${product.name}`" @click="addProduct(product)">
                                    <span class="pos-product-thumb">
                                        <img v-if="product.image_url" :src="product.image_url" :alt="product.name" />
                                        <b v-else>{{ product.name.slice(0, 2).toUpperCase() }}</b>
                                    </span>
                                    <strong>{{ product.name }}</strong>
                                    <small>{{ product.sku || product.barcode || 'No SKU' }} - Stock {{ product.available_stock ?? 'Service' }} - {{ formatMoney(product.selling_rate) }}</small>
                                </button>
                            </div>
                        </label>
                        <div class="pos-recent-products">
                            <button v-for="product in recentProducts" :key="product.id" type="button" :title="`Add ${product.name}`" @click="addProduct(product)">
                                <span class="pos-product-thumb">
                                    <img v-if="product.image_url" :src="product.image_url" :alt="product.name" />
                                    <b v-else>{{ product.name.slice(0, 2).toUpperCase() }}</b>
                                </span>
                                <strong>{{ product.name }}</strong>
                                <small>{{ formatMoney(product.selling_rate) }}</small>
                            </button>
                        </div>
                    </FilterCard>

                    <ProductTable
                        :items="form.items"
                        :line-total="productLineTotal"
                        @increment="updateQty($event, 1)"
                        @decrement="updateQty($event, -1)"
                        @remove="removeItem"
                        @change="syncPaymentAmount"
                    />

                    <FilterCard title="Invoice Actions" eyebrow="HELD">
                        <template #actions>
                            <span class="bill-status-badge hold">{{ heldBills.length }} held</span>
                        </template>
                        <div class="pos-held-list">
                            <button v-for="bill in heldBills" :key="bill.id" type="button" :title="`Recall ${bill.invoice_number}`" @click="recallBill(bill)">
                                <strong>{{ bill.invoice_number }}</strong>
                                <span>{{ bill.customer }} - {{ formatMoney(bill.grand_total) }}</span>
                            </button>
                            <p v-if="!heldBills.length">No held invoices in this branch.</p>
                        </div>
                    </FilterCard>
                </main>

                <aside class="pos-saas-side">
                    <SummaryCard title="Invoice Summary" eyebrow="TOTALS" :rows="summaryRows">
                        <template #badge>
                            <span class="bill-status-badge" :class="paymentStatus">{{ statusLabel }}</span>
                        </template>
                    </SummaryCard>

                    <PaymentPanel
                        v-model:payment-mode="paymentMode"
                        :payments="form.payments"
                        :methods="paymentMethods"
                        :grand-total="formatMoney(totals.grand)"
                        :received="formatMoney(totals.paid)"
                        :balance="formatMoney(totals.balance)"
                        :change="formatMoney(totals.change)"
                        @update:payment-mode="setPaymentMode"
                    />
                </aside>
            </section>

            <ActionFooter>
                <button type="button" title="Save invoice as draft" :disabled="saving || !form.items.length" @click="save('draft')">{{ saving && savingAction === 'draft' ? 'Saving...' : 'Save Draft' }}</button>
                <button type="button" title="Hold invoice for later recall" :disabled="saving || !form.items.length" @click="save('hold')">{{ saving && savingAction === 'hold' ? 'Holding...' : 'Hold Invoice' }}</button>
                <button type="button" title="Print last saved invoice" :disabled="!lastSale" @click="printInvoice()">Print</button>
                <button type="button" title="Print invoice and start a new bill" :disabled="saving || !form.items.length" @click="printAndNew">Print & New</button>
                <button class="primary" type="button" title="Complete sale and post invoice" :disabled="saving || !form.items.length" @click="save('approved', { reset: true })">{{ saving && savingAction === 'approved' ? 'Completing...' : 'Complete Sale' }}</button>
            </ActionFooter>
        </div>
    </Layout>
</template>

<style scoped>
.pos-saas-page{display:grid;gap:12px;padding-bottom:10px}.pos-message{padding:10px 12px;border:1px solid #bfdbfe;border-radius:8px;background:#eff6ff;color:#1e40af;font-weight:850}.pos-saas-layout{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:12px;align-items:start}.pos-saas-main,.pos-saas-side{display:grid;gap:12px;min-width:0}.pos-saas-side{position:relative}.pos-category-row{display:flex;gap:6px;flex-wrap:wrap}.pos-category-row button{min-height:30px;padding:6px 9px;border:1px solid var(--bill-line);border-radius:999px;background:#f8fafc;color:#475569;font-size:11px;font-weight:850}.pos-category-row button.active{border-color:#9ec2ff;background:#e8f1ff;color:var(--bill-accent-dark)}.pos-product-search{position:relative;grid-column:1 / -1}.pos-autocomplete{position:absolute;top:63px;left:0;right:0;z-index:12;display:grid;max-height:260px;overflow:auto;border:1px solid var(--bill-line);border-radius:8px;background:#fff;box-shadow:0 18px 40px rgba(15,34,66,.14)}.pos-autocomplete button{display:grid;grid-template-columns:40px 1fr;column-gap:10px;align-items:center;justify-items:start;padding:9px 10px;border:0;border-bottom:1px solid #edf2f7;background:#fff;text-align:left;cursor:pointer}.pos-autocomplete small{grid-column:2;color:var(--bill-muted);font-size:11px}.pos-product-thumb{width:36px;height:36px;overflow:hidden;display:grid;place-items:center;border-radius:8px;background:#eef2ff;color:var(--bill-accent-dark);font-size:11px;font-weight:900}.pos-product-thumb img{width:100%;height:100%;object-fit:cover}.pos-recent-products{grid-column:1 / -1;display:flex;gap:8px;overflow:auto;padding-bottom:2px}.pos-recent-products button{min-width:150px;display:grid;grid-template-columns:36px 1fr;column-gap:8px;align-items:center;justify-items:start;padding:8px;border:1px solid var(--bill-line);border-radius:8px;background:#f8fafc;text-align:left;cursor:pointer}.pos-recent-products small{grid-column:2;color:var(--bill-muted);font-size:11px}.pos-held-list{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.pos-held-list button{display:grid;justify-items:start;gap:3px;padding:10px;border:1px solid var(--bill-line);border-radius:8px;background:#f8fafc;text-align:left;cursor:pointer}.pos-held-list span,.pos-held-list p{color:var(--bill-muted);font-size:11px}.pos-held-list p{margin:0}@media(max-width:1180px){.pos-saas-layout{grid-template-columns:1fr}.pos-saas-side{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:760px){.pos-saas-side,.pos-held-list{grid-template-columns:1fr}.pos-recent-products{display:grid;grid-template-columns:1fr}.pos-recent-products button{min-width:0}}
</style>
