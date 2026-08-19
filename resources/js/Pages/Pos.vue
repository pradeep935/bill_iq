<script setup>
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import Layout from './Layout.vue';
import SalesApi from './Sales/SalesApi';
import ActionFooter from '@/Components/Billing/ActionFooter.vue';
import FilterCard from '@/Components/Billing/FilterCard.vue';
import PaymentPanel from '@/Components/Billing/PaymentPanel.vue';
import ProductTable from '@/Components/Billing/ProductTable.vue';
import SummaryCard from '@/Components/Billing/SummaryCard.vue';
import AppToast from '@/Components/Common/AppToast.vue';

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
const customerMobileLookup = ref('');
const customerLookup = ref({ status: '', message: '', normalized_mobile: '' });
const customerInsight = ref(null);
const showQuickCustomer = ref(false);
const sharingWhatsApp = ref(false);
const canChangeCounterScope = computed(() => Number(props.role_id || 0) === 1);
let customerLookupTimer = null;
let toastTimer = null;

const quickCustomer = reactive({
    customer_name: '',
    mobile: '',
    whatsapp_number: '',
    whatsapp_same_as_mobile: true,
    gstin: '',
    email: '',
    billing_address: '',
    shipping_address: '',
});

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
const printSettings = computed(() => ({
    defaultFormat: ['a4', 'thermal'].includes(contextSettings.value.default_print_format) ? contextSettings.value.default_print_format : 'a4',
    autoPrint: Boolean(contextSettings.value.auto_print_after_payment),
    showThermalLogo: contextSettings.value.show_logo_on_thermal_receipt !== false,
    thermalPaperWidth: contextSettings.value.thermal_paper_width || '80mm',
}));
const defaultPrintFormat = computed(() => printSettings.value.defaultFormat);
const primaryPrintLabel = computed(() => defaultPrintFormat.value === 'thermal' ? 'Print Receipt' : 'Print A4 Invoice');
const customers = computed(() => references.value.customers || []);
const paymentMethods = computed(() => references.value.payment_methods || []);
const selectedCustomer = computed(() => customers.value.find((customer) => Number(customer.id) === Number(form.customer_id)));
const selectedIsWalkIn = computed(() => !selectedCustomer.value || selectedCustomer.value.customer_type === 'walk_in');
const hasCustomerGstin = computed(() => Boolean(selectedCustomer.value?.gstin));
const invoicePartyType = computed(() => form.invoice_type === 'bill_of_supply' ? 'BOS' : (form.invoice_type === 'tax_invoice' ? 'B2B' : 'B2C'));
const invoicePartyLabel = computed(() => {
    if (invoicePartyType.value === 'BOS') return 'Bill of Supply';
    if (invoicePartyType.value === 'B2B' && !hasCustomerGstin.value) return 'B2B (GSTIN Required)';
    return invoicePartyType.value === 'B2B' ? 'B2B (With GSTIN)' : 'B2C (Without GSTIN)';
});
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
    const rate = form.invoice_type === 'bill_of_supply' ? 0 : Number(item.gst_rate || 0) + Number(item.cess_rate || 0);
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
    const mrpTotal = form.items.reduce((sum, item) => {
        const mrp = Number(item.mrp || 0);
        const rate = Number(item.selling_rate || 0);
        return sum + Number(item.quantity || 0) * (mrp > 0 ? mrp : rate);
    }, 0);
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
        mrpTotal,
        priceSaving: Math.max(0, mrpTotal - subtotal),
        discount: lineDiscount + voucherDiscount,
        tax,
        roundOff: grand - beforeRound,
        grand,
        paid,
        balance: Math.max(0, grand - paid),
        change: Math.max(0, paid - grand),
    };
});

const totalQuantity = computed(() => form.items.reduce((sum, item) => sum + Number(item.quantity || 0), 0));
const savingPercent = computed(() => totals.value.mrpTotal > 0 ? (totals.value.priceSaving / totals.value.mrpTotal) * 100 : 0);

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
    { label: 'MRP Total', value: formatMoney(totals.value.mrpTotal) },
    { label: 'Price Saving (MRP - Rate)', value: `-${formatMoney(totals.value.priceSaving)}`, saving: true },
    { label: 'Selling Price Total', value: formatMoney(totals.value.subtotal), divider: true },
    { label: 'Additional Discount', value: formatMoney(totals.value.discount) },
    { label: 'Taxable Value', value: formatMoney(Math.max(0, totals.value.subtotal - totals.value.discount)) },
    { label: hasInclusiveTax.value ? 'Tax (included)' : 'Total Tax', value: formatMoney(totals.value.tax) },
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
const productLineDetails = (item) => {
    const detail = line(item);
    return {
        taxable: formatMoney(detail.taxable),
        gstAmount: formatMoney(detail.tax),
        total: formatMoney(detail.total),
    };
};
const showToast = (text, tone = 'info') => {
    if (toastTimer) clearTimeout(toastTimer);
    message.value = text;
    messageTone.value = tone;
    toastTimer = window.setTimeout(() => {
        message.value = '';
        toastTimer = null;
    }, tone === 'error' ? 5200 : 3600);
};
const formatDate = (value) => value ? new Date(value).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '-';
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
            return false;
        }
        if (item.serial_required) {
            showToast(`Serial number is required for ${item.product}.`, 'error');
            return false;
        }
    }
    return true;
};
const validatePaymentBeforeSave = (status) => {
    if (!['confirmed', 'approved'].includes(status)) return true;
    if (form.invoice_type === 'tax_invoice' && !hasCustomerGstin.value) {
        showToast('B2B Tax Invoice requires customer GSTIN. Select B2C Retail or add GSTIN to customer.', 'error');
        return false;
    }
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
};

