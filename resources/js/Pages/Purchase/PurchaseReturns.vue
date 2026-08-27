<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import Layout from '../Layout.vue';
import PurchaseApi from './PurchaseApi';
import SearchSelect from '../../Components/Common/SearchSelect.vue';

defineProps({ page: { type: String, default: 'purchase-returns' }, title: { type: String, default: 'Purchase Returns' } });

const today = new Date().toISOString().slice(0, 10);
const returns = ref([]);
const references = ref({ suppliers: [], branches: [], warehouses: [] });
const purchaseSearch = ref('');
const purchaseResults = ref([]);
const productSearch = ref('');
const productResults = ref([]);
const loading = ref(false);
const saving = ref(false);
const errors = ref({});
const showReturnDrawer = ref(false);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });
const filters = reactive({ status: '', return_type: '', settlement_type: '' });
const returnTypes = [{ value: 'against_purchase', label: 'Against Purchase' }, { value: 'direct_return', label: 'Direct Return' }];
const returnTypeFilters = [{ value: '', label: 'All Types' }, ...returnTypes];
const taxTypes = [{ value: 'intrastate', label: 'Intrastate' }, { value: 'interstate', label: 'Interstate' }, { value: 'exempt', label: 'Exempt' }];
const settlementTypes = [
    { value: 'supplier_credit', label: 'Supplier Credit' },
    { value: 'cash_refund', label: 'Cash Refund' },
    { value: 'bank_refund', label: 'Bank Refund' },
    { value: 'adjustment', label: 'Adjustment' },
    { value: 'pending', label: 'Pending' },
];
const settlementTypeFilters = [{ value: '', label: 'All Settlements' }, ...settlementTypes];
const statusFilters = [{ value: '', label: 'All Status' }, { value: 'draft', label: 'Draft' }, { value: 'approved', label: 'Approved' }, { value: 'cancelled', label: 'Cancelled' }, { value: 'reversed', label: 'Reversed' }];
const form = reactive({
    id: null,
    return_type: 'against_purchase',
    purchase_voucher_id: '',
    purchase_number: '',
    supplier_id: '',
    branch_id: '',
    warehouse_id: '',
    return_date: today,
    supplier_debit_note_number: '',
    tax_type: 'intrastate',
    settlement_type: 'supplier_credit',
    settlement_amount: '',
    reason: '',
    remarks: '',
    items: [],
});

const filteredWarehouses = computed(() => {
    if (!form.branch_id) return references.value.warehouses || [];
    return (references.value.warehouses || []).filter((w) => Number(w.branch_id || 0) === Number(form.branch_id));
});

const reset = () => {
    Object.assign(form, {
        id: null, return_type: 'against_purchase', purchase_voucher_id: '', purchase_number: '', supplier_id: '',
        branch_id: '', warehouse_id: '', return_date: today, supplier_debit_note_number: '', tax_type: 'intrastate',
        settlement_type: 'supplier_credit', settlement_amount: '', reason: '', remarks: '', items: [],
    });
    purchaseSearch.value = '';
    productSearch.value = '';
    purchaseResults.value = [];
    productResults.value = [];
    errors.value = {};
};

const openReturnCreate = () => {
    reset();
    showReturnDrawer.value = true;
};

const closeReturnDrawer = () => {
    showReturnDrawer.value = false;
    purchaseResults.value = [];
    productResults.value = [];
    errors.value = {};
};

const loadReferences = async () => { references.value = await PurchaseApi.returnReferences(); };
const loadReturns = async (page = 1) => {
    loading.value = true;
    try {
        const response = await PurchaseApi.purchaseReturns({ ...filters, page });
        returns.value = response.returns || [];
        pagination.value = response.pagination || pagination.value;
    } finally { loading.value = false; }
};

const clearFilters = async () => {
    Object.assign(filters, { status: '', return_type: '', settlement_type: '' });
    await loadReturns(1);
};

const searchPurchases = async () => {
    if (purchaseSearch.value.trim().length < 2) { purchaseResults.value = []; return; }
    purchaseResults.value = await PurchaseApi.searchReturnPurchases(purchaseSearch.value.trim());
};

const selectPurchase = async (purchase) => {
    form.purchase_voucher_id = purchase.id;
    form.purchase_number = purchase.voucher_number;
    form.supplier_id = purchase.supplier_id;
    form.branch_id = purchase.branch_id || '';
    form.warehouse_id = purchase.warehouse_id || '';
    form.tax_type = purchase.tax_type || 'intrastate';
    form.items = await PurchaseApi.purchaseReturnItems(purchase.id);
    purchaseSearch.value = purchase.voucher_number;
    purchaseResults.value = [];
};

