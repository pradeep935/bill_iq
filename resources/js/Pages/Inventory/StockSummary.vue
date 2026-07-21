<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import Layout from '../Layout.vue';
import InventoryApi from './InventoryApi';

defineProps({
    page: { type: String, default: 'stock-summary' },
    title: { type: String, default: 'Current Stock' },
});

const loading = ref(false);
const rows = ref([]);
const references = ref({ branches: [], warehouses: [] });
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });
const filters = ref({
    branch_id: '',
    warehouse_id: '',
    category: '',
    brand: '',
    product_id: '',
    stock_status: '',
    expiry_status: '',
    sort: 'product_name',
    direction: 'asc',
    per_page: 15,
});

let timer = null;

const filteredWarehouses = computed(() => {
    if (!filters.value.branch_id) {
        return references.value.warehouses || [];
    }

    return (references.value.warehouses || []).filter((warehouse) =>
        Number(warehouse.branch_id || 0) === Number(filters.value.branch_id)
    );
});

const loadReferences = async () => {
    references.value = await InventoryApi.stockReferences();
};

const loadSummary = async (page = 1) => {
    loading.value = true;

    try {
        const response = await InventoryApi.stockSummary({
            ...filters.value,
            page,
        });
        rows.value = response.items || [];
        pagination.value = response.pagination || pagination.value;
    } finally {
        loading.value = false;
    }
};

const clearFilters = () => {
    filters.value = {
        branch_id: '',
        warehouse_id: '',
        category: '',
        brand: '',
        product_id: '',
        stock_status: '',
        expiry_status: '',
        sort: 'product_name',
        direction: 'asc',
        per_page: 15,
    };
};

const sortBy = (field) => {
    if (filters.value.sort === field) {
        filters.value.direction = filters.value.direction === 'asc' ? 'desc' : 'asc';
    } else {
        filters.value.sort = field;
        filters.value.direction = 'asc';
    }
};

const exportRows = () => {
    if (!rows.value.length) {
        alert('Export karne ke liye stock rows available nahi hain.');
        return;
    }

    const headings = ['Product', 'SKU', 'Barcode', 'Branch', 'Warehouse', 'Batch', 'Expiry', 'Qty', 'Avg Cost', 'Stock Value', 'Reorder', 'Status'];
    const csv = [headings, ...rows.value.map((row) => [
        row.product_name,
        row.sku,
        row.barcode,
        row.branch,
        row.warehouse,
        row.batch,
        row.expiry_date,
        row.quantity_available,
        row.average_cost,
        row.stock_value,
        row.reorder_level,
        row.stock_status,
    ])]
        .map((line) => line.map((value) => `"${String(value ?? '').replaceAll('"', '""')}"`).join(','))
        .join('\n');

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'stock-summary-export.csv';
    link.click();
    URL.revokeObjectURL(url);
};

const formatQty = (value) => Number(value || 0).toLocaleString('en-IN', {
    minimumFractionDigits: 3,
    maximumFractionDigits: 3,
});

