<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import Layout from '../Layout.vue';
import PurchaseApi from './PurchaseApi';

defineProps({ page: { type: String, default: 'purchases' }, title: { type: String, default: 'Purchases' } });

const today = new Date().toISOString().slice(0, 10);
const purchases = ref([]);
const references = ref({ suppliers: [], branches: [], warehouses: [] });
const products = ref([]);
const productSearch = ref('');
const loading = ref(false);
const saving = ref(false);
const errors = ref({});
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });
const filters = reactive({ status: '', payment_status: '', purchase_type: '', tax_type: '' });
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

const filteredWarehouses = computed(() => {
    if (!form.branch_id) return references.value.warehouses || [];
    return (references.value.warehouses || []).filter((w) => Number(w.branch_id || 0) === Number(form.branch_id));
});

const reset = () => {
    Object.assign(form, {
        id: null, branch_id: '', warehouse_id: '', supplier_id: '', supplier_invoice_number: '',
        purchase_date: today, supplier_invoice_date: '', due_date: '', purchase_type: 'credit',
        tax_type: 'intrastate', discount_type: '', discount_value: 0, paid_amount: 0, remarks: '', items: [],
    });
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

const searchProducts = async () => {
    if (productSearch.value.trim().length < 2) { products.value = []; return; }
    products.value = await PurchaseApi.searchProducts(productSearch.value.trim());
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

const formatMoney = (value) => Number(value || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

onMounted(async () => { await loadReferences(); await loadPurchases(); });
</script>

<template>
    <Layout :page="page" :title="title">
        <div class="purchase-page">
            <div class="page-heading">
                <div><span>PURCHASE MANAGEMENT</span><h1>Purchase Vouchers</h1><p>Draft purchases and post confirmed stock into the stock ledger.</p></div>
                <button type="button" @click="reset">New Purchase</button>
            </div>

            <section class="panel">
                <div class="form-grid">
                    <select v-model="form.branch_id"><option value="">Branch</option><option v-for="b in references.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select>
                    <select v-model="form.warehouse_id"><option value="">Warehouse</option><option v-for="w in filteredWarehouses" :key="w.id" :value="w.id">{{ w.name }}</option></select>
                    <select v-model="form.supplier_id"><option value="">Supplier</option><option v-for="s in references.suppliers" :key="s.id" :value="s.id">{{ s.supplier_name || s.name }}</option></select>
                    <input v-model="form.supplier_invoice_number" placeholder="Supplier Invoice No" />
                    <input v-model="form.purchase_date" type="date" />
                    <input v-model="form.supplier_invoice_date" type="date" />
                    <input v-model="form.due_date" type="date" />
                    <select v-model="form.purchase_type"><option value="cash">Cash</option><option value="credit">Credit</option></select>
                    <select v-model="form.tax_type"><option value="intrastate">Intrastate</option><option value="interstate">Interstate</option><option value="exempt">Exempt</option></select>
                    <select v-model="form.discount_type"><option value="">No Discount</option><option value="percentage">%</option><option value="amount">Amount</option></select>
                    <input v-model="form.discount_value" type="number" placeholder="Voucher Discount" />
                    <input v-model="form.paid_amount" type="number" placeholder="Paid Amount" />
                    <input v-model="form.remarks" placeholder="Remarks" />
                </div>

                <div class="product-search">
                    <input v-model="productSearch" placeholder="Search product by name, SKU or barcode" @input="searchProducts" />
                    <div v-if="products.length" class="search-results">
                        <button v-for="product in products" :key="product.id" type="button" @click="addProduct(product)">
                            <strong>{{ product.name }}</strong><span>{{ product.sku }} | {{ product.barcode || 'No barcode' }}</span>
                        </button>
                    </div>
                </div>

                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>Product</th><th>Variant</th><th>Batch</th><th>Expiry</th><th>Qty</th><th>Free</th><th>Rate</th><th>Disc</th><th>GST</th><th>Sell</th><th>MRP</th><th>Location</th><th></th></tr></thead>
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
                <div class="actions"><button :disabled="saving" @click="savePurchase('draft')">Save Draft</button><button class="primary" :disabled="saving" @click="savePurchase('approved')">{{ saving ? 'Saving...' : 'Confirm & Post' }}</button></div>
            </section>

            <section class="panel">
                <div class="toolbar">
                    <select v-model="filters.status" @change="loadPurchases(1)"><option value="">All Status</option><option value="draft">Draft</option><option value="approved">Approved</option><option value="cancelled">Cancelled</option><option value="reversed">Reversed</option></select>
                    <select v-model="filters.payment_status" @change="loadPurchases(1)"><option value="">All Payment</option><option value="unpaid">Unpaid</option><option value="partial">Partial</option><option value="paid">Paid</option></select>
                </div>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>Voucher</th><th>Date</th><th>Supplier</th><th>Branch</th><th>Warehouse</th><th>Invoice</th><th>Total</th><th>Paid</th><th>Balance</th><th>Payment</th><th>Status</th><th>Created By</th><th>Actions</th></tr></thead>
                        <tbody>
                            <tr v-for="purchase in purchases" :key="purchase.id">
                                <td>{{ purchase.voucher_number }}</td><td>{{ purchase.purchase_date }}</td><td>{{ purchase.supplier }}</td><td>{{ purchase.branch || '-' }}</td><td>{{ purchase.warehouse || '-' }}</td><td>{{ purchase.supplier_invoice_number || '-' }}</td>
                                <td>Rs. {{ formatMoney(purchase.grand_total) }}</td><td>Rs. {{ formatMoney(purchase.paid_amount) }}</td><td>Rs. {{ formatMoney(purchase.balance_amount) }}</td><td>{{ purchase.payment_status }}</td><td><span class="badge" :class="purchase.status">{{ purchase.status }}</span></td><td>{{ purchase.created_by || '-' }}</td>
                                <td><div class="row-actions"><button v-if="purchase.status === 'draft'" @click="editPurchase(purchase)">Edit</button><button v-if="purchase.status === 'draft'" @click="simpleAction(PurchaseApi.approvePurchase, purchase, 'Post purchase?')">Post</button><button @click="simpleAction(PurchaseApi.duplicatePurchase, purchase)">Copy</button><button v-if="purchase.status === 'draft'" class="danger" @click="simpleAction(PurchaseApi.cancelPurchase, purchase, 'Cancel draft?')">Cancel</button><button v-if="['approved','confirmed'].includes(purchase.status)" class="danger" @click="reversePurchase(purchase)">Reverse</button></div></td>
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
.purchase-page{padding:4px 0 28px}.page-heading,.toolbar,.actions,.pagination,.row-actions{display:flex;align-items:center;justify-content:space-between;gap:10px}.page-heading{margin-bottom:18px}.page-heading span{color:#2457d6;font-size:10px;font-weight:800;letter-spacing:1.2px}.page-heading h1{margin:0;color:#142139;font-weight:800}.page-heading p{margin:6px 0 0;color:#758197;font-size:13px}.panel{margin-bottom:18px;padding:18px;background:#fff;border:1px solid #dfe6ef;border-radius:14px}.form-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}input,select,button{min-height:38px;padding:8px 10px;color:#344159;background:#fff;border:1px solid #d8e0eb;border-radius:8px;font-size:12px}button{font-weight:750;cursor:pointer}.primary{color:#fff;background:#2457d6;border-color:#2457d6}.danger{color:#d23f49;background:#fff3f4;border-color:#ffd6da}.product-search{position:relative;margin:14px 0}.product-search input{width:100%}.search-results{position:absolute;z-index:20;top:44px;left:0;right:0;display:grid;max-height:220px;overflow:auto;background:#fff;border:1px solid #dce4ef;border-radius:9px;box-shadow:0 12px 30px rgba(15,34,66,.12)}.search-results button{display:grid;justify-items:start;border:0;border-bottom:1px solid #eef2f6;border-radius:0}.table-wrapper{overflow-x:auto}table{width:100%;border-collapse:collapse}th{padding:12px 10px;color:#69758a;background:#f8fafc;border-bottom:1px solid #e7ecf2;text-align:left;white-space:nowrap;font-size:10px;font-weight:800;text-transform:uppercase}td{padding:12px 10px;color:#27344c;border-bottom:1px solid #edf1f5;white-space:nowrap;font-size:12px}td input,td select{min-width:92px}td span,.search-results span{display:block;color:#7a869a;font-size:10px}.actions,.pagination{justify-content:flex-end;margin-top:12px}.toolbar{justify-content:flex-start;margin-bottom:12px}.badge{padding:5px 8px;border-radius:7px;background:#edf2ff;color:#2457d6;font-size:10px;font-weight:800;text-transform:capitalize}.badge.approved,.badge.confirmed{color:#168757;background:#eaf8f1}.badge.cancelled,.badge.reversed{color:#d23f49;background:#fff3f4}.empty{padding:28px!important;color:#8490a2;text-align:center}.error-box{display:grid;gap:4px;margin-top:12px;padding:10px;color:#96333a;background:#fff3f4;border:1px solid #ffd4d8;border-radius:8px;font-size:11px}@media(max-width:1000px){.form-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:700px){.page-heading,.toolbar{align-items:stretch;flex-direction:column}.form-grid{grid-template-columns:1fr}}
</style>
