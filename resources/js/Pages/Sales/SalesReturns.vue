<script setup>
import { computed, nextTick, onMounted, onUnmounted, reactive, ref } from 'vue';
import Layout from '../Layout.vue';
import SalesApi from './SalesApi';
import ActionFooter from '../../Components/Billing/ActionFooter.vue';
import CustomerSelector from '../../Components/Billing/CustomerSelector.vue';
import FilterCard from '../../Components/Billing/FilterCard.vue';
import InvoiceHeader from '../../Components/Billing/InvoiceHeader.vue';
import SummaryCard from '../../Components/Billing/SummaryCard.vue';

const props = defineProps({ page: { type: String, default: 'sales-returns' }, title: { type: String, default: 'Sales Returns' } });

const today = new Date().toISOString().slice(0, 10);
const returns = ref([]);
const references = ref({ customers: [], branches: [], warehouses: [], payment_methods: [] });
const invoiceSearch = ref('');
const invoiceResults = ref([]);
const productSearch = ref('');
const productResults = ref([]);
const selectedInvoice = ref(null);
const loading = ref(false);
const saving = ref(false);
const savingAction = ref('');
const errors = ref({});
const customerSelectorRef = ref(null);
const invoiceSearchRef = ref(null);
const productSearchRef = ref(null);
const refundPanelRef = ref(null);
const lastReturn = ref(null);
let invoiceSearchTimer = null;
let productSearchTimer = null;

const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });
const filters = reactive({ search: '', status: '', return_type: '', settlement_type: '', date_from: '', date_to: '', customer_id: '', branch_id: '', warehouse_id: '' });
const form = reactive({
  id: null,
  return_type: 'against_sale',
  sales_voucher_id: '',
  customer_id: '',
  branch_id: '',
  warehouse_id: '',
  return_date: today,
  tax_type: 'intrastate',
  place_of_supply_state_id: '',
  settlement_type: 'customer_credit',
  reason: '',
  remarks: '',
  items: [],
  refunds: [],
});

const shortcuts = [
  { key: 'F1', label: 'New Bill' },
  { key: 'F2', label: 'Customer' },
  { key: 'F3', label: 'Product Search' },
  { key: 'F4', label: 'Hold' },
  { key: 'F5', label: 'Payment' },
  { key: 'F6', label: 'Print' },
];
const returnTypeOptions = [
  { value: 'against_sale', label: 'Return Against Invoice' },
  { value: 'direct_return', label: 'Return Without Invoice (Direct Return)' },
  { value: 'exchange', label: 'Exchange' },
];
const conditionOptions = [
  { value: 'good', label: 'Saleable' },
  { value: 'damaged', label: 'Damaged' },
  { value: 'expired', label: 'Expired' },
  { value: 'opened', label: 'Open Box' },
  { value: 'defective', label: 'Defective' },
];
const restockOptions = [
  { value: 'restock', label: 'Return to Stock' },
  { value: 'damaged_stock', label: 'Damage Stock' },
  { value: 'scrap', label: 'Scrap' },
  { value: 'vendor_return', label: 'Vendor Return' },
];
const reasonOptions = ['Wrong Item', 'Damaged', 'Expired', 'Customer Changed Mind', 'Quality Issue', 'Other'];
const refundOptions = [
  { key: 'cash', label: 'Cash', settlement: 'cash_refund' },
  { key: 'upi', label: 'UPI', settlement: 'upi_refund' },
  { key: 'card', label: 'Card', settlement: 'card_refund' },
  { key: 'bank', label: 'Bank Transfer', settlement: 'bank_refund' },
  { key: 'credit', label: 'Customer Credit', settlement: 'customer_credit' },
  { key: 'wallet', label: 'Wallet', settlement: 'customer_credit' },
];

const filteredWarehouses = computed(() => !form.branch_id ? references.value.warehouses || [] : (references.value.warehouses || []).filter((w) => Number(w.branch_id || 0) === Number(form.branch_id)));
const selectedCustomer = computed(() => (references.value.customers || []).find((c) => Number(c.id) === Number(form.customer_id)));
const selectedWarehouse = computed(() => (references.value.warehouses || []).find((w) => Number(w.id) === Number(form.warehouse_id)));
const paymentMethods = computed(() => references.value.payment_methods || []);
const activeRefundLabel = computed(() => refundOptions.find((option) => option.settlement === form.settlement_type)?.label || 'Customer Credit');
const isLinkedReturn = computed(() => form.return_type === 'against_sale');