const addOrReplaceCustomerReference = (customer) => {
    const index = references.value.customers.findIndex((item) => Number(item.id) === Number(customer.id));
    if (index >= 0) {
        references.value.customers.splice(index, 1, customer);
        return;
    }

    references.value.customers.push(customer);
};

const resetQuickCustomer = (mobile = customerMobileLookup.value) => {
    Object.assign(quickCustomer, {
        customer_name: '',
        mobile,
        whatsapp_number: mobile,
        whatsapp_same_as_mobile: true,
        gstin: '',
        email: '',
        billing_address: '',
        shipping_address: '',
    });
};

const lookupCustomerByMobile = async () => {
    const mobile = customerMobileLookup.value.trim();

    if (mobile.replace(/\D+/g, '').length < 10) {
        customerLookup.value = { status: '', message: '', normalized_mobile: '' };
        return;
    }

    customerLookup.value = { status: 'searching', message: 'Searching customer...', normalized_mobile: '' };

    try {
        const response = await SalesApi.lookupCustomerByMobile(mobile);
        customerLookup.value = response;

        if (response.status === 'found' && response.customer) {
            addOrReplaceCustomerReference(response.customer);
            form.customer_id = response.customer.id;
            customerInsight.value = response.insight || null;
            showQuickCustomer.value = false;
            showToast('Customer found and selected.', 'success');
            return;
        }

        if (response.status === 'new') {
            resetQuickCustomer(response.normalized_mobile || mobile);
            showQuickCustomer.value = true;
        }
    } catch (error) {
        customerLookup.value = {
            status: 'invalid',
            message: error.response?.data?.message || Object.values(error.response?.data?.errors || {})?.[0]?.[0] || 'Invalid mobile number.',
            normalized_mobile: '',
        };
    }
};

const quickCreateCustomer = async () => {
    try {
        const response = await SalesApi.quickCreateCustomer({
            ...quickCustomer,
            shipping_address: quickCustomer.shipping_address || quickCustomer.billing_address,
        });
        addOrReplaceCustomerReference(response.customer);
        form.customer_id = response.customer.id;
        customerInsight.value = response.insight || null;
        customerLookup.value = { status: 'found', message: 'Customer created and selected.', normalized_mobile: response.customer.normalized_mobile };
        showQuickCustomer.value = false;
        showToast(response.message || 'Customer created.', 'success');
    } catch (error) {
        showToast(error.response?.data?.message || Object.values(error.response?.data?.errors || {})?.[0]?.[0] || 'Customer could not be created.', 'error');
    }
};

const loadCustomerInsight = async () => {
    if (!form.customer_id || selectedIsWalkIn.value) {
        customerInsight.value = null;
        return;
    }

    try {
        customerInsight.value = await SalesApi.customerInsight(form.customer_id);
    } catch (error) {
        customerInsight.value = null;
    }
};

