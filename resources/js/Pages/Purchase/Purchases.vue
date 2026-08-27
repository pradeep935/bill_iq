<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import Layout from '../Layout.vue';
import PurchaseApi from './PurchaseApi';
import RowActionMenu from '../../Components/Common/RowActionMenu.vue';
import SearchSelect from '../../Components/Common/SearchSelect.vue';

defineProps({ page: { type: String, default: 'purchases' }, title: { type: String, default: 'Purchases' } });

const today = new Date().toISOString().slice(0, 10);
const purchases = ref([]);
const references = ref({ suppliers: [], branches: [], warehouses: [] });
const products = ref([]);
const productSearch = ref('');
const loading = ref(false);
const saving = ref(false);
const errors = ref({});
const supplierErrors = ref({});
const openActionMenuId = ref(null);
const showPurchaseDrawer = ref(false);
const showSupplierModal = ref(false);
const savingSupplier = ref(false);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });
const filters = reactive({ status: '', payment_status: '', purchase_type: '', tax_type: '' });
const purchaseTypes = [{ value: 'cash', label: 'Cash' }, { value: 'credit', label: 'Credit' }];
const taxTypes = [{ value: 'intrastate', label: 'Intrastate' }, { value: 'interstate', label: 'Interstate' }, { value: 'exempt', label: 'Exempt' }];
const discountTypes = [{ value: '', label: 'No Discount' }, { value: 'percentage', label: '%' }, { value: 'amount', label: 'Amount' }];
const statusFilters = [{ value: '', label: 'All Status' }, { value: 'draft', label: 'Draft' }, { value: 'approved', label: 'Approved' }, { value: 'cancelled', label: 'Cancelled' }, { value: 'reversed', label: 'Reversed' }];
const paymentFilters = [{ value: '', label: 'All Payment' }, { value: 'unpaid', label: 'Unpaid' }, { value: 'partial', label: 'Partial' }, { value: 'paid', label: 'Paid' }];
const form = reactive({
    id: null,
    branch_id: '',
    warehouse_id: '',
    supplier_id: '',
    supplier_invoice_number: '',
    purchase_date: today,
    supplier_invoice_date: '',
    due_date: '',
    purchase_type: 'credit',
    tax_type: 'intrastate',
    discount_type: '',
    discount_value: 0,
    paid_amount: 0,
    remarks: '',
    items: [],
});
const supplierForm = reactive({
    supplier_code: '',
    supplier_name: '',
    contact_person: '',
    mobile: '',
    phone: '',
    email: '',
    gstin: '',
    pan: '',
    billing_address: '',
    shipping_address: '',
    state_id: '',
    city: '',
    pincode: '',
    opening_balance: 0,
    opening_balance_type: 'credit',
    credit_limit: '',
    credit_days: '',
    status: 'active',
});
const draftStorageKey = 'bill_iq_purchase_draft';

const filteredWarehouses = computed(() => {
    if (!form.branch_id) return references.value.warehouses || [];
    return (references.value.warehouses || []).filter((w) => Number(w.branch_id || 0) === Number(form.branch_id));
});

const filteredSupplierCities = computed(() => {
    if (!supplierForm.state_id) return references.value.cities || [];
    return (references.value.cities || []).filter((city) => Number(city.state_id) === Number(supplierForm.state_id));
});

const reset = () => {
    Object.assign(form, {
        id: null, branch_id: '', warehouse_id: '', supplier_id: '', supplier_invoice_number: '',
        purchase_date: today, supplier_invoice_date: '', due_date: '', purchase_type: 'credit',
        tax_type: 'intrastate', discount_type: '', discount_value: 0, paid_amount: 0, remarks: '', items: [],
    });
    errors.value = {};
};

const openPurchaseCreate = () => {
    reset();
    showPurchaseDrawer.value = true;
};

const closePurchaseDrawer = () => {
    showPurchaseDrawer.value = false;
    products.value = [];
    productSearch.value = '';
    errors.value = {};
};

const loadReferences = async () => { references.value = await PurchaseApi.references(); };
const loadPurchases = async (page = 1) => {
    loading.value = true;
    try {
        const response = await PurchaseApi.purchases({ ...filters, page });
        purchases.value = response.purchases || [];
        pagination.value = response.pagination || pagination.value;
    } finally { loading.value = false; }
};