const searchProducts = async () => {
    if (productSearch.value.trim().length < 2) { productResults.value = []; return; }
    productResults.value = await PurchaseApi.searchReturnProducts(productSearch.value.trim());
};

const addProduct = (product) => {
    form.items.push({
        purchase_item_id: null,
        product_id: product.id,
        product: product.name,
        sku: product.sku,
        variants: product.variants || [],
        batches: product.batches || [],
        tracking_type: product.tracking_type || 'none',
        batch_required: Boolean(product.batch_required),
        product_variant_id: '',
        batch_id: '',
        unit_id: product.unit_id || '',
        purchased_quantity: '-',
        previously_returned: '-',
        available_quantity: '-',
        quantity: 1,
        purchase_rate: product.purchase_rate || 0,
        discount_amount: 0,
        gst_rate: product.gst_rate || 0,
        cess_rate: product.cess_rate || 0,
        reason: '',
    });
    productSearch.value = '';
    productResults.value = [];
};

const removeItem = (index) => form.items.splice(index, 1);
const payload = (status) => ({ ...form, status, branch_id: form.branch_id || null, warehouse_id: form.warehouse_id || null, purchase_voucher_id: form.return_type === 'against_purchase' ? form.purchase_voucher_id : null });

const saveReturn = async (status = 'draft') => {
    if (saving.value) return;
    saving.value = true;
    errors.value = {};
    try {
        const response = await PurchaseApi.savePurchaseReturn(payload(status), form.id);
        alert(response.message || 'Purchase return saved.');
        reset();
        closeReturnDrawer();
        await loadReturns();
    } catch (error) {
        if (error.response?.status === 422) {
            errors.value = error.response.data.errors || {};
            alert(Object.values(errors.value)?.[0]?.[0] || 'Please check purchase return fields.');
            return;
        }
        alert(error.response?.data?.message || 'Purchase return save nahi ho saka.');
    } finally { saving.value = false; }
};

const editReturn = (row) => {
    Object.assign(form, {
        id: row.id,
        return_type: row.return_type,
        purchase_voucher_id: row.purchase_voucher_id || '',
        purchase_number: row.purchase_number || '',
        supplier_id: row.supplier_id,
        branch_id: row.branch_id || '',
        warehouse_id: row.warehouse_id || '',
        return_date: row.return_date || today,
        supplier_debit_note_number: row.supplier_debit_note_number || '',
        tax_type: row.tax_type,
        settlement_type: row.settlement_type,
        settlement_amount: row.settlement_amount || '',
        reason: row.reason || '',
        remarks: row.remarks || '',
        items: (row.items || []).map((item) => ({
            ...item,
            purchased_quantity: '',
            previously_returned: '',
            available_quantity: '',
        })),
    });
    showReturnDrawer.value = true;
};

const simpleAction = async (fn, row, promptText) => {
    if (promptText && !window.confirm(promptText)) return;
    const response = await fn(row.id);
    alert(response.message || 'Done.');
    await loadReturns(pagination.value.current_page || 1);
};

const reverseReturn = async (row) => {
    const remarks = window.prompt('Reversal remarks');
    if (!remarks) return;
    const response = await PurchaseApi.reversePurchaseReturn(row.id, remarks);
    alert(response.message || 'Purchase return reversed.');
    await loadReturns(pagination.value.current_page || 1);
};

