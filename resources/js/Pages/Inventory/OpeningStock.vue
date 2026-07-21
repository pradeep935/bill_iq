<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import Layout from '../Layout.vue';
import InventoryApi from './InventoryApi';

defineProps({
    page: { type: String, default: 'opening-stock' },
    title: { type: String, default: 'Opening Stock' },
    role_id: { type: Number, default: null },
});

const today = new Date().toISOString().slice(0, 10);
const loading = ref(false);
const saving = ref(false);
const vouchers = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });
const references = ref({ branches: [], warehouses: [] });
const productSearch = ref('');
const productResults = ref([]);
const errors = ref({});

const form = reactive({
    id: null,
    branch_id: '',
    warehouse_id: '',
    opening_date: today,
    remarks: '',
    status: 'draft',
    items: [],
});

const filteredWarehouses = computed(() => {
    if (!form.branch_id) {
        return references.value.warehouses || [];
    }

    return (references.value.warehouses || []).filter((warehouse) =>
        Number(warehouse.branch_id || 0) === Number(form.branch_id)
    );
});

const resetForm = () => {
    form.id = null;
    form.branch_id = '';
    form.warehouse_id = '';
    form.opening_date = today;
    form.remarks = '';
    form.status = 'draft';
    form.items = [];
    errors.value = {};
    productSearch.value = '';
    productResults.value = [];
};

const loadReferences = async () => {
    references.value = await InventoryApi.openingStockReferences();
};

const loadVouchers = async (page = 1) => {
    loading.value = true;

    try {
        const response = await InventoryApi.openingStockList({ page });
        vouchers.value = response.vouchers || [];
        pagination.value = response.pagination || pagination.value;
    } finally {
        loading.value = false;
    }
};

const searchProducts = async () => {
    const q = productSearch.value.trim();

    if (q.length < 2) {
        productResults.value = [];
        return;
    }

    productResults.value = await InventoryApi.searchOpeningStockProducts(q);
};

const addProduct = (product) => {
    form.items.push({
        product_id: product.id,
        product_name: product.name,
        sku: product.sku,
        variants: product.variants || [],
        product_variant_id: '',
        batch_id: '',
        batch_no: '',
        manufacturing_date: '',
        expiry_date: '',
        quantity: '1',
        purchase_cost: '0',
        selling_price: product.selling_price || 0,
        mrp: product.mrp || '',
        warehouse_location: '',
        remarks: '',
    });

    productSearch.value = '';
    productResults.value = [];
};

const removeItem = (index) => {
    form.items.splice(index, 1);
};

const editVoucher = (voucher) => {
    form.id = voucher.id;
    form.branch_id = voucher.branch_id || '';
    form.warehouse_id = voucher.warehouse_id || '';
    form.opening_date = voucher.opening_date || today;
    form.remarks = voucher.remarks || '';
    form.status = voucher.status === 'draft' ? 'draft' : voucher.status;
    form.items = (voucher.items || []).map((item) => ({
        ...item,
        product_name: item.product,
        variants: [],
    }));
    errors.value = {};
};

const payload = (status) => ({
    branch_id: form.branch_id || null,
    warehouse_id: form.warehouse_id || null,
    opening_date: form.opening_date,
    remarks: form.remarks,
    status,
    items: form.items.map((item) => ({
        product_id: item.product_id,
        product_variant_id: item.product_variant_id || null,
        batch_id: item.batch_id || null,
        batch_no: item.batch_no || null,
        manufacturing_date: item.manufacturing_date || null,
        expiry_date: item.expiry_date || null,
        quantity: item.quantity,
        purchase_cost: item.purchase_cost || 0,
        selling_price: item.selling_price || 0,
        mrp: item.mrp || null,
        warehouse_location: item.warehouse_location || null,
        remarks: item.remarks || null,
    })),
});

const saveVoucher = async (status = 'draft') => {
    if (saving.value) {
        return;
    }

    saving.value = true;
    errors.value = {};

    try {
        const response = await InventoryApi.saveOpeningStock(payload(status), form.id);
        alert(response.message || 'Opening stock saved.');
        resetForm();
        await loadVouchers();
    } catch (error) {
        if (error.response?.status === 422) {
            errors.value = error.response.data.errors || {};
            alert(Object.values(errors.value)?.[0]?.[0] || 'Please check opening stock fields.');
            return;
        }

        alert(error.response?.data?.message || 'Opening stock save nahi ho saka.');
    } finally {
        saving.value = false;
    }
};

const approveVoucher = async (voucher) => {
    if (!window.confirm(`${voucher.voucher_number} post karna hai?`)) {
        return;
    }

    const response = await InventoryApi.approveOpeningStock(voucher.id);
    alert(response.message || 'Opening stock posted.');
    await loadVouchers(pagination.value.current_page || 1);
};