const formatMoney = (value) => `₹${Number(value || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const toNumber = (value) => Number(value || 0);
const itemAmount = (item) => {
  const qty = toNumber(item.quantity);
  if (isLinkedReturn.value && toNumber(item.sold_quantity) > 0 && toNumber(item.line_total) > 0) {
    return toNumber(item.line_total) * qty / toNumber(item.sold_quantity);
  }
  const gross = qty * toNumber(item.selling_rate);
  const discount = Math.min(gross, toNumber(item.discount_amount));
  const taxable = Math.max(0, gross - discount);
  return taxable + (taxable * (toNumber(item.gst_rate) + toNumber(item.cess_rate)) / 100);
};
const itemTax = (item) => {
  const qty = toNumber(item.quantity);
  if (isLinkedReturn.value && toNumber(item.sold_quantity) > 0) {
    return (toNumber(item.taxable_amount) * qty / toNumber(item.sold_quantity)) * toNumber(item.gst_rate) / 100;
  }
  const taxable = Math.max(0, qty * toNumber(item.selling_rate) - toNumber(item.discount_amount));
  return taxable * toNumber(item.gst_rate) / 100;
};
const totals = computed(() => {
  const returnAmount = form.items.reduce((sum, item) => sum + itemAmount(item), 0);
  const discountAdjustment = form.items.reduce((sum, item) => sum + toNumber(item.discount_amount), 0);
  const gstAdjustment = form.items.reduce((sum, item) => sum + itemTax(item), 0);
  const rounded = Math.round(returnAmount);
  const refundAmount = form.refunds.reduce((sum, refund) => sum + toNumber(refund.amount), 0);
  const customerCredit = form.settlement_type === 'customer_credit' ? Math.max(0, rounded - refundAmount) : 0;
  return {
    returnAmount: rounded,
    discountAdjustment,
    gstAdjustment,
    refundAmount,
    customerCredit,
    balanceAdjustment: Math.max(0, rounded - refundAmount - customerCredit),
  };
});
const summaryRows = computed(() => [
  { label: 'Return Amount', value: formatMoney(totals.value.returnAmount) },
  { label: 'Discount Adjustment', value: formatMoney(totals.value.discountAdjustment) },
  { label: 'GST Adjustment', value: formatMoney(totals.value.gstAdjustment) },
  { label: 'Refund Amount', value: formatMoney(totals.value.refundAmount) },
  { label: 'Customer Credit', value: formatMoney(totals.value.customerCredit) },
  { label: 'Balance Adjustment', value: formatMoney(totals.value.balanceAdjustment), grand: true },
]);

const loadReferences = async () => { references.value = await SalesApi.returnReferences(); };
const loadReturns = async (page = 1) => {
  loading.value = true;
  try {
    const response = await SalesApi.salesReturns({ ...filters, page });
    returns.value = response.returns || [];
    pagination.value = response.pagination || pagination.value;
  } finally {
    loading.value = false;
  }
};
const reset = () => {
  Object.assign(form, { id: null, return_type: 'against_sale', sales_voucher_id: '', customer_id: '', branch_id: '', warehouse_id: '', return_date: today, tax_type: 'intrastate', place_of_supply_state_id: '', settlement_type: 'customer_credit', reason: '', remarks: '', items: [], refunds: [] });
  selectedInvoice.value = null;
  invoiceSearch.value = '';
  productSearch.value = '';
  invoiceResults.value = [];
  productResults.value = [];
  errors.value = {};
  lastReturn.value = null;
};
const clearInvoiceContext = () => {
  form.sales_voucher_id = '';
  selectedInvoice.value = null;
  invoiceSearch.value = '';
  invoiceResults.value = [];
  form.items = [];
};
const onReturnTypeChange = () => {
  clearInvoiceContext();
  if (form.return_type !== 'against_sale' && !form.reason) form.reason = 'Customer Changed Mind';
  nextTick(() => (form.return_type === 'against_sale' ? invoiceSearchRef.value : productSearchRef.value)?.focus());
};
const searchInvoices = () => {
  window.clearTimeout(invoiceSearchTimer);
  invoiceSearchTimer = window.setTimeout(async () => {
    if (invoiceSearch.value.trim().length < 2) { invoiceResults.value = []; return; }
    invoiceResults.value = await SalesApi.searchReturnInvoices(invoiceSearch.value.trim(), { customer_id: form.customer_id, branch_id: form.branch_id, warehouse_id: form.warehouse_id });
  }, 220);
};
const selectInvoice = async (invoice) => {
  selectedInvoice.value = invoice;
  form.sales_voucher_id = invoice.id;
  form.customer_id = invoice.customer_id || '';
  form.branch_id = invoice.branch_id || '';
  form.warehouse_id = invoice.warehouse_id || '';
  form.tax_type = invoice.tax_type || 'intrastate';
  form.place_of_supply_state_id = invoice.place_of_supply_state_id || '';
  form.settlement_type = invoice.payment_status === 'paid' ? 'customer_credit' : 'invoice_adjustment';
  form.items = (await SalesApi.salesReturnItems(invoice.id)).map((item) => ({ ...item, return_reason: item.return_reason || 'Wrong Item' }));
  form.refunds = [];
  invoiceSearch.value = invoice.invoice_number;
  invoiceResults.value = [];
};
const searchProducts = () => {
  window.clearTimeout(productSearchTimer);
  productSearchTimer = window.setTimeout(async () => {
    if (productSearch.value.trim().length < 2) { productResults.value = []; return; }
    productResults.value = await SalesApi.searchReturnProducts(productSearch.value.trim(), { branch_id: form.branch_id, warehouse_id: form.warehouse_id });
  }, 180);
};
const addProduct = (product) => {
  const existing = form.items.find((item) => Number(item.product_id) === Number(product.id) && !item.sales_item_id);
  if (existing && [product.barcode, product.sku].includes(productSearch.value.trim())) {
    existing.quantity = toNumber(existing.quantity) + 1;
    productSearch.value = '';
    productResults.value = [];
    return;
  }
  const batch = (product.batches || []).find((row) => toNumber(row.available_stock) > 0) || (product.batches || [])[0];
  form.items.push({
    sales_item_id: null,
    product_id: product.id,
    product: product.name,
    sku: product.sku,
    barcode: product.barcode,
    image_url: product.image_url,
    variants: product.variants || [],
    batches: product.batches || [],
    product_variant_id: '',
    batch_id: batch?.id || '',
    batch: batch?.batch_no || '',
    unit_id: product.unit_id || '',
    sold_quantity: '',
    previously_returned: '',
    available_quantity: '',
    quantity: 1,
    selling_rate: product.selling_rate || 0,
    discount_amount: '',
    gst_rate: product.gst_rate || 0,
    cess_rate: product.cess_rate || 0,
    condition_status: 'good',
    restock_status: 'restock',
    return_reason: 'Customer Changed Mind',
  });
  productSearch.value = '';
  productResults.value = [];
};
const removeItem = (index) => form.items.splice(index, 1);
const setRefundMethod = (option) => {
  form.settlement_type = option.settlement;
  if (option.key === 'credit' || option.key === 'wallet') {
    form.refunds = [];
    return;
  }
  const method = paymentMethods.value.find((m) => String(m.type || '').toLowerCase().includes(option.key) || String(m.name || '').toLowerCase().includes(option.key)) || paymentMethods.value[0];
  if (!method) return;
  form.refunds = [{ payment_method_id: method.id, amount: totals.value.returnAmount, refund_date: today, reference_number: '', notes: option.label }];
};
const removeRefund = (index) => form.refunds.splice(index, 1);
const payload = (status) => ({
  ...form,
  status,
  customer_id: form.customer_id || null,
  sales_voucher_id: isLinkedReturn.value ? form.sales_voucher_id : null,
  branch_id: form.branch_id || null,
  warehouse_id: form.warehouse_id || null,
  place_of_supply_state_id: form.place_of_supply_state_id || null,
});
const saveReturn = async (status = 'draft') => {
  if (saving.value) return;
  saving.value = true;
  savingAction.value = status;
  errors.value = {};
  try {
    const response = await SalesApi.saveSalesReturn(payload(status), form.id);
    const savedReturn = response.return;
    lastReturn.value = savedReturn;
    alert(response.message || 'Sales return saved.');
    if (status === 'approved') printCreditNote(savedReturn);
    reset();
    lastReturn.value = savedReturn;
    await loadReturns();
  } catch (error) {
    if (error.response?.status === 422) {
      errors.value = error.response.data.errors || {};
      alert(Object.values(errors.value)?.[0]?.[0] || 'Please check return fields.');
      return;
    }
    alert(error.response?.data?.message || 'Sales return could not be saved.');
  } finally {
    saving.value = false;
    savingAction.value = '';
  }
};
const saveAndPrintNew = async () => { await saveReturn('approved'); reset(); };
const editReturn = (row) => {
  Object.assign(form, { ...row, items: (row.items || []).map((item) => ({ ...item, return_reason: item.return_reason || 'Other' })), refunds: row.refunds || [] });
  selectedInvoice.value = row.invoice_number ? { invoice_number: row.invoice_number, invoice_date: row.invoice_date, customer: row.customer, payment_status: row.payment_status || '-', grand_total: row.grand_total } : null;
  invoiceSearch.value = row.invoice_number || '';
  lastReturn.value = row;
};
const printCreditNote = (row = null) => {
  const target = row || lastReturn.value;
  if (!target?.id) { alert('Save the return before printing the credit note.'); return; }
  window.open(SalesApi.salesReturnPrintUrl(target.id), '_blank');
};
const refundSavedReturn = async () => {
  const target = lastReturn.value || (form.id ? { id: form.id } : null);
  if (!target?.id || !form.refunds.length) { alert('Select a refund method after saving or approving the return.'); return; }
  const response = await SalesApi.addSalesReturnRefund(target.id, form.refunds[0]);
  lastReturn.value = response.return;
  alert(response.message || 'Refund posted successfully.');
  await loadReturns();
};
const simpleAction = async (fn, row, promptText) => { if (promptText && !window.confirm(promptText)) return; const response = await fn(row.id); alert(response.message || 'Done.'); await loadReturns(pagination.value.current_page || 1); };
const reverseReturn = async (row) => { const remarks = window.prompt('Reversal remarks'); if (!remarks) return; await simpleAction((id) => SalesApi.reverseSalesReturn(id, remarks), row); };
const cancelWork = async () => {
  if (!form.id) { reset(); return; }
  await simpleAction(SalesApi.cancelSalesReturn, { id: form.id }, 'Cancel this draft return?');
  reset();
};
const exchangeMode = () => { form.return_type = 'exchange'; onReturnTypeChange(); };
const exportRows = () => { window.location.href = SalesApi.salesReturnExportUrl(filters); };
const clearFilters = () => { Object.assign(filters, { search: '', status: '', return_type: '', settlement_type: '', date_from: '', date_to: '', customer_id: '', branch_id: '', warehouse_id: '' }); loadReturns(1); };
const statusLabel = (status) => ({ confirmed: 'Approved', reversed: 'Cancelled' }[status] || status || 'Draft');
const refundStatus = (row) => toNumber(row.refund_amount) <= 0 ? 'Pending' : (toNumber(row.refund_amount) >= toNumber(row.grand_total) ? 'Refunded' : 'Partial');
const paymentStatus = (row) => row.settlement_type === 'customer_credit' ? 'Credit' : refundStatus(row);
const handleShortcut = (event) => {
  if (!shortcuts.some((shortcut) => shortcut.key === event.key)) return;
  event.preventDefault();
  if (event.key === 'F1') reset();
  if (event.key === 'F2') customerSelectorRef.value?.focus();
  if (event.key === 'F3') (isLinkedReturn.value ? invoiceSearchRef.value : productSearchRef.value)?.focus();
  if (event.key === 'F4') saveReturn('draft');
  if (event.key === 'F5') refundPanelRef.value?.scrollIntoView({ behavior: 'smooth', block: 'center' });
  if (event.key === 'F6') printCreditNote();
};

onMounted(async () => {
  window.addEventListener('keydown', handleShortcut);
  await loadReferences();
  await loadReturns();
});
onUnmounted(() => window.removeEventListener('keydown', handleShortcut));
</script>

<template>
  <Layout :page="props.page" :title="props.title">
    <template #topbar-title>
      <div class="bill-page-title">
        <span>SALES MANAGEMENT</span>
        <h1>Sales Returns</h1>
        <p>Approve returns, credit notes, refunds and stock adjustments from one billing workspace.</p>
      </div>
    </template>

    <div class="return-saas-page">
      <InvoiceHeader title="Sales Return" subtitle="Tenant-aware credit notes with invoice lookup, barcode entry, inventory posting and refund tracking." :shortcuts="shortcuts" />

      <div class="return-workspace">
        <main class="return-main">
          <FilterCard title="Return Information" eyebrow="RETURN">
            <label class="bill-field">
              <span>Against Sale</span>
              <select v-model="form.return_type" title="Choose return mode" @change="onReturnTypeChange">
                <option v-for="option in returnTypeOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
              </select>
            </label>
            <label class="bill-field"><span>Return Date</span><input v-model="form.return_date" type="date" title="Return date" /></label>
            <label class="bill-field"><span>Status</span><span class="bill-status-badge">{{ form.id ? 'Draft' : 'New' }}</span></label>
            <label class="bill-field"><span>Tax Type</span><select v-model="form.tax_type" title="Tax treatment"><option value="intrastate">Intrastate</option><option value="interstate">Interstate</option><option value="exempt">Exempt</option><option value="nil_rated">Nil Rated</option></select></label>
            <label class="bill-field"><span>Reason</span><select v-model="form.reason" title="Primary return reason"><option value="">Select reason</option><option v-for="reason in reasonOptions" :key="reason" :value="reason">{{ reason }}</option></select></label>
            <label class="bill-field bill-field-wide"><span>Remarks</span><input v-model="form.remarks" placeholder="Internal return remarks" title="Internal return remarks" /></label>
          </FilterCard>

          <div class="return-two-column">
            <CustomerSelector ref="customerSelectorRef" v-model="form.customer_id" :customers="references.customers || []" />
            <FilterCard title="Customer & Original Invoice" eyebrow="INVOICE">
              <label class="bill-field"><span>Branch</span><select v-model="form.branch_id" title="Return branch"><option value="">Select branch</option><option v-for="branch in references.branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option></select></label>
              <label class="bill-field"><span>Warehouse</span><select v-model="form.warehouse_id" title="Return warehouse"><option value="">Select warehouse</option><option v-for="warehouse in filteredWarehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option></select></label>
              <div v-if="isLinkedReturn" class="return-search bill-field-wide">
                <label class="bill-field"><span>Original Invoice</span><input ref="invoiceSearchRef" v-model="invoiceSearch" placeholder="Search invoice number, customer, mobile or GSTIN" title="Search original invoice" @input="searchInvoices" /></label>
                <div v-if="invoiceResults.length" class="return-search-results">
                  <button v-for="invoice in invoiceResults" :key="invoice.id" type="button" title="Load invoice for return" @click="selectInvoice(invoice)">
                    <strong>{{ invoice.invoice_number }}</strong>
                    <span>{{ invoice.customer || 'Walk-in Customer' }} | {{ invoice.invoice_date }} | {{ formatMoney(invoice.grand_total) }} | {{ invoice.payment_status }}</span>
                  </button>
                </div>
              </div>
              <div v-if="selectedInvoice" class="return-invoice-meta bill-field-wide">
                <div><span>Invoice Number</span><strong>{{ selectedInvoice.invoice_number }}</strong></div>
                <div><span>Invoice Date</span><strong>{{ selectedInvoice.invoice_date || '-' }}</strong></div>
                <div><span>Payment Method</span><strong>{{ selectedInvoice.payment_status || '-' }}</strong></div>
                <div><span>Warehouse</span><strong>{{ selectedWarehouse?.name || selectedInvoice.warehouse || '-' }}</strong></div>
              </div>
            </FilterCard>
          </div>

          <section class="bill-ui-card">
            <div class="bill-ui-card-head">
              <div><span>PRODUCT ENTRY</span><h2>Returned Products</h2></div>
              <span class="bill-status-badge">{{ form.items.length }} item{{ form.items.length === 1 ? '' : 's' }}</span>
            </div>
            <div class="return-search">
              <input ref="productSearchRef" v-model="productSearch" :placeholder="isLinkedReturn ? 'Invoice products load automatically. Search direct product for exchange add-on.' : 'Scan barcode or search product, SKU or barcode'" title="Barcode and product search" @keyup.enter="productResults[0] && addProduct(productResults[0])" @input="searchProducts" />
              <div v-if="productResults.length" class="return-search-results">
                <button v-for="product in productResults" :key="product.id" type="button" title="Add returned product" @click="addProduct(product)">
                  <strong>{{ product.name }}</strong>
                  <span>{{ product.sku || '-' }} | {{ product.barcode || 'No barcode' }} | Stock {{ product.available_stock ?? 'Service' }}</span>
                </button>
              </div>
            </div>
            <div class="return-recent-products">
              <button v-for="item in form.items.slice(0, 5)" :key="`${item.product_id}-${item.sales_item_id || item.sku}`" type="button" title="Recently loaded return product" @click="item.quantity = Math.min(toNumber(item.available_quantity || item.quantity || 999), toNumber(item.quantity) + 1)">
                {{ item.product }}
              </button>
            </div>
            <div class="return-table-wrap">
              <table class="return-products-table">
                <thead><tr><th>Image</th><th>Product Name</th><th>SKU</th><th>Barcode</th><th>Batch</th><th>Sold Qty</th><th>Returned Qty</th><th>Available Qty</th><th>Return Qty</th><th>Rate</th><th>Discount</th><th>GST</th><th>Return Amount</th><th>Product Condition</th><th>Restock Option</th><th>Return Reason</th><th>Delete</th></tr></thead>
                <tbody>
                  <tr v-for="(item,index) in form.items" :key="`${item.product_id}-${item.sales_item_id || index}`">
                    <td><img v-if="item.image_url" :src="item.image_url" :alt="item.product" /><span v-else class="return-product-placeholder">{{ (item.product || 'P').slice(0, 1) }}</span></td>
                    <td><strong>{{ item.product }}</strong><small>{{ item.variant || 'Default' }}</small></td>
                    <td>{{ item.sku || '-' }}</td>
                    <td>{{ item.barcode || '-' }}</td>
                    <td><span v-if="isLinkedReturn">{{ item.batch || '-' }}</span><select v-else v-model="item.batch_id" title="Return batch"><option value="">Batch</option><option v-for="batch in item.batches || []" :key="batch.id" :value="batch.id">{{ batch.batch_no }} | {{ batch.expiry_date || '-' }}</option></select></td>
                    <td>{{ item.sold_quantity || '-' }}</td>
                    <td>{{ item.previously_returned || '-' }}</td>
                    <td>{{ item.available_quantity || '-' }}</td>
                    <td><div class="qty-stepper"><button type="button" title="Decrease return quantity" @click="item.quantity = Math.max(0, toNumber(item.quantity) - 1)">-</button><input v-model="item.quantity" type="number" step="0.001" placeholder="Qty" title="Return quantity" :max="item.available_quantity || undefined" /><button type="button" title="Increase return quantity" @click="item.quantity = item.available_quantity ? Math.min(toNumber(item.available_quantity), toNumber(item.quantity) + 1) : toNumber(item.quantity) + 1">+</button></div></td>
                    <td><input v-model="item.selling_rate" type="number" step="0.01" placeholder="Rate" title="Return rate" :disabled="isLinkedReturn" /></td>
                    <td><input v-model="item.discount_amount" type="number" step="0.01" placeholder="Discount" title="Discount adjustment" :disabled="isLinkedReturn" /></td>
                    <td><input v-model="item.gst_rate" type="number" step="0.01" placeholder="GST %" title="GST adjustment" :disabled="isLinkedReturn" /></td>
                    <td><strong>{{ formatMoney(itemAmount(item)) }}</strong></td>
                    <td><select v-model="item.condition_status" title="Product condition"><option v-for="option in conditionOptions" :key="option.value" :value="option.value">{{ option.label }}</option></select></td>
                    <td><select v-model="item.restock_status" title="Inventory restock option"><option v-for="option in restockOptions" :key="option.value" :value="option.value">{{ option.label }}</option></select></td>
                    <td><select v-model="item.return_reason" title="Line return reason"><option value="">Select reason</option><option v-for="reason in reasonOptions" :key="reason" :value="reason">{{ reason }}</option></select></td>
                    <td><button type="button" class="bill-icon-button danger" title="Delete returned product" @click="removeItem(index)">Delete</button></td>
                  </tr>
                  <tr v-if="!form.items.length"><td colspan="17" class="return-empty">Select an invoice or scan a product to start the return.</td></tr>
                </tbody>
              </table>
            </div>
          </section>

          <section ref="refundPanelRef" class="bill-ui-card">
            <div class="bill-ui-card-head"><div><span>REFUND</span><h2>Refund / Credit Note</h2></div><span class="bill-status-badge">{{ activeRefundLabel }}</span></div>
            <div class="return-payment-methods">
              <button v-for="option in refundOptions" :key="option.key" type="button" :class="{ active: form.settlement_type === option.settlement }" :title="`Set refund method to ${option.label}`" @click="setRefundMethod(option)">{{ option.label }}</button>
            </div>
            <div v-if="form.refunds.length" class="return-refund-lines">
              <div v-for="(refund,index) in form.refunds" :key="index" class="return-refund-line">
                <label class="bill-field"><span>Method</span><select v-model="refund.payment_method_id" title="Refund payment method"><option v-for="method in paymentMethods" :key="method.id" :value="method.id">{{ method.name }}</option></select></label>
                <label class="bill-field"><span>Received Amount</span><input v-model="refund.amount" type="number" step="0.01" placeholder="Refund amount" title="Refund amount" /></label>
                <label class="bill-field"><span>Reference</span><input v-model="refund.reference_number" placeholder="UTR, card ref or receipt no" title="Refund reference number" /></label>
                <button type="button" class="danger" title="Remove refund line" @click="removeRefund(index)">Remove</button>
              </div>
            </div>
            <div class="return-balance-grid">
              <div><span>Balance</span><strong>{{ formatMoney(totals.balanceAdjustment) }}</strong></div>
              <div><span>Change</span><strong>{{ formatMoney(Math.max(0, totals.refundAmount - totals.returnAmount)) }}</strong></div>
            </div>
          </section>
        </main>

        <aside class="return-side">
          <SummaryCard title="Return Summary" eyebrow="SUMMARY" :rows="summaryRows">
            <template #badge><span class="bill-status-badge primary">Grand Total</span></template>
          </SummaryCard>
          <section class="bill-ui-card return-actions-card">
            <div class="bill-ui-card-head"><div><span>ACTIONS</span><h2>Invoice Actions</h2></div></div>
            <div class="return-side-actions">
              <button type="button" title="Save return as draft" @click="saveReturn('draft')">Save Draft</button>
              <button type="button" title="Approve return and post inventory/accounting entries" class="primary" @click="saveReturn('approved')">Approve Return</button>
              <button type="button" title="Print credit note" @click="printCreditNote()">Print Credit Note</button>
              <button type="button" title="Post refund against saved return" @click="refundSavedReturn">Refund</button>
              <button type="button" title="Start exchange return" @click="exchangeMode">Exchange</button>
              <button type="button" class="danger" title="Cancel current return" @click="cancelWork">Cancel</button>
            </div>
          </section>
        </aside>
      </div>

      <section class="bill-ui-card return-history">
        <div class="bill-ui-card-head">
          <div><span>HISTORY</span><h2>Return History</h2></div>
          <div class="bill-ui-card-actions"><button type="button" title="Export return history" @click="exportRows">Export</button><button type="button" title="Clear history filters" @click="clearFilters">Clear</button></div>
        </div>
        <div class="return-history-filters">
          <input v-model="filters.search" placeholder="Search credit note, invoice or customer" title="Search return history" @keyup.enter="loadReturns(1)" />
          <input v-model="filters.date_from" type="date" title="From date" @change="loadReturns(1)" />
          <input v-model="filters.date_to" type="date" title="To date" @change="loadReturns(1)" />
          <select v-model="filters.status" title="Return status filter" @change="loadReturns(1)"><option value="">All Status</option><option value="draft">Draft</option><option value="confirmed">Pending</option><option value="approved">Approved</option><option value="refunded">Refunded</option><option value="cancelled">Cancelled</option></select>
          <select v-model="filters.return_type" title="Return type filter" @change="loadReturns(1)"><option value="">All Types</option><option value="against_sale">Return Against Invoice</option><option value="direct_return">Direct Return</option><option value="exchange">Exchange</option></select>
        </div>
        <div class="return-table-wrap compact">
          <table class="return-products-table">
            <thead><tr><th>Credit Note Number</th><th>Invoice Number</th><th>Customer</th><th>Refund Status</th><th>Payment Status</th><th>Return Status</th><th>Amount</th><th>Actions</th></tr></thead>
            <tbody>
              <tr v-for="row in returns" :key="row.id">
                <td><strong>{{ row.credit_note_number }}</strong><small>{{ row.return_date }}</small></td>
                <td>{{ row.invoice_number || '-' }}</td>
                <td>{{ row.customer || 'Walk-in Customer' }}</td>
                <td><span class="bill-status-badge">{{ refundStatus(row) }}</span></td>
                <td><span class="bill-status-badge">{{ paymentStatus(row) }}</span></td>
                <td><span class="bill-status-badge" :class="row.status">{{ statusLabel(row.status) }}</span></td>
                <td>{{ formatMoney(row.grand_total) }}</td>
                <td><div class="return-row-actions"><button type="button" title="Print credit note" @click="printCreditNote(row)">Print</button><button v-if="row.status === 'draft'" type="button" title="Edit draft return" @click="editReturn(row)">Edit</button><button v-if="row.status === 'draft'" type="button" title="Approve draft return" @click="simpleAction(SalesApi.approveSalesReturn,row,'Approve this return?')">Approve</button><button v-if="row.status === 'draft'" type="button" class="danger" title="Cancel draft return" @click="simpleAction(SalesApi.cancelSalesReturn,row,'Cancel this draft?')">Cancel</button><button v-if="['approved','confirmed'].includes(row.status)" type="button" class="danger" title="Reverse posted return" @click="reverseReturn(row)">Reverse</button></div></td>
              </tr>
              <tr v-if="!returns.length && !loading"><td colspan="8" class="return-empty">No returns found for the selected filters.</td></tr>
            </tbody>
          </table>
        </div>
        <div v-if="pagination.total > 0 && pagination.last_page > 1" class="return-pagination"><button :disabled="pagination.current_page <= 1" title="Previous page" @click="loadReturns(pagination.current_page - 1)">Previous</button><span>{{ pagination.from || 0 }}-{{ pagination.to || 0 }} of {{ pagination.total || 0 }}</span><button :disabled="pagination.current_page >= pagination.last_page" title="Next page" @click="loadReturns(pagination.current_page + 1)">Next</button></div>
      </section>

      <div v-if="Object.keys(errors).length" class="return-error-box"><span v-for="(messages, field) in errors" :key="field">{{ messages[0] }}</span></div>
      <ActionFooter>
        <button type="button" title="Save draft return" :disabled="saving" @click="saveReturn('draft')">{{ saving && savingAction === 'draft' ? 'Saving...' : 'Save Draft' }}</button>
        <button type="button" title="Hold return as pending draft" :disabled="saving" @click="saveReturn('draft')">Hold Invoice</button>
        <button type="button" title="Print credit note" @click="printCreditNote()">Print</button>
        <button type="button" title="Approve, print and start a new return" :disabled="saving" @click="saveAndPrintNew">Print & New</button>
        <button type="button" class="primary" title="Complete sale return and post stock, credit note, voucher and ledger entries" :disabled="saving" @click="saveReturn('approved')">{{ saving && savingAction === 'approved' ? 'Completing...' : 'Complete Sale' }}</button>
      </ActionFooter>
    </div>
  </Layout>
</template>

<style scoped>
.return-saas-page{padding:4px 0 92px}.return-workspace{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:14px;align-items:start}.return-main{display:grid;gap:14px}.return-side{position:sticky;top:84px;display:grid;gap:14px}.return-two-column{display:grid;grid-template-columns:minmax(280px,.8fr) minmax(420px,1.2fr);gap:14px}.bill-field-wide{grid-column:1/-1}.return-search{position:relative}.return-search>input{width:100%}.return-search-results{position:absolute;z-index:30;top:calc(100% + 6px);left:0;right:0;display:grid;max-height:260px;overflow:auto;background:#fff;border:1px solid #dfe6ef;border-radius:8px;box-shadow:0 18px 42px rgba(16,24,40,.16)}.return-search-results button{display:grid;gap:3px;justify-items:start;padding:11px 12px;border:0;border-bottom:1px solid #eef2f6;border-radius:0;background:#fff;text-align:left}.return-search-results span,.return-products-table small{display:block;color:#7a869a;font-size:11px}.return-invoice-meta,.return-balance-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}.return-invoice-meta div,.return-balance-grid div{padding:10px;background:#f8fafc;border:1px solid #e7ecf2;border-radius:8px}.return-invoice-meta span,.return-balance-grid span{display:block;color:#69758a;font-size:10px;font-weight:800;text-transform:uppercase}.return-invoice-meta strong,.return-balance-grid strong{color:#142139;font-size:12px}.return-recent-products,.return-payment-methods,.return-history-filters,.return-row-actions,.return-pagination{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.return-recent-products{margin-top:10px}.return-recent-products button,.return-payment-methods button{min-height:34px;padding:7px 10px;background:#f8fafc;border:1px solid #e4eaf2;border-radius:8px;color:#344159;font-size:12px;font-weight:750}.return-payment-methods button.active{color:#2457d6;background:#edf4ff;border-color:#b8cdf8}.return-table-wrap{max-height:520px;margin-top:12px;overflow:auto;border:1px solid #e4eaf2;border-radius:8px}.return-table-wrap.compact{max-height:460px}.return-products-table{width:100%;min-width:1560px;border-collapse:separate;border-spacing:0}.return-products-table th{position:sticky;top:0;z-index:4;padding:11px 10px;color:#65758b;background:#f8fafc;border-bottom:1px solid #e4eaf2;text-align:left;white-space:nowrap;font-size:10px;font-weight:850;text-transform:uppercase}.return-products-table td{padding:10px;border-bottom:1px solid #edf1f5;color:#27344c;vertical-align:middle;white-space:nowrap;font-size:12px}.return-products-table img,.return-product-placeholder{width:36px;height:36px;border-radius:8px;object-fit:cover}.return-product-placeholder{display:grid;place-items:center;color:#2457d6;background:#edf4ff;font-weight:850}.return-products-table input,.return-products-table select,.return-history-filters input,.return-history-filters select{min-height:34px;padding:7px 9px;border:1px solid #d8e0eb;border-radius:8px;background:#fff;color:#344159;font-size:12px}.return-products-table input{width:86px}.qty-stepper{display:grid;grid-template-columns:30px 74px 30px;gap:4px;align-items:center}.qty-stepper button,.bill-icon-button,.return-row-actions button,.return-pagination button,.return-side-actions button,.return-refund-line button,.return-history button,.bill-action-footer button{min-height:34px;padding:7px 10px;border:1px solid #d8e0eb;border-radius:8px;background:#fff;color:#344159;font-size:12px;font-weight:800;cursor:pointer}.return-side-actions{display:grid;gap:8px}.primary,.return-side-actions .primary,.bill-action-footer .primary{color:#fff!important;background:#2457d6!important;border-color:#2457d6!important}.danger{color:#d23f49!important;background:#fff3f4!important;border-color:#ffd6da!important}.return-refund-lines{display:grid;gap:10px;margin-top:12px}.return-refund-line{display:grid;grid-template-columns:1fr 150px 1fr auto;gap:10px;align-items:end}.return-history{margin-top:14px}.return-pagination{justify-content:flex-end;margin-top:12px}.return-empty{padding:32px!important;color:#8490a2;text-align:center}.return-error-box{display:grid;gap:4px;margin-top:12px;padding:10px;color:#96333a;background:#fff3f4;border:1px solid #ffd4d8;border-radius:8px;font-size:11px}.bill-status-badge.primary{color:#2457d6;background:#edf4ff}.bill-status-badge.approved,.bill-status-badge.confirmed{color:#168757;background:#eaf8f1}.bill-status-badge.cancelled,.bill-status-badge.reversed{color:#d23f49;background:#fff3f4}@media(max-width:1180px){.return-workspace,.return-two-column{grid-template-columns:1fr}.return-side{position:static}.return-invoice-meta,.return-balance-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:720px){.return-saas-page{padding-bottom:130px}.return-refund-line,.return-invoice-meta,.return-balance-grid{grid-template-columns:1fr}.return-products-table{min-width:1380px}.return-history-filters>*{width:100%}}
</style>
