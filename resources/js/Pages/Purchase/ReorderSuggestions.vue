<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import Layout from '../Layout.vue';
import InventoryApi from '../Inventory/InventoryApi';
import InventoryModuleScaffold from '../Inventory/Shared/InventoryModuleScaffold.vue';
import InventoryTable from '../Inventory/Shared/InventoryTable.vue';
import SearchSelect from '../../Components/Common/SearchSelect.vue';

const props = defineProps({ page: String, title: String });
const loading = ref(false);
const loaded = ref(false);
const rows = ref([]);
const refs = ref({ branches: [], warehouses: [] });
const filters = reactive({ search: '', branch_id: '', warehouse_id: '', per_page: 50 });
const perPageOptions = [{ value: 25, label: '25' }, { value: 50, label: '50' }, { value: 100, label: '100' }];
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });

const suggestions = computed(() => rows.value.map((r) => {
    const available = Number(r.quantity ?? r.current_stock ?? r.available_quantity ?? 0);
    const reorder = Number(r.reorder_level ?? r.product?.reorder_level ?? 0);
    const target = Number(r.reorder_quantity ?? r.product?.reorder_quantity ?? reorder);
    return { ...r, available, reorder_level: reorder, suggested_quantity: Math.max(0, target - available), shortage_status: reorder && available <= reorder ? 'Reorder' : 'OK' };
}).filter((r) => r.shortage_status === 'Reorder'));

const cards = computed(() => [
    { label: 'Reorder Items', value: suggestions.value.length, tone: 'bad' },
    { label: 'Stock Lines', value: rows.value.length, tone: 'info' },
    { label: 'Suggested Qty', value: suggestions.value.reduce((sum, r) => sum + Number(r.suggested_quantity || 0), 0).toFixed(3), tone: 'warn' },
]);

const load = async () => {
    loading.value = true;
    try {
        refs.value = await InventoryApi.stockReferences();
        const response = await InventoryApi.stockSummary({ ...filters, page: pagination.value.current_page });
        rows.value = response.stocks || [];
        pagination.value = response.pagination || pagination.value;
    } finally {
        loaded.value = true;
        loading.value = false;
    }
};

const exportCsv = () => {
    const headers = ['product_name', 'sku', 'available', 'reorder_level', 'suggested_quantity', 'shortage_status'];
    const csv = [headers.join(','), ...suggestions.value.map((r) => headers.map((h) => `"${String(r[h] ?? '').replaceAll('"', '""')}"`).join(','))].join('\n');
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
    link.download = 'reorder-suggestions.csv';
    link.click();
};

onMounted(load);
</script>

<template>
    <Layout :page="page" :title="title">
        <template #topbar-title><div class="bill-page-title"><span>PURCHASE</span><h1>{{ title }}</h1><p>Reorder suggestions calculated from current stock and product reorder levels.</p></div></template>
        <InventoryModuleScaffold :title="title" :cards="cards" :loading="loading" :initial-loaded="loaded" :pagination="pagination" @page="(p) => { pagination.current_page = p; load(); }">
            <template #toolbar><button @click="load">Refresh</button><button @click="exportCsv">Export CSV</button><button @click="window.print()">Print</button></template>
            <template #filters>
                <input v-model="filters.search" placeholder="Search product or SKU" @keyup.enter="load" />
                <SearchSelect v-model="filters.branch_id" label="Branch" :options="refs.branches" option-value-key="id" option-label-key="name" select-placeholder="All Branches" @selected="load" />
                <SearchSelect v-model="filters.warehouse_id" label="Warehouse" :options="refs.warehouses" option-value-key="id" option-label-key="name" select-placeholder="All Warehouses" @selected="load" />
                <SearchSelect v-model="filters.per_page" label="Rows" :options="perPageOptions" option-value-key="value" option-label-key="label" select-placeholder="Rows" @selected="load" />
                <button @click="filters.search = ''; filters.branch_id = ''; filters.warehouse_id = ''; load()">Clear</button>
            </template>
            <InventoryTable :columns="[{key:'product_name',label:'Product'},{key:'sku',label:'SKU'},{key:'warehouse_name',label:'Warehouse'},{key:'available',label:'Available'},{key:'reorder_level',label:'Reorder Level'},{key:'suggested_quantity',label:'Suggested Qty'},{key:'shortage_status',label:'Status'}]" :rows="suggestions" empty-text="No reorder suggestions found." />
        </InventoryModuleScaffold>
    </Layout>
</template>

<style scoped>
input,select,button{border:1px solid #d8e0eb;border-radius:8px;font-size:12px;min-height:38px;padding:8px 10px}button{background:#fff;cursor:pointer;font-weight:800}:deep(.search-select){min-width:180px}
</style>
