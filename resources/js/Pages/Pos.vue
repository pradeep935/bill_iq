<script setup>
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import Layout from './Layout.vue';
import SalesApi from './Sales/SalesApi';
import ActionFooter from '@/Components/Billing/ActionFooter.vue';
import FilterCard from '@/Components/Billing/FilterCard.vue';
import InvoiceHeader from '@/Components/Billing/InvoiceHeader.vue';
import PaymentPanel from '@/Components/Billing/PaymentPanel.vue';
import ProductTable from '@/Components/Billing/ProductTable.vue';
import SummaryCard from '@/Components/Billing/SummaryCard.vue';

const props = defineProps({
    page: { type: String, default: 'pos' },
    title: { type: String, default: 'POS Billing' },
    role_id: { type: [Number, String], default: 2 },
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
const paymentMode = ref('cash');
const saving = ref(false);
const savingAction = ref('');
const scanning = ref(false);
const message = ref('');
const messageTone = ref('info');
const lastSale = ref(null);
const invoiceStatus = ref('draft');
const scannerFocused = ref(false);
const highlightedRowKey = ref('');
const canChangeCounterScope = computed(() => Number(props.role_id || 0) === 1);

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

const contextSettings = computed(() => props.context?.settings || {});
const currencySymbol = computed(() => contextSettings.value.currency_symbol || 'Rs. ');
const customers = computed(() => references.value.customers || []);
const paymentMethods = computed(() => references.value.payment_methods || []);
const selectedCustomer = computed(() => customers.value.find((customer) => Number(customer.id) === Number(form.customer_id)));
const priceType = computed(() => selectedCustomer.value?.price_type || 'retail');
const selectedBranch = computed(() => references.value.branches.find((branch) => Number(branch.id) === Number(form.branch_id)));
const selectedWarehouse = computed(() => references.value.warehouses.find((warehouse) => Number(warehouse.id) === Number(form.warehouse_id)));
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
    const rate = Number(item.gst_rate || 0) + Number(item.cess_rate || 0);
    if (item.tax_inclusive && rate > 0) {
        const inclusiveTaxable = taxable * 100 / (100 + rate);
        const tax = taxable - inclusiveTaxable;
        return { gross, discount, taxable: inclusiveTaxable, tax, total: taxable };
    }
    const tax = taxable * rate / 100;
    return { gross, discount, taxable, tax, total: taxable + tax };
};

const totals = computed(() => {
    const subtotal = form.items.reduce((sum, item) => sum + line(item).gross, 0);
    const lineDiscount = form.items.reduce((sum, item) => sum + line(item).discount, 0);
    const taxableBeforeVoucher = form.items.reduce((sum, item) => sum + line(item).taxable, 0);
    const voucherDiscount = form.voucher_discount_type === 'percentage'
        ? taxableBeforeVoucher * Math.min(Number(form.voucher_discount_value || 0), 100) / 100
        : Math.min(taxableBeforeVoucher, Number(form.voucher_discount_value || 0));
    const taxableAfterVoucher = Math.max(0, taxableBeforeVoucher - voucherDiscount);
    const taxRatio = taxableBeforeVoucher > 0 ? taxableAfterVoucher / taxableBeforeVoucher : 1;
    const tax = form.items.reduce((sum, item) => sum + line(item).tax, 0) * taxRatio;
    const beforeRound = Math.max(0, taxableAfterVoucher + tax + Number(form.shipping_amount || 0) + Number(form.other_charges || 0));
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
const hasInclusiveTax = computed(() => form.items.some((item) => item.tax_inclusive && (Number(item.gst_rate || 0) + Number(item.cess_rate || 0)) > 0));
const summaryRows = computed(() => [
    { label: 'Subtotal', value: formatMoney(totals.value.subtotal) },
    { label: 'Discount', value: formatMoney(totals.value.discount) },
    { label: hasInclusiveTax.value ? 'Tax (included)' : 'Tax', value: formatMoney(totals.value.tax) },
    { label: 'Round-off', value: formatMoney(totals.value.roundOff) },
    { label: 'Grand Total', value: formatMoney(totals.value.grand), grand: true },
]);
const hasCartItems = computed(() => form.items.length > 0);
const footerState = computed(() => {
    if (!hasCartItems.value) return 'Scan a product to start billing';
    if (paymentMode.value === 'credit') return 'Credit sale';
    if (totals.value.balance > 0) return `Balance ${formatMoney(totals.value.balance)}`;
    if (totals.value.change > 0) return `Change ${formatMoney(totals.value.change)}`;
    return 'Ready to complete';
});

const formatMoney = (value) => `${currencySymbol.value}${Number(value || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const productLineTotal = (item) => formatMoney(line(item).total);
const showToast = (text, tone = 'info') => {
    message.value = text;
    messageTone.value = tone;
};
const focusScanner = () => nextTick(() => scanInput.value?.focus());
const firstWarehouseForBranch = (branchId = form.branch_id) => {
    const warehouses = references.value.warehouses || [];
    if (!warehouses.length) return '';
    return (branchId
        ? warehouses.find((warehouse) => Number(warehouse.branch_id || 0) === Number(branchId))
        : warehouses[0])?.id || warehouses[0]?.id || '';
};
const ensureDefaultCounterScope = () => {
    if (!form.branch_id) {
        form.branch_id = props.context?.branch?.id || references.value.branches?.[0]?.id || '';
    }
    if (!form.warehouse_id) {
        form.warehouse_id = firstWarehouseForBranch(form.branch_id);
    }
};
const updateCustomerTaxTreatment = () => {
    const customer = selectedCustomer.value;
    if (!customer || customer.customer_type === 'walk_in') {
        form.invoice_type = 'retail_invoice';
        form.tax_type = 'intrastate';
        return;
    }
    form.invoice_type = customer.gstin ? 'tax_invoice' : 'retail_invoice';
    const sourceStateId = props.context?.branch?.state_id || props.context?.business?.state_id;
    if (customer.state_id && sourceStateId) {
        form.tax_type = Number(customer.state_id) === Number(sourceStateId) ? 'intrastate' : 'interstate';
    }
};
const rowKey = (item) => `${item.product_id}-${item.product_variant_id || 0}-${item.batch_id || 0}`;
const flashRow = (item) => {
    highlightedRowKey.value = rowKey(item);
    window.setTimeout(() => {
        if (highlightedRowKey.value === rowKey(item)) highlightedRowKey.value = '';
    }, 1200);
};
const isStockItem = (item) => item.available_stock !== null && item.available_stock !== undefined;
const validateCartStock = () => {
    for (const item of form.items) {
        if (isStockItem(item) && Number(item.quantity || 0) + Number(item.free_quantity || 0) > Number(item.available_stock || 0)) {
            showToast(`Insufficient stock for ${item.product}. Available ${item.available_stock}.`, 'error');
            focusScanner();
            return false;
        }
        if (item.serial_required) {
            showToast(`Serial number is required for ${item.product}.`, 'error');
            focusScanner();
            return false;
        }
    }
    return true;
};
const validatePaymentBeforeSave = (status) => {
    if (!['confirmed', 'approved'].includes(status)) return true;
    const paid = Number(totals.value.paid.toFixed(2));
    const grand = Number(totals.value.grand.toFixed(2));
    if (paymentMode.value === 'credit') return true;
    if (paymentMode.value === 'split' && paid !== grand) {
        showToast('Split payment total must exactly match invoice total.', 'error');
        return false;
    }
    if (paid < grand) {
        showToast('Received amount is less than invoice total. Use Credit sale for outstanding.', 'error');
        return false;
    }
    return true;
};

const loadReferences = async () => {
    references.value = await SalesApi.references();
    ensureDefaultCounterScope();
    form.customer_id = customers.value.find((customer) => customer.customer_type === 'walk_in')?.id || customers.value[0]?.id || '';
    setPaymentMode('cash');
    focusScanner();
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
    });
};

const addProduct = (product, fromScan = false) => {
    const batch = (product.batches || []).find((item) => Number(item.id || 0) === Number(product.batch_id || 0))
        || (product.batches || []).find((item) => Number(item.available_stock || 0) > 0);
    const available = Number(batch?.available_stock ?? product.available_stock ?? 0);
    if (product.serial_required) {
        showToast('Serial-number products require serial selection before billing.', 'error');
        focusScanner();
        return false;
    }
    if (product.product_type !== 'service' && product.item_type !== 'non_stock' && available <= 0) {
        showToast('Insufficient stock', 'error');
        focusScanner();
        return false;
    }
    const variantId = product.product_variant_id || '';
    const batchId = batch?.id || product.batch_id || '';
    const existing = form.items.find((item) => Number(item.product_id) === Number(product.id) && Number(item.product_variant_id || 0) === Number(variantId || 0) && Number(item.batch_id || 0) === Number(batchId || 0));
    let touched = existing;
    if (existing) {
        if (isStockItem(existing) && Number(existing.quantity || 0) + 1 > Number(existing.available_stock || 0)) {
            showToast('Insufficient stock', 'error');
            focusScanner();
            return false;
        }
        existing.quantity = Number(existing.quantity || 0) + 1;
    } else {
        touched = {
            product_id: product.id,
            product: product.name,
            sku: product.sku,
            barcode: product.barcode,
            image_url: product.image_url,
            product_variant_id: variantId,
            batch_id: batchId,
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
            available_stock: product.product_type === 'service' || product.item_type === 'non_stock' ? null : available,
            tax_inclusive: product.tax_inclusive || false,
            serial_required: Boolean(product.serial_required),
        };
        form.items.push(touched);
    }
    search.value = '';
    productResults.value = [];
    if (fromScan) showToast('Product added to cart.', 'success');
    flashRow(touched);
    syncPaymentAmount();
    focusScanner();
    return true;
};

const scan = async () => {
    const barcode = search.value.trim();
    if (!barcode || scanning.value) return;
    ensureDefaultCounterScope();
    if (!form.branch_id || !form.warehouse_id) {
        search.value = '';
        productResults.value = [];
        showToast('Please select branch and warehouse before scanning.', 'error');
        focusScanner();
        return;
    }
    scanning.value = true;
    try {
        const product = await SalesApi.scanProduct(barcode, {
            branch_id: form.branch_id,
            warehouse_id: form.warehouse_id,
            price_type: priceType.value,
        });
        addProduct(product, true);
    } catch (error) {
        const serverMessage = error.response?.data?.message || Object.values(error.response?.data?.errors || {})?.[0]?.[0] || '';
        showToast(serverMessage.includes('Insufficient stock') ? 'Insufficient stock' : serverMessage || 'Product not found', 'error');
    } finally {
        scanning.value = false;
        search.value = '';
        productResults.value = [];
        focusScanner();
    }
};

const updateQty = (item, amount) => {
    const next = Math.max(1, Number(item.quantity || 0) + amount);
    if (isStockItem(item) && next > Number(item.available_stock || 0)) {
        item.quantity = Number(item.available_stock || 1);
        showToast(`Only ${item.available_stock} available for ${item.product}.`, 'error');
    } else {
        item.quantity = next;
    }
    flashRow(item);
    syncPaymentAmount();
};
const normalizeQty = (item) => {
    item.quantity = Math.max(1, Number(item.quantity || 1));
    if (isStockItem(item) && Number(item.quantity || 0) > Number(item.available_stock || 0)) {
        item.quantity = Number(item.available_stock || 1);
        showToast(`Only ${item.available_stock} available for ${item.product}.`, 'error');
    }
    flashRow(item);
    syncPaymentAmount();
};
const updateBatchStock = (item) => {
    const selected = (item.batches || []).find((batch) => Number(batch.id) === Number(item.batch_id));
    if (selected) {
        item.available_stock = Number(selected.available_stock || 0);
        normalizeQty(item);
    }
    focusScanner();
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
    messageTone.value = 'info';
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
        showToast(`Recalled ${sale.invoice_number}.`, 'info');
    } catch (error) {
        showToast(error.response?.data?.message || 'Held bill could not be recalled.', 'error');
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
    ensureDefaultCounterScope();
    if (!validateCartStock() || !validatePaymentBeforeSave(status)) return null;
    saving.value = true;
    savingAction.value = status;
    message.value = '';
    try {
        if (status === 'approved' && paymentMode.value !== 'credit' && !form.payments.length) setPaymentMode('cash');
        const response = await SalesApi.saveSale(payload(status), form.id);
        lastSale.value = response.sale;
        invoiceStatus.value = status === 'approved' ? 'paid' : status;
        showToast(response.message || 'Sale saved.', 'success');
        if (status === 'hold') {
            heldBills.value = [{ id: response.sale.id, invoice_number: response.sale.invoice_number, customer: response.sale.customer, grand_total: response.sale.grand_total, created_at: response.sale.invoice_date }, ...heldBills.value.filter((bill) => bill.id !== response.sale.id)].slice(0, 10);
        }
        if (options.print) printInvoice(response.sale);
        if (options.reset) newBill();
        return response.sale;
    } catch (error) {
        showToast(error.response?.data?.message || Object.values(error.response?.data?.errors || {})?.[0]?.[0] || 'POS sale could not be saved.', 'error');
        return null;
    } finally {
        saving.value = false;
        savingAction.value = '';
        nextTick(() => scanInput.value?.focus());
    }
};

const printInvoice = (sale = lastSale.value) => {
    if (!sale?.id) {
        showToast('Complete or save a sale before printing.', 'error');
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
    form.warehouse_id = firstWarehouseForBranch(form.branch_id);
    focusScanner();
});
watch(() => form.warehouse_id, () => {
    focusScanner();
});
watch(() => form.customer_id, () => {
    updateCustomerTaxTreatment();
    focusScanner();
});

const keepScannerReady = (event) => {
    if (event.target?.closest?.('input, textarea, select, button')) return;
    focusScanner();
};

onMounted(async () => {
    SalesApi.configure(props.endpoints);
    window.addEventListener('keydown', handleShortcut);
    window.addEventListener('click', keepScannerReady);
    await loadReferences();
    scanInput.value?.focus();
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleShortcut);
    window.removeEventListener('click', keepScannerReady);
});
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
            <InvoiceHeader title="POS Billing" subtitle="Fast counter billing with scanner-first product entry, live stock and payment posting." />

            <div v-if="message" class="pos-message" :class="messageTone">{{ message }}</div>

            <section class="pos-saas-layout">
                <main class="pos-saas-main">
                    <FilterCard title="Counter Details" eyebrow="BILLING">
                        <div v-if="!canChangeCounterScope" class="pos-counter-scope">
                            <span>{{ selectedBranch?.name || 'Default Branch' }}</span>
                            <strong>{{ selectedWarehouse?.name || 'Default Warehouse' }}</strong>
                        </div>
                        <label v-if="canChangeCounterScope" class="bill-field">
                            <span>Branch</span>
                            <select v-model="form.branch_id" title="Select branch">
                                <option value="">Select branch</option>
                                <option v-for="branch in references.branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                            </select>
                        </label>
                        <label v-if="canChangeCounterScope" class="bill-field">
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
                        <label class="bill-field pos-customer-field">
                            <span>Customer</span>
                            <select ref="customerSelect" v-model="form.customer_id" title="Select invoice customer">
                                <option value="">Walk-in Customer</option>
                                <option v-for="customer in customers" :key="customer.id" :value="customer.id">
                                    {{ customer.customer_name }}{{ customer.gstin ? ` - ${customer.gstin}` : (customer.mobile ? ` - ${customer.mobile}` : '') }}
                                </option>
                            </select>
                        </label>
                    </FilterCard>

                    <FilterCard title="Scan Product" eyebrow="BARCODE">
                        <label class="bill-field pos-product-search">
                            <span>Barcode Search</span>
                            <input ref="scanInput" v-model="search" class="pos-scan-input" type="search" placeholder="Scan barcode or search product" @focus="scannerFocused = true" @blur="scannerFocused = false" @keyup.enter="scan" @input="searchProducts" />
                            <div v-if="productResults.length" class="pos-autocomplete">
                                <button v-for="product in productResults" :key="product.id" type="button" :title="`Add ${product.name}`" @click="addProduct(product)">
                                    <span class="pos-product-thumb">
                                        <img v-if="product.image_url" :src="product.image_url" :alt="product.name" />
                                        <b v-else>{{ product.name.slice(0, 2).toUpperCase() }}</b>
                                    </span>
                                    <strong>{{ product.name }}</strong>
                                    <small>{{ product.sku || product.barcode || 'No SKU' }} - Stock {{ product.available_stock ?? 'Service' }} - GST {{ product.gst_rate || 0 }}% - {{ formatMoney(product.selling_rate) }}</small>
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
                        :highlight-key="highlightedRowKey"
                        @increment="updateQty($event, 1)"
                        @decrement="updateQty($event, -1)"
                        @remove="removeItem"
                        @change="syncPaymentAmount"
                        @quantity-change="normalizeQty"
                        @batch-change="updateBatchStock"
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
                <div class="pos-footer-total">
                    <span>{{ footerState }}</span>
                    <strong>{{ formatMoney(totals.grand) }}</strong>
                </div>
                <div class="pos-footer-actions">
                    <div class="pos-footer-group">
                        <button class="pos-action secondary" type="button" title="Save invoice as draft" :disabled="saving || !hasCartItems" @click="save('draft')">{{ saving && savingAction === 'draft' ? 'Saving...' : 'Save Draft' }}</button>
                        <button class="pos-action secondary" type="button" title="Hold invoice for later recall" :disabled="saving || !hasCartItems" @click="save('hold')">{{ saving && savingAction === 'hold' ? 'Holding...' : 'Hold Bill' }}</button>
                    </div>
                    <div class="pos-footer-group">
                        <button class="pos-action print" type="button" title="Print last completed or saved invoice" :disabled="!lastSale" @click="printInvoice()">{{ lastSale ? 'Print Last Bill' : 'Print unavailable' }}</button>
                        <button class="pos-action print" type="button" title="Complete sale, print invoice and start a new bill" :disabled="saving || !hasCartItems" @click="printAndNew">Print & New</button>
                        <button class="pos-action complete primary" type="button" title="Complete sale and post invoice" :disabled="saving || !hasCartItems" @click="save('approved', { reset: true })">
                            <span>{{ saving && savingAction === 'approved' ? 'Completing...' : 'Complete Sale' }}</span>
                            <strong>{{ formatMoney(totals.grand) }}</strong>
                        </button>
                    </div>
                </div>
            </ActionFooter>
        </div>
    </Layout>
</template>

<style scoped>
.pos-saas-page{display:grid;gap:10px;padding-bottom:8px}.pos-message{padding:10px 12px;border:1px solid #bfdbfe;border-radius:8px;background:#eff6ff;color:#1e40af;font-weight:850}.pos-message.success{border-color:#bbf7d0;background:#ecfdf5;color:#15803d}.pos-message.error{border-color:#fecdd3;background:#fff1f2;color:#be123c}.pos-saas-layout{display:grid;grid-template-columns:minmax(0,1fr) 330px;gap:12px;align-items:start}.pos-saas-main,.pos-saas-side{display:grid;gap:10px;min-width:0}.pos-saas-side{position:sticky;top:86px}.pos-counter-scope{grid-column:span 2;display:grid;gap:3px;min-height:54px;padding:10px 12px;border:1px solid var(--bill-line);border-radius:8px;background:#f8fafc}.pos-counter-scope span{color:var(--bill-muted);font-size:11px;font-weight:850}.pos-counter-scope strong{color:#142139;font-size:13px}.pos-customer-field{grid-column:span 2}.pos-category-row{display:flex;gap:6px;flex-wrap:wrap}.pos-category-row button{min-height:28px;padding:5px 9px;border:1px solid var(--bill-line);border-radius:999px;background:#f8fafc;color:#475569;font-size:11px;font-weight:850}.pos-category-row button.active{border-color:#9ec2ff;background:#e8f1ff;color:var(--bill-accent-dark)}.pos-scan-state{display:inline-flex;align-items:center;min-height:28px;padding:5px 10px;border-radius:999px;background:#f1f5f9;color:#64748b;font-size:11px;font-weight:900}.pos-scan-state.ready{background:#dcfce7;color:#15803d}.pos-product-search{position:relative;grid-column:1 / -1}.pos-scan-input{min-height:56px!important;border:2px solid #9ec2ff!important;border-radius:12px!important;background:#fff!important;font-size:18px!important;font-weight:850;letter-spacing:.02em}.pos-scan-input:focus{border-color:#2457d6!important;box-shadow:0 0 0 4px rgba(36,87,214,.12);outline:0}.pos-autocomplete{position:absolute;top:82px;left:0;right:0;z-index:12;display:grid;max-height:260px;overflow:auto;border:1px solid var(--bill-line);border-radius:8px;background:#fff;box-shadow:0 18px 40px rgba(15,34,66,.14)}.pos-autocomplete button{display:grid;grid-template-columns:40px 1fr;column-gap:10px;align-items:center;justify-items:start;padding:9px 10px;border:0;border-bottom:1px solid #edf2f7;background:#fff;text-align:left;cursor:pointer}.pos-autocomplete small{grid-column:2;color:var(--bill-muted);font-size:11px}.pos-product-thumb{width:36px;height:36px;overflow:hidden;display:grid;place-items:center;border-radius:8px;background:#eef2ff;color:var(--bill-accent-dark);font-size:11px;font-weight:900}.pos-product-thumb img{width:100%;height:100%;object-fit:cover}.pos-recent-products{grid-column:1 / -1;display:flex;gap:8px;overflow:auto;padding-bottom:2px}.pos-recent-products button{min-width:140px;display:grid;grid-template-columns:36px 1fr;column-gap:8px;align-items:center;justify-items:start;padding:8px;border:1px solid var(--bill-line);border-radius:8px;background:#f8fafc;text-align:left;cursor:pointer}.pos-recent-products small{grid-column:2;color:var(--bill-muted);font-size:11px}.pos-held-list{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.pos-held-list button{display:grid;justify-items:start;gap:3px;padding:10px;border:1px solid var(--bill-line);border-radius:8px;background:#f8fafc;text-align:left;cursor:pointer}.pos-held-list span,.pos-held-list p{color:var(--bill-muted);font-size:11px}.pos-held-list p{margin:0}:deep(.bill-invoice-header){margin-bottom:2px}:deep(.bill-filter-grid){grid-template-columns:repeat(6,minmax(0,1fr));gap:9px}:deep(.bill-filter-grid>.bill-field){grid-column:span 2}:deep(.bill-product-table-wrap){max-height:calc(100vh - 455px);min-height:250px}:deep(.bill-summary-card),:deep(.bill-payment-panel){position:static}@media(min-width:1500px){.pos-saas-layout{grid-template-columns:minmax(0,1fr) 360px}:deep(.bill-product-table-wrap){max-height:calc(100vh - 430px)}}@media(max-width:1366px){.pos-saas-layout{grid-template-columns:minmax(0,1fr) 315px}.pos-scan-input{min-height:52px!important;font-size:16px!important}:deep(.bill-filter-grid){grid-template-columns:repeat(4,minmax(0,1fr))}:deep(.bill-filter-grid>.bill-field){grid-column:span 1}.pos-customer-field{grid-column:span 2}}@media(max-width:1180px){.pos-saas-layout{grid-template-columns:1fr}.pos-saas-side{position:static;grid-template-columns:repeat(2,minmax(0,1fr))}:deep(.bill-product-table-wrap){max-height:520px}}@media(max-width:760px){.pos-saas-side,.pos-held-list{grid-template-columns:1fr}.pos-recent-products{display:grid;grid-template-columns:1fr}.pos-recent-products button{min-width:0}:deep(.bill-filter-grid){grid-template-columns:1fr}:deep(.bill-filter-grid>.bill-field),.pos-customer-field,.pos-counter-scope{grid-column:auto}}
:deep(.bill-action-footer){display:grid;grid-template-columns:minmax(190px,260px) 1fr;gap:12px;align-items:center}.pos-footer-total{display:grid;gap:2px;min-height:54px;padding:8px 12px;border:1px solid #dbeafe;border-radius:8px;background:#eff6ff}.pos-footer-total span{color:#47607d;font-size:11px;font-weight:850}.pos-footer-total strong{color:#173b77;font-size:22px;line-height:1}.pos-footer-actions{display:flex;justify-content:flex-end;gap:12px;flex-wrap:wrap}.pos-footer-group{display:flex;gap:8px;flex-wrap:wrap}.pos-action{min-height:44px;padding:9px 13px;border:1px solid #d8e0eb;border-radius:8px;background:#fff;color:#344159;font-size:12px;font-weight:900;cursor:pointer}.pos-action.secondary{background:#f8fafc}.pos-action.print{color:#24446f}.pos-action.complete{display:grid;grid-template-columns:auto auto;gap:10px;align-items:center;min-width:210px;padding:9px 16px;border-color:#2457d6;background:#2457d6;color:#fff}.pos-action.complete strong{font-size:14px}.pos-action:disabled{cursor:not-allowed;opacity:.52}.pos-action.print:disabled{background:#f8fafc;color:#94a3b8}@media(max-width:980px){:deep(.bill-action-footer){grid-template-columns:1fr}.pos-footer-actions{justify-content:stretch}.pos-footer-group,.pos-action{flex:1 1 auto}.pos-action.complete{grid-template-columns:1fr;justify-items:center;min-width:0}}@media(max-width:640px){.pos-footer-actions,.pos-footer-group{display:grid;grid-template-columns:1fr}.pos-action{width:100%}}
</style>