const reverseVoucher = async (voucher) => {
    const remarks = window.prompt('Reversal remarks');

    if (!remarks) {
        return;
    }

    const response = await InventoryApi.reverseOpeningStock(voucher.id, remarks);
    alert(response.message || 'Opening stock reversed.');
    await loadVouchers(pagination.value.current_page || 1);
};

const formatMoney = (value) => Number(value || 0).toLocaleString('en-IN', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

onMounted(async () => {
    await loadReferences();
    await loadVouchers();
});
</script>

<template>
    <Layout :page="page" :title="title">
        <div class="inventory-page">
            <div class="page-heading">
                <div>
                    <span>INVENTORY FOUNDATION</span>
                    <h1>Opening Stock</h1>
                    <p>Create draft vouchers and post opening quantities to the stock ledger.</p>
                </div>
                <button type="button" @click="resetForm">New Voucher</button>
            </div>

            <section class="panel">
                <div class="form-grid">
                    <label>
                        Branch
                        <select v-model="form.branch_id">
                            <option value="">Select Branch</option>
                            <option v-for="branch in references.branches" :key="branch.id" :value="branch.id">
                                {{ branch.name }}
                            </option>
                        </select>
                    </label>

                    <label>
                        Warehouse
                        <select v-model="form.warehouse_id">
                            <option value="">Select Warehouse</option>
                            <option v-for="warehouse in filteredWarehouses" :key="warehouse.id" :value="warehouse.id">
                                {{ warehouse.name }}
                            </option>
                        </select>
                    </label>

                    <label>
                        Opening Date
                        <input v-model="form.opening_date" type="date" />
                    </label>

                    <label>
                        Remarks
                        <input v-model="form.remarks" type="text" placeholder="Voucher remarks" />
                    </label>
                </div>

                <div class="product-search">
                    <input
                        v-model="productSearch"
                        type="text"
                        placeholder="Search product by name, SKU or barcode"
                        @input="searchProducts"
                    />
                    <div v-if="productResults.length" class="search-results">
                        <button
                            v-for="product in productResults"
                            :key="product.id"
                            type="button"
                            @click="addProduct(product)"
                        >
                            <strong>{{ product.name }}</strong>
                            <span>{{ product.sku }} | {{ product.barcode || 'No barcode' }}</span>
                        </button>
                    </div>
                </div>

                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Variant</th>
                                <th>Batch</th>
                                <th>Mfg</th>
                                <th>Expiry</th>
                                <th>Qty</th>
                                <th>Cost</th>
                                <th>Sell</th>
                                <th>MRP</th>
                                <th>Location</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(item, index) in form.items" :key="`${item.product_id}-${index}`">
                                <td>
                                    <strong>{{ item.product_name }}</strong>
                                    <span>{{ item.sku }}</span>
                                </td>
                                <td>
                                    <select v-model="item.product_variant_id">
                                        <option value="">Default</option>
                                        <option v-for="variant in item.variants" :key="variant.id" :value="variant.id">
                                            {{ variant.sku }}
                                        </option>
                                    </select>
                                </td>
                                <td><input v-model="item.batch_no" type="text" /></td>
                                <td><input v-model="item.manufacturing_date" type="date" /></td>
                                <td><input v-model="item.expiry_date" type="date" /></td>
                                <td><input v-model="item.quantity" type="number" min="0.001" step="0.001" /></td>
                                <td><input v-model="item.purchase_cost" type="number" min="0" step="0.01" /></td>
                                <td><input v-model="item.selling_price" type="number" min="0" step="0.01" /></td>
                                <td><input v-model="item.mrp" type="number" min="0" step="0.01" /></td>
                                <td><input v-model="item.warehouse_location" type="text" /></td>
                                <td><button type="button" class="danger" @click="removeItem(index)">Remove</button></td>
                            </tr>
                            <tr v-if="!form.items.length">
                                <td colspan="11" class="empty">Search and add stock items.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="Object.keys(errors).length" class="error-box">
                    <span v-for="(messages, field) in errors" :key="field">{{ messages[0] }}</span>
                </div>

                <div class="actions">
                    <button type="button" :disabled="saving" @click="saveVoucher('draft')">Save Draft</button>
                    <button type="button" class="primary" :disabled="saving" @click="saveVoucher('approved')">
                        {{ saving ? 'Saving...' : 'Confirm & Post' }}
                    </button>
                </div>
            </section>

            <section class="panel">
                <div class="panel-head">
                    <h2>Opening Stock Vouchers</h2>
                    <span>{{ pagination.total || 0 }} vouchers</span>
                </div>

                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Voucher</th>
                                <th>Date</th>
                                <th>Branch</th>
                                <th>Warehouse</th>
                                <th>Items</th>
                                <th>Quantity</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="voucher in vouchers" :key="voucher.id">
                                <td>{{ voucher.voucher_number }}</td>
                                <td>{{ voucher.opening_date }}</td>
                                <td>{{ voucher.branch || '-' }}</td>
                                <td>{{ voucher.warehouse || '-' }}</td>
                                <td>{{ voucher.items_count }}</td>
                                <td>{{ voucher.total_quantity }}</td>
                                <td><span class="badge" :class="voucher.status">{{ voucher.status }}</span></td>
                                <td>
                                    <div class="row-actions">
                                        <button v-if="voucher.status === 'draft'" type="button" @click="editVoucher(voucher)">Edit</button>
                                        <button v-if="voucher.status === 'draft'" type="button" @click="approveVoucher(voucher)">Post</button>
                                        <button v-if="['approved', 'confirmed'].includes(voucher.status)" type="button" class="danger" @click="reverseVoucher(voucher)">Reverse</button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!vouchers.length && !loading">
                                <td colspan="8" class="empty">No opening stock vouchers found.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="pagination">
                    <button type="button" :disabled="pagination.current_page <= 1" @click="loadVouchers(pagination.current_page - 1)">Previous</button>
                    <span>{{ pagination.from || 0 }}-{{ pagination.to || 0 }} of {{ pagination.total || 0 }}</span>
                    <button type="button" :disabled="pagination.current_page >= pagination.last_page" @click="loadVouchers(pagination.current_page + 1)">Next</button>
                </div>
            </section>
        </div>
    </Layout>
</template>

<style scoped>
.inventory-page { padding: 4px 0 28px; }
.page-heading, .panel-head, .actions, .pagination, .row-actions { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.page-heading { margin-bottom: 18px; }
.page-heading span { color: #2457d6; font-size: 10px; font-weight: 800; letter-spacing: 1.2px; }
.page-heading h1, .panel-head h2 { margin: 0; color: #142139; font-weight: 800; }
.page-heading p { margin: 6px 0 0; color: #758197; font-size: 13px; }
.panel { margin-bottom: 18px; padding: 18px; background: #fff; border: 1px solid #dfe6ef; border-radius: 14px; box-shadow: 0 7px 24px rgba(25, 50, 84, .045); }
.form-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 14px; }
label { display: grid; gap: 6px; color: #526078; font-size: 11px; font-weight: 750; }
input, select { min-height: 38px; padding: 8px 10px; color: #22304a; background: #fff; border: 1px solid #d8e0eb; border-radius: 8px; font-size: 12px; }
button { min-height: 34px; padding: 7px 12px; color: #35435b; background: #fff; border: 1px solid #d8e0eb; border-radius: 8px; font-size: 11px; font-weight: 750; cursor: pointer; }
button.primary { color: #fff; background: #2457d6; border-color: #2457d6; }
button.danger { color: #d23f49; background: #fff3f4; border-color: #ffd6da; }
button:disabled { opacity: .55; cursor: not-allowed; }
.product-search { position: relative; margin-bottom: 14px; }
.product-search input { width: 100%; }
.search-results { position: absolute; z-index: 20; right: 0; left: 0; top: 44px; display: grid; max-height: 220px; overflow: auto; background: #fff; border: 1px solid #dce4ef; border-radius: 9px; box-shadow: 0 12px 30px rgba(15, 34, 66, .12); }
.search-results button { display: grid; justify-items: start; border: 0; border-bottom: 1px solid #eef2f6; border-radius: 0; }
.search-results span, td span { display: block; color: #7a869a; font-size: 10px; }
.table-wrapper { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; }
th { padding: 12px 10px; color: #69758a; background: #f8fafc; border-bottom: 1px solid #e7ecf2; text-align: left; white-space: nowrap; font-size: 10px; font-weight: 800; text-transform: uppercase; }
td { padding: 12px 10px; color: #27344c; border-bottom: 1px solid #edf1f5; white-space: nowrap; font-size: 12px; }
td input, td select { min-width: 105px; }
.empty { padding: 28px !important; color: #8490a2; text-align: center; }
.badge { display: inline-flex; padding: 5px 8px; border-radius: 7px; background: #f0f2f5; color: #69758a; font-size: 10px; font-weight: 800; text-transform: capitalize; }
.badge.approved, .badge.confirmed { color: #168757; background: #eaf8f1; }
.badge.reversed { color: #d23f49; background: #fff3f4; }
.error-box { display: grid; gap: 4px; margin-top: 12px; padding: 10px; color: #96333a; background: #fff3f4; border: 1px solid #ffd4d8; border-radius: 8px; font-size: 11px; }
.actions { justify-content: flex-end; margin-top: 14px; }
.pagination { justify-content: flex-end; margin-top: 12px; color: #69758a; font-size: 11px; }
@media (max-width: 1000px) { .form-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 700px) { .page-heading, .panel-head { align-items: stretch; flex-direction: column; } .form-grid { grid-template-columns: 1fr; } }
</style>