const clearFilters = async () => {
    Object.assign(filters, { status: '', payment_status: '', purchase_type: '', tax_type: '' });
    await loadPurchases(1);
};

const searchProducts = async () => {
    if (productSearch.value.trim().length < 2) { products.value = []; return; }
    products.value = await PurchaseApi.searchProducts(productSearch.value.trim());
};

const persistDraftForProductCreate = () => {
    sessionStorage.setItem(draftStorageKey, JSON.stringify({
        form: JSON.parse(JSON.stringify(form)),
        productSearch: productSearch.value,
    }));
};

const restoreDraftAfterProductCreate = () => {
    const saved = sessionStorage.getItem(draftStorageKey);
    if (!saved) return;

    try {
        const draft = JSON.parse(saved);
        Object.assign(form, {
            ...form,
            ...(draft.form || {}),
            items: draft.form?.items || [],
        });
        productSearch.value = draft.productSearch || '';
    } catch (error) {
        console.error(error);
    } finally {
        sessionStorage.removeItem(draftStorageKey);
    }
};

const openProductMasterCreate = () => {
    persistDraftForProductCreate();

    const url = new URL('/app/inventory/products', window.location.origin);
    url.searchParams.set('return_to', '/app/purchase/bills');

    if (productSearch.value.trim()) {
        url.searchParams.set('prefill_name', productSearch.value.trim());
    }

    window.location.href = `${url.pathname}${url.search}`;
};

const resetSupplierForm = () => {
    Object.assign(supplierForm, {
        supplier_code: '',
        supplier_name: '',
        contact_person: '',
        mobile: '',
        phone: '',
        email: '',
        gstin: '',
        pan: '',
        billing_address: '',
        shipping_address: '',
        state_id: '',
        city: '',
        pincode: '',
        opening_balance: 0,
        opening_balance_type: 'credit',
        credit_limit: '',
        credit_days: '',
        status: 'active',
    });
    supplierErrors.value = {};
};

const openSupplierCreate = () => {
    resetSupplierForm();
    showSupplierModal.value = true;
};

const closeSupplierCreate = () => {
    showSupplierModal.value = false;
    supplierErrors.value = {};
};

const saveSupplierFromPurchase = async () => {
    if (savingSupplier.value) return;
    savingSupplier.value = true;
    supplierErrors.value = {};
    try {
        const response = await PurchaseApi.saveSupplier({ ...supplierForm });
        await loadReferences();
        form.supplier_id = response.supplier?.id || '';
        closeSupplierCreate();
        alert(response.message || 'Supplier saved.');
    } catch (error) {
        if (error.response?.status === 422) {
            supplierErrors.value = error.response.data.errors || {};
            alert(Object.values(supplierErrors.value)?.[0]?.[0] || 'Please check supplier fields.');
            return;
        }
        const data = error.response?.data || {};
        alert(data.message || data.error || error.message || 'Supplier save nahi ho saka.');
    } finally {
        savingSupplier.value = false;
    }
};

const addReturnedProduct = async () => {
    const params = new URLSearchParams(window.location.search);
    const productId = params.get('added_product_id');
    if (!productId) return;

    const found = await PurchaseApi.searchProducts(`id:${productId}`);
    if (found?.[0]) {
        addProduct(found[0]);
    }

    window.history.replaceState({}, document.title, window.location.pathname);
};

const addProduct = (product) => {
    form.items.push({
        product_id: product.id,
        product_name: product.name,
        sku: product.sku,
        variants: product.variants || [],
        product_variant_id: '',
        batch_id: '',
        quantity: 1,
        free_quantity: 0,
        unit_id: product.unit_id || '',
        purchase_rate: product.purchase_rate || 0,
        selling_price: product.selling_price || 0,
        mrp: product.mrp || '',
        discount_type: '',
        discount_value: 0,
        gst_rate: product.gst_rate || 0,
        cess_rate: product.cess_rate || 0,
        batch_number: '',
        manufacturing_date: '',
        expiry_date: '',
        warehouse_location: '',
        remarks: '',
    });
    productSearch.value = '';
    products.value = [];
};

const removeItem = (index) => form.items.splice(index, 1);