const openCustomerHistory = () => {
    if (!customerInsight.value?.customer) return;
    const query = customerInsight.value.customer.mobile || customerInsight.value.customer.customer_name || '';
    window.location.href = `/app/sales/customers?search=${encodeURIComponent(query)}`;
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

const addProduct = async (product, fromScan = false) => {
    const batch = (product.batches || []).find((item) => Number(item.id || 0) === Number(product.batch_id || 0))
        || (product.batches || []).find((item) => Number(item.available_stock || 0) > 0);
    const available = Number(batch?.available_stock ?? product.available_stock ?? 0);
    if (product.serial_required) {
        showToast('Serial-number products require serial selection before billing.', 'error');
        return false;
    }
    if (product.product_type !== 'service' && product.item_type !== 'non_stock' && available <= 0) {
        showToast('Insufficient stock', 'error');
        return false;
    }
    const variantId = product.product_variant_id || '';
    const batchId = batch?.id || product.batch_id || '';
    const existing = form.items.find((item) => Number(item.product_id) === Number(product.id) && Number(item.product_variant_id || 0) === Number(variantId || 0) && Number(item.batch_id || 0) === Number(batchId || 0));
    let touched = existing;
    if (existing) {
        if (isStockItem(existing) && Number(existing.quantity || 0) + 1 > Number(existing.available_stock || 0)) {
            showToast('Insufficient stock', 'error');
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
            unit: product.unit || 'PCS',
            hsn_code: product.hsn_code || '',
            quantity: 1,
            free_quantity: 0,
            selling_rate: product.selling_rate || '',
            mrp: product.mrp || '',
            discount_type: '',
            discount_value: '',
            gst_rate: product.gst_rate ?? '',
            cess_rate: product.cess_rate ?? '',
            batches: product.batches || [],
            available_stock: product.product_type === 'service' || product.item_type === 'non_stock' ? null : available,
            tax_inclusive: product.tax_inclusive || false,
            serial_required: Boolean(product.serial_required),
            previous_purchase: null,
        };
        form.items.push(touched);
    }
    search.value = '';
    productResults.value = [];
    if (fromScan) showToast('Product added to cart.', 'success');
    flashRow(touched);
    if (form.customer_id && !selectedIsWalkIn.value) {
        try {
            const response = await SalesApi.productLastPurchase(form.customer_id, product.id);
            touched.previous_purchase = response.last_purchase;
        } catch (error) {
            touched.previous_purchase = null;
        }
    }
    syncPaymentAmount();
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
        return;
    }
    scanning.value = true;
    try {
        const product = await SalesApi.scanProduct(barcode, {
            branch_id: form.branch_id,
            warehouse_id: form.warehouse_id,
            price_type: priceType.value,
        });
        await addProduct(product, true);
    } catch (error) {
        const serverMessage = error.response?.data?.message || Object.values(error.response?.data?.errors || {})?.[0]?.[0] || '';
        showToast(serverMessage.includes('Insufficient stock') ? 'Insufficient stock' : serverMessage || 'Product not found', 'error');
    } finally {
        scanning.value = false;
        search.value = '';
        productResults.value = [];
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

const newBill = (options = {}) => {
    const previousSale = lastSale.value;
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
    message.value = '';
    messageTone.value = 'info';
    customerMobileLookup.value = '';
    customerLookup.value = { status: '', message: '', normalized_mobile: '' };
    customerInsight.value = null;
    showQuickCustomer.value = false;
    lastSale.value = options.keepLastSale ? previousSale : null;
    setPaymentMode('cash');
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
            unit: item.unit || 'PCS',
            hsn_code: item.hsn_code_snapshot || item.hsn_code || '',
            quantity: item.quantity,
            free_quantity: item.free_quantity || 0,
            selling_rate: item.selling_rate,
            mrp: item.mrp || '',
            discount_type: item.discount_type || '',
            discount_value: item.discount_value || '',
            gst_rate: item.gst_rate ?? '',
            cess_rate: item.cess_rate ?? '',
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
        if (options.print) printInvoice(response.sale, options.printWindow || null, options.printFormat || defaultPrintFormat.value);
        if (status === 'approved' && printSettings.value.autoPrint && !options.print) printInvoice(response.sale, null, defaultPrintFormat.value);
        if (options.reset) newBill({ keepLastSale: options.keepLastSale });
        return response.sale;
    } catch (error) {
        showToast(error.response?.data?.message || Object.values(error.response?.data?.errors || {})?.[0]?.[0] || 'POS sale could not be saved.', 'error');
        return null;
    } finally {
        saving.value = false;
        savingAction.value = '';
    }
};

const printInvoice = (sale = lastSale.value, targetWindow = null, format = defaultPrintFormat.value) => {
    if (!sale?.id) {
        if (targetWindow) targetWindow.close();
        showToast('Complete or save a sale before printing.', 'error');
        return;
    }
    const printUrl = SalesApi.printUrl(sale.id, format);
    if (targetWindow) {
        targetWindow.opener = null;
        targetWindow.location.href = printUrl;
        return;
    }
    window.open(printUrl, '_blank', 'noopener');
};

const shareLastSaleWhatsApp = async () => {
    if (!lastSale.value?.id) {
        showToast('Complete or save a posted invoice before WhatsApp sharing.', 'error');
        return;
    }

    const shareWindow = window.open('about:blank', '_blank');
    sharingWhatsApp.value = true;
    try {
        let whatsappNumber = selectedCustomer.value?.whatsapp_number
            || selectedCustomer.value?.mobile
            || customerMobileLookup.value
            || lastSale.value.customer_mobile;
        if (!whatsappNumber) {
            whatsappNumber = window.prompt('Enter WhatsApp mobile number for this invoice') || '';
        }
        const response = await SalesApi.whatsappShare(lastSale.value.id, {
            whatsapp_number: whatsappNumber,
        });
        if (shareWindow) {
            shareWindow.opener = null;
            shareWindow.location.href = response.url;
        } else {
            window.location.href = response.url;
        }
        showToast('WhatsApp opened. Share status logged as initiated.', 'success');
    } catch (error) {
        if (shareWindow) shareWindow.close();
        showToast(error.response?.data?.message || Object.values(error.response?.data?.errors || {})?.[0]?.[0] || 'WhatsApp share could not be prepared.', 'error');
    } finally {
        sharingWhatsApp.value = false;
    }
};

const printAndNew = async () => {
    const printWindow = window.open('about:blank', '_blank');
    const sale = await save('approved', { print: true, printWindow, printFormat: defaultPrintFormat.value });
    if (!sale && printWindow) printWindow.close();
    if (sale) newBill({ keepLastSale: true });
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
});
watch(() => form.customer_id, () => {
    updateCustomerTaxTreatment();
    loadCustomerInsight();
});
watch(() => form.invoice_type, (type) => {
    if (type === 'bill_of_supply') {
        form.tax_type = 'exempt';
        return;
    }
    if (form.tax_type === 'exempt') {
        form.tax_type = 'intrastate';
    }
});
watch(() => quickCustomer.whatsapp_same_as_mobile, (checked) => {
    if (checked) quickCustomer.whatsapp_number = quickCustomer.mobile;
});
watch(() => quickCustomer.mobile, (mobile) => {
    if (quickCustomer.whatsapp_same_as_mobile) quickCustomer.whatsapp_number = mobile;
});
watch(customerMobileLookup, () => {
    clearTimeout(customerLookupTimer);
    customerLookupTimer = setTimeout(lookupCustomerByMobile, 450);
});