const formatMoney = (value) => Number(value || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

onMounted(async () => { await loadReferences(); await loadReturns(); });
</script>

<template>
    <Layout :page="page" :title="title">
        <template #topbar-title>
            <div class="bill-page-title"><span>PURCHASE MANAGEMENT</span><h1>Purchase Returns</h1><p>Return stock against purchases or create authorized direct supplier returns.</p></div>
        </template>
        <div class="purchase-page">
            <div v-if="showReturnDrawer" class="drawer-backdrop" @click.self="closeReturnDrawer">
                <aside class="drawer-panel">
                    <div class="drawer-heading">
                        <div><span>PURCHASE MANAGEMENT</span><h2>{{ form.id ? 'Edit Return' : 'New Return' }}</h2></div>
                        <button type="button" @click="closeReturnDrawer">Close</button>
                    </div>

                    <div class="form-grid">
                        <SearchSelect v-model="form.return_type" label="Return Type" :options="returnTypes" option-value-key="value" option-label-key="label" select-placeholder="Select Return Type" required />
                        <SearchSelect v-model="form.supplier_id" label="Supplier" :options="references.suppliers" option-value-key="id" option-label-key="supplier_name" select-placeholder="Select Supplier" required />
                        <SearchSelect v-model="form.branch_id" label="Branch" :options="references.branches" option-value-key="id" option-label-key="name" select-placeholder="Select Branch" :required="form.return_type === 'direct_return'" />
                        <SearchSelect v-model="form.warehouse_id" label="Warehouse" :options="filteredWarehouses" option-value-key="id" option-label-key="name" select-placeholder="Select Warehouse" :required="form.return_type === 'direct_return'" />
                        <label><span>Return Date <span class="required-mark">*</span></span><input v-model="form.return_date" type="date" /></label>
                        <label>Supplier Debit Note No<input v-model="form.supplier_debit_note_number" placeholder="Supplier Debit Note No" /></label>
                        <SearchSelect v-model="form.tax_type" label="Tax Type" :options="taxTypes" option-value-key="value" option-label-key="label" select-placeholder="Select Tax Type" required />
                        <SearchSelect v-model="form.settlement_type" label="Settlement Type" :options="settlementTypes" option-value-key="value" option-label-key="label" select-placeholder="Select Settlement" required />
                        <label>Settlement Amount<input v-model="form.settlement_amount" type="number" min="0" step="0.01" placeholder="Settlement Amount" /></label>
                        <label>Reason<input v-model="form.reason" placeholder="Reason" /></label>
                        <label>Remarks<input v-model="form.remarks" placeholder="Remarks" /></label>
                    </div>

                    <div v-if="form.return_type === 'against_purchase'" class="search-box">
                        <input v-model="purchaseSearch" placeholder="Search approved purchase voucher" @input="searchPurchases" />
                        <div v-if="purchaseResults.length" class="search-results">
                            <button v-for="purchase in purchaseResults" :key="purchase.id" type="button" @click="selectPurchase(purchase)">
                                <strong>{{ purchase.voucher_number }}</strong><span>{{ purchase.supplier }} | {{ purchase.purchase_date }}</span>
                            </button>
                        </div>
                    </div>

                    <div v-else class="search-box">
                        <strong class="direct-label">Direct return: not linked to original purchase</strong>
                        <input v-model="productSearch" placeholder="Search product by name, SKU or barcode" @input="searchProducts" />
                        <div v-if="productResults.length" class="search-results">
                            <button v-for="product in productResults" :key="product.id" type="button" @click="addProduct(product)">
                                <strong>{{ product.name }}</strong><span>{{ product.sku }} | {{ product.barcode || 'No barcode' }}</span>
                            </button>
                        </div>
                    </div>

                    <div class="table-wrapper">
                        <table>
                            <thead><tr><th>Product</th><th>Variant</th><th>Batch</th><th>Purchased</th><th>Returned</th><th>Available</th><th>Return Qty <span class="required-mark">*</span></th><th>Rate <span class="required-mark">*</span></th><th>Discount</th><th>GST</th><th>Reason</th><th></th></tr></thead>
                            <tbody>
                                <tr v-for="(item, index) in form.items" :key="`${item.product_id}-${index}`">
                                    <td><strong>{{ item.product }}</strong><span>{{ item.sku }}</span></td>
                                    <td>
                                        <span v-if="form.return_type === 'against_purchase'">{{ item.variant || '-' }}</span>
                                        <select v-else v-model="item.product_variant_id">
                                            <option value="">No Variant</option>
                                            <option v-for="variant in item.variants || []" :key="variant.id" :value="variant.id">{{ variant.sku || variant.barcode || variant.id }}</option>
                                        </select>
                                    </td>
                                    <td>
                                        <span v-if="form.return_type === 'against_purchase'">{{ item.batch || item.batch_id || '-' }}</span>
                                        <select v-else-if="(item.batches || []).length" v-model="item.batch_id">
                                            <option value="">Batch</option>
                                            <option v-for="batch in item.batches || []" :key="batch.id" :value="batch.id">{{ batch.batch_no }}{{ batch.expiry_date ? ` | ${batch.expiry_date}` : '' }}</option>
                                        </select>
                                        <input v-else v-model="item.batch_id" type="number" placeholder="Batch ID" />
                                    </td>
                                    <td>{{ item.purchased_quantity }}</td>
                                    <td>{{ item.previously_returned }}</td>
                                    <td>{{ item.available_quantity }}</td>
                                    <td><input v-model="item.quantity" type="number" min="0.001" step="0.001" /></td>
                                    <td><input v-model="item.purchase_rate" type="number" min="0" step="0.01" :disabled="form.return_type === 'against_purchase'" /></td>
                                    <td><input v-model="item.discount_amount" type="number" min="0" step="0.01" /></td>
                                    <td><input v-model="item.gst_rate" type="number" min="0" step="0.01" :disabled="form.return_type === 'against_purchase'" /></td>
                                    <td><input v-model="item.reason" /></td>
                                    <td><button type="button" class="danger" @click="removeItem(index)">Remove</button></td>
                                </tr>
                                <tr v-if="!form.items.length"><td colspan="12" class="empty">Select a purchase or add direct return products.</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-if="Object.keys(errors).length" class="error-box"><span v-for="(messages, field) in errors" :key="field">{{ messages[0] }}</span></div>
                    <div class="actions"><button type="button" @click="closeReturnDrawer">Cancel</button><button :disabled="saving" @click="saveReturn('draft')">Save Draft</button><button class="primary" :disabled="saving" @click="saveReturn('approved')">{{ saving ? 'Saving...' : 'Confirm & Post' }}</button></div>
                </aside>
            </div>

            <section class="panel">
                <div class="toolbar">
                    <div class="filter-group">
                        <SearchSelect v-model="filters.status" label="Status" :options="statusFilters" option-value-key="value" option-label-key="label" select-placeholder="All Status" @selected="loadReturns(1)" />
                        <SearchSelect v-model="filters.return_type" label="Type" :options="returnTypeFilters" option-value-key="value" option-label-key="label" select-placeholder="All Types" @selected="loadReturns(1)" />
                        <SearchSelect v-model="filters.settlement_type" label="Settlement" :options="settlementTypeFilters" option-value-key="value" option-label-key="label" select-placeholder="All Settlements" @selected="loadReturns(1)" />
                        <button type="button" class="primary" @click="loadReturns(1)">Search</button>
                        <button type="button" class="ghost-action" @click="clearFilters">Clear</button>
                    </div>
                    <button type="button" class="primary" @click="openReturnCreate">New Return</button>
                </div>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>Return Voucher</th><th>Date</th><th>Original Purchase</th><th>Supplier</th><th>Branch</th><th>Warehouse</th><th>Type</th><th>Total</th><th>Settlement</th><th>Status</th><th>Created By</th><th>Actions</th></tr></thead>
                        <tbody :class="{ 'is-loading': loading }">
                            <template v-if="loading">
                                <tr v-for="index in 4" :key="`return-loading-${index}`" class="loading-row">
                                    <td v-for="cell in 12" :key="cell"><span class="skeleton-line" :class="`w-${(cell % 4) + 1}`"></span></td>
                                </tr>
                            </template>
                            <tr v-for="row in returns" :key="row.id">
                                <td>{{ row.voucher_number }}</td><td>{{ row.return_date }}</td><td>{{ row.purchase_number || '-' }}</td><td>{{ row.supplier }}</td><td>{{ row.branch || '-' }}</td><td>{{ row.warehouse || '-' }}</td><td>{{ row.return_type }}</td><td>Rs. {{ formatMoney(row.grand_total) }}</td><td>{{ row.settlement_type }}</td><td><span class="badge" :class="row.status">{{ row.status }}</span></td><td>{{ row.created_by || '-' }}</td>
                                <td><div class="row-actions"><button v-if="row.status === 'draft'" @click="editReturn(row)">Edit</button><button v-if="row.status === 'draft'" @click="simpleAction(PurchaseApi.approvePurchaseReturn, row, 'Post return?')">Post</button><button v-if="row.status === 'draft'" class="danger" @click="simpleAction(PurchaseApi.cancelPurchaseReturn, row, 'Cancel draft?')">Cancel</button><button v-if="['approved','confirmed'].includes(row.status)" class="danger" @click="reverseReturn(row)">Reverse</button></div></td>
                            </tr>
                            <tr v-if="!returns.length && !loading"><td colspan="12" class="empty">No purchase returns found.</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="pagination"><button :disabled="pagination.current_page <= 1" @click="loadReturns(pagination.current_page - 1)">Previous</button><span>{{ pagination.from || 0 }}-{{ pagination.to || 0 }} of {{ pagination.total || 0 }}</span><button :disabled="pagination.current_page >= pagination.last_page" @click="loadReturns(pagination.current_page + 1)">Next</button></div>
            </section>
        </div>
    </Layout>
</template>

<style scoped>
.purchase-page{padding:4px 0 28px}.page-heading,.toolbar,.actions,.pagination,.row-actions,.drawer-heading{display:flex;align-items:center;justify-content:space-between;gap:10px}.page-heading{margin-bottom:18px}.page-heading span,.drawer-heading span{color:#2457d6;font-size:10px;font-weight:800;letter-spacing:1.2px}.page-heading h1{margin:0;color:#142139;font-weight:800}.page-heading p{margin:6px 0 0;color:#758197;font-size:13px}.panel{margin-bottom:18px;padding:18px;background:#fff;border:1px solid #dfe6ef;border-radius:8px}.form-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.form-grid label{display:grid;gap:5px;color:#667085;font-size:11px;font-weight:800}.form-grid :deep(.search-select){display:block}.toolbar :deep(.search-select){width:220px;min-width:0}.toolbar :deep(.search-select-hint){display:none}.required-mark{color:#dc2626;font-weight:900;margin-left:3px}input,select,button{min-height:38px;padding:8px 10px;color:#344159;background:#fff;border:1px solid #d8e0eb;border-radius:8px;font-size:12px}button{font-weight:750;cursor:pointer}.primary{color:#fff;background:#2457d6;border-color:#2457d6}.danger{color:#d23f49;background:#fff3f4;border-color:#ffd6da}.search-box{position:relative;margin:14px 0}.search-box input{width:100%}.direct-label{display:block;margin-bottom:8px;color:#7a5b10;font-size:11px}.search-results{position:absolute;z-index:20;top:44px;left:0;right:0;display:grid;max-height:220px;overflow:auto;background:#fff;border:1px solid #dce4ef;border-radius:9px;box-shadow:0 12px 30px rgba(15,34,66,.12)}.search-results button{display:grid;justify-items:start;border:0;border-bottom:1px solid #eef2f6;border-radius:0}.table-wrapper{overflow-x:auto}table{width:100%;border-collapse:collapse}th{padding:12px 10px;color:#69758a;background:#f8fafc;border-bottom:1px solid #e7ecf2;text-align:left;white-space:nowrap;font-size:10px;font-weight:800;text-transform:uppercase}td{padding:12px 10px;color:#27344c;border-bottom:1px solid #edf1f5;white-space:nowrap;font-size:12px}td input{min-width:86px}td span,.search-results span{display:block;color:#7a869a;font-size:10px}.actions,.pagination{justify-content:flex-end;margin-top:12px}.toolbar{justify-content:space-between;margin-bottom:12px;flex-wrap:wrap}.filter-group{display:flex;align-items:end;gap:10px;flex-wrap:wrap}.ghost-action{color:#475569;background:#f8fafc;border-color:#dfe6ef}.toolbar > .primary{margin-left:auto;white-space:nowrap}.badge{padding:5px 8px;border-radius:7px;background:#edf2ff;color:#2457d6;font-size:10px;font-weight:800;text-transform:capitalize}.badge.approved,.badge.confirmed{color:#168757;background:#eaf8f1}.badge.cancelled,.badge.reversed{color:#d23f49;background:#fff3f4}.empty{padding:28px!important;color:#8490a2;text-align:center}.error-box{display:grid;gap:4px;margin-top:12px;padding:10px;color:#96333a;background:#fff3f4;border:1px solid #ffd4d8;border-radius:8px;font-size:11px}.is-loading tr:not(.loading-row){opacity:.38}.loading-row td{padding:14px 10px}.skeleton-line{display:block;width:100%;height:12px;border-radius:999px;background:linear-gradient(90deg,#eef3fb 25%,#f8fbff 38%,#eef3fb 63%);background-size:240% 100%;animation:shimmer 1.05s ease-in-out infinite}.skeleton-line.w-1{width:46%}.skeleton-line.w-2{width:64%}.skeleton-line.w-3{width:78%}.skeleton-line.w-4{width:92%}@keyframes shimmer{0%{background-position:120% 0}100%{background-position:-120% 0}}.drawer-backdrop{position:fixed;z-index:950;inset:0;display:flex;justify-content:flex-end;background:rgba(15,23,42,.38)}.drawer-panel{width:min(1120px,calc(100vw - 28px));height:100vh;overflow:auto;padding:18px;background:#fff;border-left:1px solid #dfe6ef;box-shadow:-24px 0 70px rgba(15,23,42,.2)}.drawer-heading{position:sticky;z-index:30;top:0;margin:-18px -18px 16px;padding:16px 18px;background:#fff;border-bottom:1px solid #edf1f5}.drawer-heading h2{margin:2px 0 0;color:#142139;font-size:20px}@media(max-width:1000px){.form-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:700px){.page-heading,.toolbar,.drawer-heading{align-items:stretch;flex-direction:column}.form-grid{grid-template-columns:1fr}.toolbar :deep(.search-select){min-width:0;width:100%}.filter-group{width:100%;align-items:stretch}.toolbar > .primary{width:100%;margin-left:0}.drawer-panel{width:100vw}}
</style>