const payload = (status) => ({ ...form, status, branch_id: form.branch_id || null, warehouse_id: form.warehouse_id || null });

const savePurchase = async (status = 'draft') => {
    if (saving.value) return;
    saving.value = true;
    errors.value = {};
    try {
        const response = await PurchaseApi.savePurchase(payload(status), form.id);
        alert(response.message || 'Purchase saved.');
        reset();
        closePurchaseDrawer();
        await loadPurchases();
    } catch (error) {
        if (error.response?.status === 422) {
            errors.value = error.response.data.errors || {};
            alert(Object.values(errors.value)?.[0]?.[0] || 'Please check purchase fields.');
            return;
        }
        alert(error.response?.data?.message || 'Purchase save nahi ho saka.');
    } finally { saving.value = false; }
};

const editPurchase = (purchase) => {
    Object.assign(form, {
        id: purchase.id,
        branch_id: purchase.branch_id || '',
        warehouse_id: purchase.warehouse_id || '',
        supplier_id: purchase.supplier_id,
        supplier_invoice_number: purchase.supplier_invoice_number || '',
        purchase_date: purchase.purchase_date || today,
        supplier_invoice_date: purchase.supplier_invoice_date || '',
        due_date: purchase.due_date || '',
        purchase_type: purchase.purchase_type,
        tax_type: purchase.tax_type,
        discount_type: purchase.discount_type || '',
        discount_value: purchase.discount_value || 0,
        paid_amount: purchase.paid_amount || 0,
        remarks: purchase.remarks || '',
        items: (purchase.items || []).map((item) => ({ ...item, product_name: item.product, variants: [] })),
    });
    showPurchaseDrawer.value = true;
};

const simpleAction = async (fn, purchase, promptText) => {
    if (promptText && !window.confirm(promptText)) return;
    const response = await fn(purchase.id);
    alert(response.message || 'Done.');
    await loadPurchases(pagination.value.current_page || 1);
};

const reversePurchase = async (purchase) => {
    const remarks = window.prompt('Reversal remarks');
    if (!remarks) return;
    const response = await PurchaseApi.reversePurchase(purchase.id, remarks);
    alert(response.message || 'Purchase reversed.');
    await loadPurchases(pagination.value.current_page || 1);
};
const toggleActionMenu = (purchase) => { openActionMenuId.value = openActionMenuId.value === purchase.id ? null : purchase.id; };
const closeActionMenu = () => { openActionMenuId.value = null; };