onMounted(async () => {
    SalesApi.configure(props.endpoints);
    window.addEventListener('keydown', handleShortcut);
    await loadReferences();
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleShortcut);
    if (toastTimer) clearTimeout(toastTimer);
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
	            <AppToast
	                :show="Boolean(message)"
	                title="POS Billing"
	                :message="message"
	                :type="messageTone"
	            />

            <section v-if="lastSale" class="pos-payment-success">
                <div>
                    <span>Payment Successful</span>
                    <strong>Invoice {{ lastSale.invoice_number }}</strong>
                    <small>Grand Total: {{ formatMoney(lastSale.grand_total) }}</small>
                </div>
                <div class="pos-payment-success-actions">
                    <button type="button" class="primary" @click="printInvoice(lastSale, null, defaultPrintFormat)">{{ primaryPrintLabel }}</button>
                    <button type="button" @click="printInvoice(lastSale, null, 'thermal')">Print Receipt</button>
                    <button type="button" @click="printInvoice(lastSale, null, 'a4')">Print A4 Invoice</button>
                    <button type="button" @click="newBill()">New Sale</button>
                </div>
            </section>

            <section class="pos-saas-layout">
                <main class="pos-saas-main">
                    <FilterCard title="Counter Details" eyebrow="BILLING">
                        <div class="pos-print-preview">
                            <div>
                                <strong>TAX INVOICE</strong>
                                <span>{{ invoicePartyLabel }}</span>
                            </div>
                            <small>{{ selectedCustomer?.customer_name || 'Walk-in Customer' }} | {{ selectedCustomer?.gstin || (invoicePartyType === 'B2B' ? 'GSTIN required' : 'No GSTIN') }}</small>
                        </div>
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
                        <label class="bill-field">
                            <span>Bill Type</span>
                            <select v-model="form.invoice_type" title="Select B2B, B2C or bill of supply">
                                <option value="retail_invoice">B2C Retail</option>
                                <option value="tax_invoice">B2B Tax Invoice</option>
                                <option value="bill_of_supply">Bill of Supply</option>
                            </select>
                        </label>
                        <label class="bill-field">
                            <span>Tax Type</span>
                            <span class="bill-status-badge" :class="form.tax_type">{{ form.tax_type }}</span>
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
                        <label class="bill-field pos-customer-field">
                            <span>Mobile / WhatsApp Lookup</span>
                            <input v-model="customerMobileLookup" type="search" placeholder="Enter mobile number" />
                        </label>
                        <div v-if="customerLookup.status" class="pos-customer-lookup" :class="customerLookup.status">
                            {{ customerLookup.message || (customerLookup.status === 'searching' ? 'Searching customer...' : '') }}
                        </div>
                        <div v-if="customerInsight" class="pos-customer-insight">
                            <strong>{{ customerInsight.customer.customer_name }}</strong>
                            <span>{{ customerInsight.summary.customer_status_label }}</span>
                            <small>{{ customerInsight.summary.total_orders }} Orders | {{ formatMoney(customerInsight.summary.lifetime_sales) }} Lifetime Purchase</small>
                            <small>Last Purchase: {{ formatDate(customerInsight.summary.last_purchase_date) }} | Outstanding: {{ formatMoney(customerInsight.summary.outstanding) }}</small>
                            <button type="button" @click="openCustomerHistory">View Purchase History</button>
                        </div>
                        <div v-if="showQuickCustomer" class="pos-quick-customer">
                            <label><span>Name</span><input v-model="quickCustomer.customer_name" placeholder="Customer name" /></label>
                            <label><span>Mobile</span><input v-model="quickCustomer.mobile" placeholder="9876543210" /></label>
                            <label class="check"><input v-model="quickCustomer.whatsapp_same_as_mobile" type="checkbox" /> WhatsApp same as mobile</label>
                            <label v-if="!quickCustomer.whatsapp_same_as_mobile"><span>WhatsApp</span><input v-model="quickCustomer.whatsapp_number" placeholder="WhatsApp number" /></label>
                            <label><span>GSTIN</span><input v-model="quickCustomer.gstin" maxlength="15" placeholder="Optional GSTIN" /></label>
                            <label><span>Email</span><input v-model="quickCustomer.email" placeholder="Optional email" /></label>
                            <label class="wide"><span>Address</span><input v-model="quickCustomer.billing_address" placeholder="Billing address" /></label>
                            <button type="button" @click="quickCreateCustomer">Create & Select</button>
                        </div>
                    </FilterCard>

	                    <FilterCard>
	                        <label class="bill-field pos-product-search">
	                            <input ref="scanInput" v-model="search" class="pos-scan-input" type="search" placeholder="Scan barcode or search product" @focus="scannerFocused = true" @blur="scannerFocused = false" @keyup.enter="scan" @input="searchProducts" />
                            <div v-if="productResults.length" class="pos-autocomplete">
                                <button v-for="product in productResults" :key="product.id" type="button" :title="`Add ${product.name}`" @click="addProduct(product)">
                                    <span class="pos-product-thumb">
                                        <img v-if="product.image_url" :src="product.image_url" :alt="product.name" />
                                        <b v-else>{{ product.name.slice(0, 2).toUpperCase() }}</b>
                                    </span>
                                    <strong>{{ product.name }}</strong>
                                    <small>{{ product.sku || product.barcode || 'No SKU' }} - HSN {{ product.hsn_code || '-' }} - Stock {{ product.available_stock ?? 'Service' }} - GST {{ product.gst_rate || 0 }}% - {{ formatMoney(product.selling_rate) }}</small>
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
                        :line-details="productLineDetails"
                        :highlight-key="highlightedRowKey"
                        @increment="updateQty($event, 1)"
                        @decrement="updateQty($event, -1)"
                        @remove="removeItem"
                        @change="syncPaymentAmount"
	                        @quantity-change="normalizeQty"
	                        @batch-change="updateBatchStock"
	                    >
	                        <template #footer>
	                            <button type="button" class="pos-manual-product-button" @click="showToast('Manual product entry is available through product search for now.', 'info')">+ Add Manual Product</button>
	                            <span class="pos-table-items">{{ form.items.length }} Items</span>
	                            <button type="button" class="pos-clear-cart-button" :disabled="!hasCartItems" @click="form.items = []; syncPaymentAmount()">Clear Cart</button>
	                        </template>
	                    </ProductTable>

                    <FilterCard v-if="heldBills.length" title="Held Bills" eyebrow="RECALL">
                        <template #actions>
                            <span class="bill-status-badge hold">{{ heldBills.length }} held</span>
                        </template>
                        <div class="pos-held-list">
                            <button v-for="bill in heldBills" :key="bill.id" type="button" @click="recallBill(bill)">
                                <span class="pos-held-number">{{ bill.invoice_number }}</span>
                                <span class="pos-held-meta">{{ bill.customer }}</span>
                                <strong>{{ formatMoney(bill.grand_total) }}</strong>
                            </button>
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
	                <div class="pos-footer-cart">
	                    <div class="pos-footer-cart-icon">▣</div>
	                    <div>
	                        <strong>{{ form.items.length }} Items in Cart</strong>
	                        <span>You Saved {{ formatMoney(totals.priceSaving) }} ({{ savingPercent.toFixed(2) }}%)</span>
	                    </div>
	                </div>
	                <div class="pos-footer-metrics">
	                    <div><span>Total Quantity</span><strong>{{ totalQuantity }} Pcs</strong></div>
	                    <div><span>Taxable Value</span><strong>{{ formatMoney(Math.max(0, totals.subtotal - totals.discount)) }}</strong></div>
	                    <div><span>Total Tax</span><strong>{{ formatMoney(totals.tax) }}</strong></div>
	                    <div class="grand"><span>Grand Total</span><strong>{{ formatMoney(totals.grand) }}</strong></div>
	                </div>
	                <div class="pos-footer-actions">
                    <div class="pos-footer-group">
                        <button class="pos-action secondary" type="button" title="Save invoice as draft" :disabled="saving || !hasCartItems" @click="save('draft')">{{ saving && savingAction === 'draft' ? 'Saving...' : 'Save Draft' }}</button>
                        <button class="pos-action secondary" type="button" title="Hold invoice for later recall" :disabled="saving || !hasCartItems" @click="save('hold')">{{ saving && savingAction === 'hold' ? 'Holding...' : 'Hold Bill' }}</button>
                    </div>
                    <div class="pos-footer-group">
                        <button v-if="lastSale" class="pos-action print" type="button" title="Print last completed or saved invoice" @click="printInvoice(lastSale, null, defaultPrintFormat)">{{ primaryPrintLabel }}</button>
                        <button v-if="lastSale" class="pos-action print" type="button" title="Print 80mm thermal receipt" @click="printInvoice(lastSale, null, 'thermal')">80mm</button>
                        <button v-if="lastSale" class="pos-action print" type="button" title="Print A4 GST invoice" @click="printInvoice(lastSale, null, 'a4')">A4</button>
                        <button v-if="lastSale" class="pos-action print" type="button" title="Share last posted invoice through WhatsApp" :disabled="sharingWhatsApp" @click="shareLastSaleWhatsApp">{{ sharingWhatsApp ? 'Opening...' : 'WhatsApp' }}</button>
                        <button class="pos-action print" type="button" title="Complete sale, print invoice and start a new bill" :disabled="saving || !hasCartItems" @click="printAndNew">Print & New</button>
	                        <button class="pos-action complete primary" type="button" title="Complete sale and post invoice" :disabled="saving || !hasCartItems" @click="save('approved', { reset: true, keepLastSale: true })">
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
.pos-saas-page {
    display: grid;
    gap: 12px;
    padding-bottom: 92px;
}

.pos-saas-layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 360px;
    gap: 18px;
    align-items: start;
}