const formatMoney = (value) => Number(value || 0).toLocaleString('en-IN', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

watch(filters, () => {
    clearTimeout(timer);
    timer = setTimeout(() => loadSummary(1), 300);
}, { deep: true });

onMounted(async () => {
    await loadReferences();
    await loadSummary();
});
</script>

<template>
    <Layout :page="page" :title="title">
        <div class="stock-page">
            <div class="page-heading">
                <div>
                    <span>STOCK LEDGER</span>
                    <h1>Current Stock</h1>
                    <p>Ledger-based stock by business, branch, warehouse, product, variant and batch.</p>
                </div>
                <button type="button" @click="exportRows">Export</button>
            </div>

            <section class="panel">
                <div class="filters">
                    <select v-model="filters.branch_id">
                        <option value="">All Branches</option>
                        <option v-for="branch in references.branches" :key="branch.id" :value="branch.id">
                            {{ branch.name }}
                        </option>
                    </select>

                    <select v-model="filters.warehouse_id">
                        <option value="">All Warehouses</option>
                        <option v-for="warehouse in filteredWarehouses" :key="warehouse.id" :value="warehouse.id">
                            {{ warehouse.name }}
                        </option>
                    </select>

                    <input v-model="filters.category" type="text" placeholder="Category" />
                    <input v-model="filters.brand" type="text" placeholder="Brand" />
                    <input v-model="filters.product_id" type="number" placeholder="Product ID" />

                    <select v-model="filters.stock_status">
                        <option value="">All Stock</option>
                        <option value="in">In Stock</option>
                        <option value="low">Low Stock</option>
                        <option value="out">Out of Stock</option>
                        <option value="over">Over Stock</option>
                    </select>

                    <select v-model="filters.expiry_status">
                        <option value="">All Expiry</option>
                        <option value="expired">Expired</option>
                        <option value="expiring">Expiring in 30 days</option>
                    </select>

                    <select v-model="filters.per_page">
                        <option :value="15">15 / page</option>
                        <option :value="25">25 / page</option>
                        <option :value="50">50 / page</option>
                    </select>

                    <button type="button" @click="clearFilters">Clear</button>
                </div>

                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th @click="sortBy('product_name')">Product Name</th>
                                <th @click="sortBy('sku')">SKU</th>
                                <th>Barcode</th>
                                <th>Branch</th>
                                <th>Warehouse</th>
                                <th>Batch</th>
                                <th>Expiry Date</th>
                                <th @click="sortBy('quantity_available')">Quantity Available</th>
                                <th @click="sortBy('average_cost')">Average Cost</th>
                                <th @click="sortBy('stock_value')">Stock Value</th>
                                <th>Reorder Level</th>
                                <th>Stock Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in rows" :key="`${row.product_id}-${row.branch_id}-${row.warehouse_id}-${row.batch_id}`">
                                <td>{{ row.product_name }}</td>
                                <td>{{ row.sku || '-' }}</td>
                                <td>{{ row.barcode || '-' }}</td>
                                <td>{{ row.branch || '-' }}</td>
                                <td>{{ row.warehouse || '-' }}</td>
                                <td>{{ row.batch || '-' }}</td>
                                <td>{{ row.expiry_date || '-' }}</td>
                                <td>{{ formatQty(row.quantity_available) }}</td>
                                <td>Rs. {{ formatMoney(row.average_cost) }}</td>
                                <td>Rs. {{ formatMoney(row.stock_value) }}</td>
                                <td>{{ formatQty(row.reorder_level) }}</td>
                                <td>
                                    <span class="badge" :class="row.stock_status.toLowerCase().replaceAll(' ', '-')">
                                        {{ row.stock_status }}
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="!rows.length && !loading">
                                <td colspan="12" class="empty">No stock ledger balance found.</td>
                            </tr>
                            <tr v-if="loading">
                                <td colspan="12" class="empty">Loading stock...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="pagination">
                    <button type="button" :disabled="pagination.current_page <= 1" @click="loadSummary(pagination.current_page - 1)">Previous</button>
                    <span>{{ pagination.from || 0 }}-{{ pagination.to || 0 }} of {{ pagination.total || 0 }}</span>
                    <button type="button" :disabled="pagination.current_page >= pagination.last_page" @click="loadSummary(pagination.current_page + 1)">Next</button>
                </div>
            </section>
        </div>
    </Layout>
</template>

<style scoped>
.stock-page { padding: 4px 0 28px; }
.page-heading, .pagination { display: flex; align-items: center; justify-content: space-between; gap: 14px; }
.page-heading { margin-bottom: 18px; }
.page-heading span { color: #2457d6; font-size: 10px; font-weight: 800; letter-spacing: 1.2px; }
.page-heading h1 { margin: 0; color: #142139; font-size: 28px; font-weight: 800; }
.page-heading p { margin: 6px 0 0; color: #758197; font-size: 13px; }
.panel { overflow: hidden; background: #fff; border: 1px solid #dfe6ef; border-radius: 14px; box-shadow: 0 7px 24px rgba(25, 50, 84, .045); }
.filters { display: flex; flex-wrap: wrap; gap: 9px; padding: 16px; border-bottom: 1px solid #e8edf3; }
input, select, button { min-height: 38px; padding: 8px 10px; color: #344159; background: #fff; border: 1px solid #d8e0eb; border-radius: 8px; font-size: 12px; }
button { font-weight: 750; cursor: pointer; }
.table-wrapper { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; }
th { padding: 12px 10px; color: #69758a; background: #f8fafc; border-bottom: 1px solid #e7ecf2; text-align: left; white-space: nowrap; font-size: 10px; font-weight: 800; text-transform: uppercase; cursor: pointer; }
td { padding: 12px 10px; color: #27344c; border-bottom: 1px solid #edf1f5; white-space: nowrap; font-size: 12px; }
.empty { padding: 34px !important; color: #8490a2; text-align: center; }
.badge { display: inline-flex; padding: 5px 8px; border-radius: 7px; color: #2457d6; background: #edf2ff; font-size: 10px; font-weight: 800; }
.badge.out-of-stock { color: #d23f49; background: #fff3f4; }
.badge.low-stock { color: #9b6a0c; background: #fff4d4; }
.badge.over-stock { color: #6e45b8; background: #f3edff; }
.badge.in-stock { color: #168757; background: #eaf8f1; }
.pagination { justify-content: flex-end; padding: 14px 16px; color: #69758a; font-size: 11px; }
@media (max-width: 700px) { .page-heading { align-items: stretch; flex-direction: column; } .filters > * { flex: 1; min-width: 130px; } }
</style>