const formatMoney = (value) => Number(value || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

watch(() => supplierForm.state_id, () => {
    if (supplierForm.city && !filteredSupplierCities.value.some((city) => city.name === supplierForm.city)) {
        supplierForm.city = '';
    }
});

onMounted(async () => {
    restoreDraftAfterProductCreate();
    await loadReferences();
    await addReturnedProduct();
    if (form.items.length || form.id) {
        showPurchaseDrawer.value = true;
    }
    await loadPurchases();
});
</script>

<template>
    <Layout :page="page" :title="title">
        <template #topbar-title>
            <div class="bill-page-title"><span>PURCHASE MANAGEMENT</span><h1>Purchase Vouchers</h1><p>Draft purchases and post confirmed stock into the stock ledger.</p></div>
        </template>
        <div class="purchase-page">
            <div class="page-toolbar"><button type="button" class="primary" @click="openPurchaseCreate">New Purchase</button></div>

            <div v-if="showPurchaseDrawer" class="drawer-backdrop" @click.self="closePurchaseDrawer">
                <aside class="drawer-panel">
                    <div class="drawer-heading">
                        <div><span>PURCHASE MANAGEMENT</span><h2>{{ form.id ? 'Edit Purchase' : 'New Purchase' }}</h2></div>
                        <button type="button" @click="closePurchaseDrawer">Close</button>
                    </div>

                    <div class="form-grid">
                        <SearchSelect v-model="form.branch_id" label="Branch" :options="references.branches" option-value-key="id" option-label-key="name" select-placeholder="Select Branch" />
                        <SearchSelect v-model="form.warehouse_id" label="Warehouse" :options="filteredWarehouses" option-value-key="id" option-label-key="name" select-placeholder="Select Warehouse" />
                        <div class="supplier-picker">
                            <SearchSelect v-model="form.supplier_id" label="Supplier" :options="references.suppliers" option-value-key="id" option-label-key="supplier_name" select-placeholder="Select Supplier" required />
                            <button type="button" class="secondary-action add-supplier-btn" @click="openSupplierCreate">Add Supplier</button>
                        </div>
                        <label>Supplier Invoice No<input v-model="form.supplier_invoice_number" placeholder="Supplier Invoice No" /></label>
                        <label><span>Purchase Date <span class="required-mark">*</span></span><input v-model="form.purchase_date" type="date" /></label>
                        <label>Supplier Invoice Date<input v-model="form.supplier_invoice_date" type="date" /></label>
                        <label>Due Date<input v-model="form.due_date" type="date" /></label>
                        <SearchSelect v-model="form.purchase_type" label="Purchase Type" :options="purchaseTypes" option-value-key="value" option-label-key="label" select-placeholder="Select Type" required />
                        <SearchSelect v-model="form.tax_type" label="Tax Type" :options="taxTypes" option-value-key="value" option-label-key="label" select-placeholder="Select Tax Type" required />
                        <SearchSelect v-model="form.discount_type" label="Discount Type" :options="discountTypes" option-value-key="value" option-label-key="label" select-placeholder="No Discount" />
                        <label>Voucher Discount<input v-model="form.discount_value" type="number" min="0" step="0.01" placeholder="Voucher Discount" /></label>
                        <label>Paid Amount<input v-model="form.paid_amount" type="number" min="0" step="0.01" placeholder="Paid Amount" /></label>
                        <label>Remarks<input v-model="form.remarks" placeholder="Remarks" /></label>
                    </div>

                    <div class="product-search">
                        <div class="product-search-row">
                            <input v-model="productSearch" placeholder="Search product by name, SKU or barcode" @input="searchProducts" />
                            <button type="button" class="secondary-action" @click="openProductMasterCreate">Add Product</button>
                        </div>
                        <div v-if="products.length" class="search-results">
                            <button v-for="product in products" :key="product.id" type="button" @click="addProduct(product)">
                                <strong>{{ product.name }}</strong><span>{{ product.sku }} | {{ product.barcode || 'No barcode' }}</span>
                            </button>
                        </div>
                    </div>

                    <div class="table-wrapper">
                        <table>
                            <thead><tr><th>Product</th><th>Variant</th><th>Batch</th><th>Expiry</th><th>Qty <span class="required-mark">*</span></th><th>Free</th><th>Rate <span class="required-mark">*</span></th><th>Disc</th><th>GST</th><th>Sell</th><th>MRP</th><th>Location</th><th></th></tr></thead>
                            <tbody>
                                <tr v-for="(item, index) in form.items" :key="`${item.product_id}-${index}`">
                                    <td><strong>{{ item.product_name }}</strong><span>{{ item.sku }}</span></td>
                                    <td><select v-model="item.product_variant_id"><option value="">Default</option><option v-for="v in item.variants" :key="v.id" :value="v.id">{{ v.sku }}</option></select></td>
                                    <td><input v-model="item.batch_number" /></td>
                                    <td><input v-model="item.expiry_date" type="date" /></td>
                                    <td><input v-model="item.quantity" type="number" step="0.001" /></td>
                                    <td><input v-model="item.free_quantity" type="number" step="0.001" /></td>
                                    <td><input v-model="item.purchase_rate" type="number" step="0.01" /></td>
                                    <td><input v-model="item.discount_value" type="number" step="0.01" /></td>
                                    <td><input v-model="item.gst_rate" type="number" step="0.01" /></td>
                                    <td><input v-model="item.selling_price" type="number" step="0.01" /></td>
                                    <td><input v-model="item.mrp" type="number" step="0.01" /></td>
                                    <td><input v-model="item.warehouse_location" /></td>
                                    <td><button type="button" class="danger" @click="removeItem(index)">Remove</button></td>
                                </tr>
                                <tr v-if="!form.items.length"><td colspan="13" class="empty">Search and add purchase items.</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-if="Object.keys(errors).length" class="error-box"><span v-for="(messages, field) in errors" :key="field">{{ messages[0] }}</span></div>
                    <div class="actions"><button type="button" @click="closePurchaseDrawer">Cancel</button><button :disabled="saving" @click="savePurchase('draft')">Save Draft</button><button class="primary" :disabled="saving" @click="savePurchase('approved')">{{ saving ? 'Saving...' : 'Confirm & Post' }}</button></div>
                </aside>
            </div>

            <div v-if="showSupplierModal" class="modal-backdrop" @click.self="closeSupplierCreate">
                <section class="modal-panel">
                    <div class="modal-heading">
                        <div><span>PURCHASE MASTER</span><h2>Add Supplier</h2></div>
                        <button type="button" @click="closeSupplierCreate">Close</button>
                    </div>
                    <div class="form-grid supplier-form-grid">
                        <label>Supplier Code<input v-model="supplierForm.supplier_code" placeholder="Auto if blank" /></label>
                        <label><span>Supplier Name <span class="required-mark">*</span></span><input v-model="supplierForm.supplier_name" placeholder="Supplier Name" /></label>
                        <label>Contact Person<input v-model="supplierForm.contact_person" placeholder="Contact Person" /></label>
                        <label><span>Mobile <span class="required-mark">*</span></span><input v-model="supplierForm.mobile" placeholder="9876543210" /></label>
                        <label>Phone<input v-model="supplierForm.phone" placeholder="Phone" /></label>
                        <label>Email<input v-model="supplierForm.email" placeholder="Email" /></label>
                        <label>GSTIN<input v-model="supplierForm.gstin" maxlength="15" placeholder="15-character GSTIN" /></label>
                        <label>PAN<input v-model="supplierForm.pan" maxlength="10" placeholder="PAN" /></label>
                        <SearchSelect v-model="supplierForm.state_id" label="State" :options="references.states" option-value-key="id" option-label-key="name" select-placeholder="Select State" />
                        <SearchSelect v-model="supplierForm.city" label="City" :options="filteredSupplierCities" option-value-key="name" option-label-key="name" select-placeholder="Search City" required allow-custom />
                        <label>Pincode<input v-model="supplierForm.pincode" maxlength="12" placeholder="Pincode" /></label>
                        <label>Credit Days<input v-model="supplierForm.credit_days" type="number" min="0" placeholder="Credit Days" /></label>
                        <label class="wide"><span>Billing Address <span class="required-mark">*</span></span><textarea v-model="supplierForm.billing_address" placeholder="Billing Address"></textarea></label>
                        <label class="wide">Shipping Address<textarea v-model="supplierForm.shipping_address" placeholder="Shipping Address"></textarea></label>
                    </div>
                    <div v-if="Object.keys(supplierErrors).length" class="error-box"><span v-for="(messages, field) in supplierErrors" :key="field">{{ messages[0] }}</span></div>
                    <div class="actions"><button type="button" @click="closeSupplierCreate">Cancel</button><button type="button" class="primary" :disabled="savingSupplier" @click="saveSupplierFromPurchase">{{ savingSupplier ? 'Saving...' : 'Save Supplier' }}</button></div>
                </section>
            </div>

            <section class="panel">
                <div class="toolbar">
                    <SearchSelect v-model="filters.status" label="Status" :options="statusFilters" option-value-key="value" option-label-key="label" select-placeholder="All Status" @selected="loadPurchases(1)" />
                    <SearchSelect v-model="filters.payment_status" label="Payment" :options="paymentFilters" option-value-key="value" option-label-key="label" select-placeholder="All Payment" @selected="loadPurchases(1)" />
                    <button type="button" class="primary" @click="loadPurchases(1)">Search</button>
                    <button type="button" class="ghost-action" @click="clearFilters">Clear</button>
                </div>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>Voucher</th><th>Date</th><th>Supplier</th><th>Branch</th><th>Warehouse</th><th>Invoice</th><th>Total</th><th>Paid</th><th>Balance</th><th>Payment</th><th>Status</th><th>Created By</th><th>Actions</th></tr></thead>
                        <tbody :class="{ 'is-loading': loading }">
                            <template v-if="loading">
                                <tr v-for="index in 4" :key="`purchase-loading-${index}`" class="loading-row">
                                    <td v-for="cell in 13" :key="cell"><span class="skeleton-line" :class="`w-${(cell % 4) + 1}`"></span></td>
                                </tr>
                            </template>
                            <tr v-for="purchase in purchases" :key="purchase.id">
                                <td>{{ purchase.voucher_number }}</td><td>{{ purchase.purchase_date }}</td><td>{{ purchase.supplier }}</td><td>{{ purchase.branch || '-' }}</td><td>{{ purchase.warehouse || '-' }}</td><td>{{ purchase.supplier_invoice_number || '-' }}</td>
                                <td>Rs. {{ formatMoney(purchase.grand_total) }}</td><td>Rs. {{ formatMoney(purchase.paid_amount) }}</td><td>Rs. {{ formatMoney(purchase.balance_amount) }}</td><td>{{ purchase.payment_status }}</td><td><span class="badge" :class="purchase.status">{{ purchase.status }}</span></td><td>{{ purchase.created_by || '-' }}</td>
                                <td><div class="row-actions"><RowActionMenu :open="openActionMenuId === purchase.id" :show-view="false" more-label="Actions" more-title="Purchase actions" placement="top" @toggle="toggleActionMenu(purchase)"><button v-if="purchase.status === 'draft'" type="button" @click="editPurchase(purchase); closeActionMenu()">Edit</button><button v-if="purchase.status === 'draft'" type="button" @click="simpleAction(PurchaseApi.approvePurchase, purchase, 'Post purchase?'); closeActionMenu()">Post</button><button type="button" @click="simpleAction(PurchaseApi.duplicatePurchase, purchase); closeActionMenu()">Copy</button><button v-if="purchase.status === 'draft'" type="button" class="danger" @click="simpleAction(PurchaseApi.cancelPurchase, purchase, 'Cancel draft?'); closeActionMenu()">Cancel</button><button v-if="['approved','confirmed'].includes(purchase.status)" type="button" class="danger" @click="reversePurchase(purchase); closeActionMenu()">Reverse</button></RowActionMenu></div></td>
                            </tr>
                            <tr v-if="!purchases.length && !loading"><td colspan="13" class="empty">No purchases found.</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="pagination"><button :disabled="pagination.current_page <= 1" @click="loadPurchases(pagination.current_page - 1)">Previous</button><span>{{ pagination.from || 0 }}-{{ pagination.to || 0 }} of {{ pagination.total || 0 }}</span><button :disabled="pagination.current_page >= pagination.last_page" @click="loadPurchases(pagination.current_page + 1)">Next</button></div>
            </section>
        </div>
    </Layout>
</template>

<style scoped>
.purchase-page{padding:4px 0 28px}.page-heading,.toolbar,.actions,.pagination,.row-actions,.modal-heading,.drawer-heading{display:flex;align-items:center;justify-content:space-between;gap:10px}.page-toolbar{display:flex;justify-content:flex-end;margin-bottom:14px}.page-heading{margin-bottom:18px}.page-heading span,.modal-heading span,.drawer-heading span{color:#2457d6;font-size:10px;font-weight:800;letter-spacing:1.2px}.page-heading h1{margin:0;color:#142139;font-weight:800}.page-heading p{margin:6px 0 0;color:#758197;font-size:13px}.panel{margin-bottom:18px;padding:18px;background:#fff;border:1px solid #dfe6ef;border-radius:8px}.form-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.form-grid label{display:grid;gap:5px;color:#667085;font-size:11px;font-weight:800}.form-grid :deep(.search-select){display:block}.toolbar :deep(.search-select){width:220px;min-width:0}.toolbar :deep(.search-select-hint){display:none}.required-mark{color:#dc2626;font-weight:900;margin-left:3px}input,select,textarea,button{min-height:38px;padding:8px 10px;color:#344159;background:#fff;border:1px solid #d8e0eb;border-radius:8px;font-size:12px}textarea{min-height:72px;resize:vertical}button{font-weight:750;cursor:pointer}.primary{color:#fff;background:#2457d6;border-color:#2457d6}.secondary-action{color:#2457d6;background:#edf4ff;border-color:#cfe0ff}.danger{color:#d23f49;background:#fff3f4;border-color:#ffd6da}.supplier-picker{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:end;gap:8px}.add-supplier-btn{height:38px;align-self:end;white-space:nowrap}.product-search{position:relative;margin:14px 0}.product-search-row{display:flex;gap:10px}.product-search-row input{flex:1;min-width:0}.product-search-row button{white-space:nowrap}.search-results{position:absolute;z-index:20;top:44px;left:0;right:0;display:grid;max-height:220px;overflow:auto;background:#fff;border:1px solid #dce4ef;border-radius:9px;box-shadow:0 12px 30px rgba(15,34,66,.12)}.search-results button{display:grid;justify-items:start;border:0;border-bottom:1px solid #eef2f6;border-radius:0}.table-wrapper{overflow-x:auto}table{width:100%;border-collapse:collapse}th{padding:12px 10px;color:#69758a;background:#f8fafc;border-bottom:1px solid #e7ecf2;text-align:left;white-space:nowrap;font-size:10px;font-weight:800;text-transform:uppercase}td{padding:12px 10px;color:#27344c;border-bottom:1px solid #edf1f5;white-space:nowrap;font-size:12px}td input,td select{min-width:92px}td span,.search-results span{display:block;color:#7a869a;font-size:10px}.actions,.pagination{justify-content:flex-end;margin-top:12px}.toolbar{justify-content:flex-start;margin-bottom:12px;flex-wrap:wrap}.ghost-action{color:#475569;background:#f8fafc;border-color:#dfe6ef}.badge{padding:5px 8px;border-radius:7px;background:#edf2ff;color:#2457d6;font-size:10px;font-weight:800;text-transform:capitalize}.badge.approved,.badge.confirmed{color:#168757;background:#eaf8f1}.badge.cancelled,.badge.reversed{color:#d23f49;background:#fff3f4}.empty{padding:28px!important;color:#8490a2;text-align:center}.error-box{display:grid;gap:4px;margin-top:12px;padding:10px;color:#96333a;background:#fff3f4;border:1px solid #ffd4d8;border-radius:8px;font-size:11px}.is-loading tr:not(.loading-row){opacity:.38}.loading-row td{padding:14px 10px}.skeleton-line{display:block;width:100%;height:12px;border-radius:999px;background:linear-gradient(90deg,#eef3fb 25%,#f8fbff 38%,#eef3fb 63%);background-size:240% 100%;animation:shimmer 1.05s ease-in-out infinite}.skeleton-line.w-1{width:46%}.skeleton-line.w-2{width:64%}.skeleton-line.w-3{width:78%}.skeleton-line.w-4{width:92%}@keyframes shimmer{0%{background-position:120% 0}100%{background-position:-120% 0}}.drawer-backdrop{position:fixed;z-index:950;inset:0;display:flex;justify-content:flex-end;background:rgba(15,23,42,.38)}.drawer-panel{width:min(1120px,calc(100vw - 28px));height:100vh;overflow:auto;padding:18px;background:#fff;border-left:1px solid #dfe6ef;box-shadow:-24px 0 70px rgba(15,23,42,.2)}.drawer-heading{position:sticky;z-index:30;top:0;margin:-18px -18px 16px;padding:16px 18px;background:#fff;border-bottom:1px solid #edf1f5}.drawer-heading h2{margin:2px 0 0;color:#142139;font-size:20px}.modal-backdrop{position:fixed;z-index:1000;inset:0;display:grid;place-items:center;padding:20px;background:rgba(15,23,42,.42)}.modal-panel{width:min(920px,100%);max-height:calc(100vh - 40px);overflow:auto;padding:18px;background:#fff;border:1px solid #dfe6ef;border-radius:8px;box-shadow:0 24px 70px rgba(15,23,42,.22)}.modal-heading{margin-bottom:14px}.modal-heading h2{margin:2px 0 0;color:#142139;font-size:20px}.supplier-form-grid .wide{grid-column:span 2}@media(max-width:1000px){.form-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:700px){.page-heading,.toolbar,.product-search-row,.modal-heading,.drawer-heading{align-items:stretch;flex-direction:column}.form-grid{grid-template-columns:1fr}.toolbar :deep(.search-select){min-width:0;width:100%}.supplier-picker{grid-template-columns:1fr}.supplier-form-grid .wide{grid-column:auto}.drawer-panel{width:100vw}}
</style>