.pos-payment-success {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    padding: 12px 14px;
    border: 1px solid #bbf7d0;
    border-radius: 8px;
    background: #f0fdf4;
}

.pos-payment-success span,
.pos-payment-success small {
    display: block;
    color: #15803d;
    font-size: 11px;
    font-weight: 900;
}

.pos-payment-success strong {
    display: block;
    margin: 3px 0;
    color: #0f172a;
    font-size: 16px;
    font-weight: 950;
}

.pos-payment-success-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 8px;
}

.pos-payment-success-actions button {
    min-height: 38px;
    padding: 8px 12px;
    border: 1px solid #bbd1f4;
    border-radius: 8px;
    background: #fff;
    color: #24446f;
    font-size: 12px;
    font-weight: 900;
    cursor: pointer;
}

.pos-payment-success-actions .primary {
    border-color: #155ee8;
    background: #155ee8;
    color: #fff;
}

.pos-saas-main,
.pos-saas-side {
    display: grid;
    gap: 14px;
    min-width: 0;
}

.pos-saas-side {
    position: sticky;
    top: 86px;
}

.pos-print-preview {
    grid-column: 1 / -1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    min-height: 66px;
    padding: 12px 14px;
    border-bottom: 1px solid #e6edf5;
    background: #ffffff;
}

.pos-print-preview strong {
    display: inline-flex;
    align-items: center;
    min-height: 38px;
    padding: 7px 16px;
    border-radius: 7px;
    background: #082747;
    color: #fff;
    font-size: 14px;
    font-weight: 950;
}

.pos-print-preview span {
    display: inline-flex;
    margin-left: 8px;
    padding: 7px 12px;
    border: 1px solid #bae6d4;
    border-radius: 7px;
    background: #e9fbf3;
    color: #047857;
    font-size: 11px;
    font-weight: 900;
}

.pos-print-preview small {
    color: #0f5de8;
    font-size: 12px;
    font-weight: 900;
    text-align: right;
}

.pos-counter-scope {
    grid-column: span 2;
    display: grid;
    gap: 3px;
    min-height: 54px;
    padding: 10px 12px;
    border: 1px solid var(--bill-line);
    border-radius: 8px;
    background: #f8fafc;
}

.pos-counter-scope span,
.pos-counter-scope strong {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.pos-counter-scope span { color: var(--bill-muted); font-size: 11px; font-weight: 850; }
.pos-counter-scope strong { color: #142139; font-size: 13px; }
.pos-customer-field { grid-column: span 2; }

.pos-customer-lookup,
.pos-customer-insight,
.pos-quick-customer {
    grid-column: 1 / -1;
    border: 1px solid var(--bill-line);
    border-radius: 8px;
    background: #f8fafc;
}

.pos-customer-lookup {
    padding: 9px 10px;
    color: #536179;
    font-size: 12px;
    font-weight: 850;
}

.pos-customer-lookup.found { border-color: #bbf7d0; background: #ecfdf5; color: #15803d; }
.pos-customer-lookup.new { border-color: #bfdbfe; background: #eff6ff; color: #1e40af; }
.pos-customer-lookup.invalid,
.pos-customer-lookup.multiple { border-color: #fecdd3; background: #fff1f2; color: #be123c; }

.pos-customer-insight {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 3px 12px;
    padding: 10px 12px;
}

.pos-customer-insight strong { color: #142139; font-size: 13px; }
.pos-customer-insight span { color: #2457d6; font-size: 11px; font-weight: 900; }
.pos-customer-insight small { color: #64748b; font-size: 11px; }

.pos-customer-insight button,
.pos-quick-customer button,
.pos-manual-product-button,
.pos-clear-cart-button {
    min-height: 36px;
    padding: 7px 11px;
    border: 1px solid #d8e0eb;
    border-radius: 8px;
    background: #fff;
    color: #2457d6;
    font-size: 12px;
    font-weight: 900;
    cursor: pointer;
}

.pos-customer-insight button {
    grid-row: 1 / span 3;
    grid-column: 2;
    align-self: center;
}

.pos-quick-customer {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 8px;
    padding: 10px;
}

.pos-quick-customer label {
    display: grid;
    gap: 4px;
    color: #64748b;
    font-size: 11px;
    font-weight: 850;
}

.pos-quick-customer input { min-height: 36px; }
.pos-quick-customer .wide { grid-column: span 2; }
.pos-quick-customer .check { display: flex; align-items: center; gap: 7px; }
.pos-quick-customer .check input { min-height: auto; width: auto; }
.pos-quick-customer button { border-color: #2457d6; background: #2457d6; color: #fff; }

.pos-product-search {
    position: relative;
    grid-column: 1 / -1;
    display: block;
    width: 100%;
}

.pos-scan-input {
    display: block;
    width: 100% !important;
    min-height: 56px !important;
    border: 2px solid #2f6bff !important;
    border-radius: 8px !important;
    background: #fff !important;
    font-size: 17px !important;
    font-weight: 650;
}

.pos-scan-input:focus {
    border-color: #1457ff !important;
    box-shadow: 0 0 0 4px rgba(36, 87, 214, .12);
    outline: 0;
}

.pos-autocomplete {
    position: absolute;
    top: 82px;
    left: 0;
    right: 0;
    z-index: 12;
    display: grid;
    max-height: 260px;
    overflow: auto;
    border: 1px solid var(--bill-line);
    border-radius: 8px;
    background: #fff;
    box-shadow: 0 18px 40px rgba(15, 34, 66, .14);
}

.pos-autocomplete button {
    display: grid;
    grid-template-columns: 40px 1fr;
    column-gap: 10px;
    align-items: center;
    justify-items: start;
    padding: 9px 10px;
    border: 0;
    border-bottom: 1px solid #edf2f7;
    background: #fff;
    text-align: left;
    cursor: pointer;
}

.pos-autocomplete small { grid-column: 2; color: var(--bill-muted); font-size: 11px; }

.pos-product-thumb {
    width: 36px;
    height: 36px;
    overflow: hidden;
    display: grid;
    place-items: center;
    border-radius: 8px;
    background: #eef2ff;
    color: var(--bill-accent-dark);
    font-size: 11px;
    font-weight: 900;
}

.pos-product-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.pos-recent-products { display: none; }

.pos-held-list {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 8px;
}

.pos-held-list button {
    width: 100%;
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    grid-template-areas: "number amount" "meta amount";
    align-items: center;
    gap: 3px 10px;
    padding: 10px 12px;
    border: 1px solid var(--bill-line);
    border-radius: 8px;
    background: #f8fafc;
    text-align: left;
    cursor: pointer;
}

.pos-held-list button:hover { border-color: #9ec2ff; background: #eef6ff; }
.pos-held-number { grid-area: number; color: #142139; font-size: 12px; font-weight: 900; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pos-held-meta { grid-area: meta; color: var(--bill-muted); font-size: 11px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pos-held-list strong { grid-area: amount; color: #173b77; font-size: 12px; white-space: nowrap; }

.pos-manual-product-button { justify-self: start; }
.pos-clear-cart-button { color: #ef4444; }
.pos-clear-cart-button:disabled { opacity: .45; cursor: not-allowed; }
.pos-table-items { color: #64748b; font-size: 12px; font-weight: 850; }

:deep(.bill-ui-card) {
    border-color: #dfe7f1;
    box-shadow: 0 1px 2px rgba(15, 23, 42, .04), 0 14px 34px rgba(15, 23, 42, .05);
}

:deep(.bill-filter-card:first-child) {
    padding: 0;
    overflow: hidden;
}

:deep(.bill-filter-card:first-child .bill-ui-card-head) {
    display: none;
}

:deep(.bill-filter-grid) {
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px 16px;
}

:deep(.bill-filter-card:first-child .bill-filter-grid) {
    padding: 0 14px 14px;
}

:deep(.bill-filter-card:nth-of-type(2) .bill-filter-grid) {
    display: block;
}

:deep(.bill-filter-card:nth-of-type(2)) {
    padding: 12px 14px;
}

:deep(.bill-filter-grid > .bill-field) {
    grid-column: span 1;
}

:deep(.bill-field > span) {
    color: #334155;
    font-size: 11px;
}

:deep(.bill-ui-card input),
:deep(.bill-ui-card select) {
    min-height: 40px;
}

:deep(.bill-product-table-wrap) {
    max-height: calc(100vh - 420px);
    min-height: 230px;
    border: 1px solid #dfe7f1;
    border-radius: 8px;
    margin: 0 14px;
}

:deep(.bill-product-table-card.is-empty .bill-product-table-wrap) {
    min-height: 170px;
}

:deep(.bill-product-table-card.is-empty .bill-empty-row) {
    height: 118px;
}

:deep(.bill-product-table) {
    min-width: 860px;
}

:deep(.bill-product-table th:nth-child(9)),
:deep(.bill-product-table td:nth-child(9)),
:deep(.bill-product-table th:nth-child(10)),
:deep(.bill-product-table td:nth-child(10)) {
    display: none;
}

:deep(.bill-product-table th) {
    background: #f8fafc;
    color: #475569;
}

:deep(.bill-product-table td) {
    padding: 12px 10px;
}

:deep(.bill-product-table-footer) {
    display: grid;
    grid-template-columns: auto 1fr auto;
    align-items: center;
    gap: 12px;
    padding: 12px 14px 14px;
}

:deep(.bill-summary-card),
:deep(.bill-payment-panel) {
    position: static;
}

:deep(.bill-summary-row) {
    padding: 8px 0;
    font-size: 12px;
}

:deep(.bill-summary-row.divider),
:deep(.bill-summary-row:nth-child(3)) {
    margin-top: 6px;
    padding-top: 13px;
    border-top: 1px dashed #b8c4d6;
}

:deep(.bill-summary-row strong) {
    color: #0f172a;
}

:deep(.bill-summary-row.saving strong) {
    color: #078044;
}

:deep(.bill-summary-row.grand strong),
:deep(.bill-payment-total) {
    color: #155ee8;
    font-size: 25px;
}

:deep(.bill-payment-methods) {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 0;
    overflow: hidden;
    border: 1px solid #dfe7f1;
    border-radius: 8px;
}

:deep(.bill-payment-methods button) {
    border: 0;
    border-right: 1px solid #dfe7f1;
    border-radius: 0;
    min-height: 38px;
    padding: 6px 4px;
    font-size: 11px;
}

:deep(.bill-payment-methods button:last-child) {
    border-right: 0;
}

:deep(.bill-payment-methods button.active) {
    background: #eff6ff;
    color: #155ee8;
}

:deep(.bill-payment-line) {
    grid-template-columns: 1fr 120px;
}

:deep(.bill-payment-balance div:first-child) {
    background: #effaf4;
}

:deep(.bill-payment-balance div:nth-child(2)) {
    background: #fff7ed;
}

:deep(.bill-payment-balance div:nth-child(3)) {
    background: #f5f8ff;
}

:deep(.bill-action-footer) {
    position: fixed;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 40;
    display: grid;
    grid-template-columns: minmax(230px, 1.1fr) minmax(360px, 2.2fr) auto;
    gap: 18px;
    align-items: center;
    padding: 14px 20px;
    border-top: 1px solid #dfe7f1;
    background: #ffffff;
    box-shadow: 0 -18px 44px rgba(15, 23, 42, .12);
}

.pos-footer-cart {
    display: grid;
    grid-template-columns: 52px minmax(0, 1fr);
    gap: 10px;
    align-items: center;
}

.pos-footer-cart-icon {
    display: grid;
    place-items: center;
    width: 52px;
    height: 52px;
    border: 1px solid #d8e3f1;
    border-radius: 8px;
    color: #155ee8;
    font-size: 22px;
}

.pos-footer-cart strong,
.pos-footer-cart span {
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.pos-footer-cart strong {
    color: #0f172a;
    font-size: 13px;
    font-weight: 950;
}

.pos-footer-cart span {
    margin-top: 4px;
    color: #078044;
    font-size: 12px;
    font-weight: 900;
}

.pos-footer-metrics {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 0;
}

.pos-footer-metrics div {
    min-width: 0;
    padding: 0 18px;
    border-left: 1px solid #e2e8f0;
}

.pos-footer-metrics span {
    display: block;
    color: #475569;
    font-size: 11px;
    font-weight: 800;
    text-align: center;
}

.pos-footer-metrics strong {
    display: block;
    margin-top: 4px;
    color: #0f172a;
    font-size: 15px;
    font-weight: 950;
    text-align: center;
}

.pos-footer-metrics .grand strong {
    color: #155ee8;
    font-size: 24px;
    line-height: 1;
}

.pos-footer-actions {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    flex-wrap: nowrap;
}

.pos-footer-group {
    display: flex;
    gap: 10px;
}

.pos-action {
    min-height: 52px;
    padding: 9px 16px;
    border: 1px solid #d8e0eb;
    border-radius: 8px;
    background: #fff;
    color: #344159;
    font-size: 12px;
    font-weight: 900;
    cursor: pointer;
    white-space: nowrap;
}

.pos-action.secondary { background: #ffffff; }
.pos-action.print { color: #24446f; }

.pos-action.complete {
    display: grid;
    grid-template-columns: auto auto;
    gap: 12px;
    align-items: center;
    min-width: 226px;
    border-color: #155ee8;
    background: #155ee8;
    color: #fff;
}

.pos-action.complete strong {
    font-size: 14px;
}

.pos-action:disabled {
    cursor: not-allowed;
    opacity: .52;
}

.pos-action.print:disabled {
    background: #f8fafc;
    color: #94a3b8;
}

@media (min-width: 1500px) {
    .pos-saas-layout {
        grid-template-columns: minmax(0, 1fr) 390px;
    }
}

@media (max-width: 1366px) {
    .pos-saas-layout {
        grid-template-columns: minmax(0, 1fr) 330px;
        gap: 14px;
    }

    .pos-scan-input {
        min-height: 52px !important;
        font-size: 15px !important;
    }

    :deep(.bill-filter-grid) {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }

    :deep(.bill-action-footer) {
        grid-template-columns: minmax(210px, .9fr) minmax(340px, 1.4fr) auto;
        gap: 12px;
        padding: 12px 16px;
    }

    .pos-footer-metrics div {
        padding: 0 12px;
    }

    .pos-action {
        padding-inline: 14px;
    }
}

@media (max-width: 1180px) {
    .pos-saas-layout {
        grid-template-columns: 1fr;
    }

    .pos-saas-side {
        position: static;
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    :deep(.bill-product-table-wrap) {
        max-height: 520px;
    }

    :deep(.bill-action-footer) {
        grid-template-columns: 1fr;
    }

    .pos-footer-actions,
    .pos-footer-group {
        justify-content: stretch;
    }

    .pos-action {
        flex: 1 1 auto;
    }
}

@media (max-width: 760px) {
    .pos-saas-page {
        padding-bottom: 180px;
    }

    .pos-saas-side,
    .pos-held-list {
        grid-template-columns: 1fr;
    }

    .pos-payment-success {
        align-items: stretch;
        flex-direction: column;
    }

    .pos-payment-success-actions {
        justify-content: stretch;
    }

    .pos-payment-success-actions button {
        flex: 1 1 auto;
    }

    .pos-print-preview {
        align-items: stretch;
        flex-direction: column;
    }

    .pos-print-preview small {
        text-align: left;
    }

    .pos-quick-customer,
    .pos-footer-metrics {
        grid-template-columns: 1fr;
    }

    .pos-quick-customer .wide,
    :deep(.bill-filter-grid > .bill-field),
    .pos-customer-field,
    .pos-counter-scope {
        grid-column: auto;
    }

    .pos-customer-insight {
        grid-template-columns: 1fr;
    }

    .pos-customer-insight button {
        grid-row: auto;
        grid-column: auto;
    }

    :deep(.bill-filter-grid) {
        grid-template-columns: 1fr;
    }

    .pos-footer-actions,
    .pos-footer-group {
        display: grid;
        grid-template-columns: 1fr;
    }

    .pos-action {
        width: 100%;
    }
}
</style>
