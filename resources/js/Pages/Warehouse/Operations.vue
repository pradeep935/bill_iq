<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import Layout from '../Layout.vue';
import InventoryApi from '../Inventory/InventoryApi';
import InventoryModuleScaffold from '../Inventory/Shared/InventoryModuleScaffold.vue';
import InventoryTable from '../Inventory/Shared/InventoryTable.vue';

const props = defineProps({ page: String, title: String, initial_tab: { type: String, default: 'balances' } });

const tab = ref(props.initial_tab);
const loading = ref(false);
const loaded = ref(false);
const message = ref('');
const refs = ref({ branches: [], warehouses: [] });
const balances = ref([]);
const locations = ref([]);
const transfers = ref([]);
const batches = ref([]);
const serials = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });
const filters = reactive({ search: '', branch_id: '', warehouse_id: '', per_page: 25 });
const editingLocationId = ref(null);
const locationForm = reactive({ branch_id: '', warehouse_id: '', zone: '', aisle: '', rack: '', shelf: '', bin: '', status: 'active' });

const cards = computed(() => [
    { label: 'Warehouses', value: refs.value.warehouses?.length || 0, tone: 'info' },
    { label: 'Bins / Racks', value: locations.value.length, tone: 'good' },
    { label: 'Stock Lines', value: balances.value.length, tone: 'money' },
    { label: 'Transfers', value: transfers.value.length, tone: 'warn' },
    { label: 'Batches', value: batches.value.length, tone: 'info' },
    { label: 'Serials', value: serials.value.length, tone: 'bad' },
]);

const selectedWarehouseName = (id) => refs.value.warehouses.find((w) => Number(w.id) === Number(id))?.name || '-';
const selectedBranchName = (id) => refs.value.branches.find((b) => Number(b.id) === Number(id))?.name || '-';
const params = () => ({ ...filters, page: pagination.value.current_page });
const clearFilters = () => { filters.search = ''; filters.branch_id = ''; filters.warehouse_id = ''; pagination.value.current_page = 1; load(); };

const load = async () => {
    loading.value = true;
    message.value = '';
    try {
        refs.value = await InventoryApi.stockReferences();
        const [stock, locs, transferRows, batchRows, serialRows] = await Promise.all([
            InventoryApi.stockSummary(params()).catch(() => ({ stocks: [], pagination: {} })),
            InventoryApi.warehouseLocations(params()).catch(() => ({ locations: [] })),
            InventoryApi.stockTransfers(params()).catch(() => ({ transfers: [] })),
            InventoryApi.batchList(params()).catch(() => ({ batches: [] })),
            InventoryApi.serialList(params()).catch(() => ({ serials: [] })),
        ]);
        balances.value = stock.stocks || [];
        locations.value = locs.locations || locs.data || [];
        transfers.value = transferRows.transfers || [];
        batches.value = batchRows.batches || [];
        serials.value = serialRows.serials || [];
        pagination.value = stock.pagination || pagination.value;
    } finally {
        loaded.value = true;
        loading.value = false;
    }
};

const saveLocation = async () => {
    loading.value = true;
    try {
        await InventoryApi.saveWarehouseLocation({ ...locationForm }, editingLocationId.value);
        editingLocationId.value = null;
        Object.assign(locationForm, { branch_id: '', warehouse_id: '', zone: '', aisle: '', rack: '', shelf: '', bin: '', status: 'active' });
        message.value = 'Location saved successfully.';
        await load();
    } catch (e) {
        message.value = e?.response?.data?.message || Object.values(e?.response?.data?.errors || {})[0]?.[0] || 'Unable to save location.';
    } finally {
        loading.value = false;
    }
};

const editLocation = (row) => {
    editingLocationId.value = row.id;
    Object.assign(locationForm, {
        branch_id: row.branch_id || '',
        warehouse_id: row.warehouse_id || '',
        zone: row.zone || '',
        aisle: row.aisle || '',
        rack: row.rack || '',
        shelf: row.shelf || '',
        bin: row.bin || '',
        status: row.status || 'active',
    });
};

const exportCsv = (rows, name) => {
    const safe = rows || [];
    const headers = Object.keys(safe[0] || { empty: '' }).filter((h) => typeof safe[0]?.[h] !== 'object');
    const csv = [headers.join(','), ...safe.map((r) => headers.map((h) => `"${String(r[h] ?? '').replaceAll('"', '""')}"`).join(','))].join('\n');
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
    link.download = name;
    link.click();
};

const activeRows = computed(() => tab.value === 'bins' ? locations.value : tab.value === 'requests' ? transfers.value : tab.value === 'allocation' ? [...batches.value, ...serials.value] : balances.value);

onMounted(load);
</script>

<template>
    <Layout :page="page" :title="title">
        <template #topbar-title><div class="bill-page-title"><span>WAREHOUSE</span><h1>{{ title }}</h1><p>Live warehouse locations, balances, transfers, batch and serial allocation.</p></div></template>
        <InventoryModuleScaffold :title="title" :subtitle="'All records are loaded from inventory stock and warehouse endpoints.'" :cards="cards" :loading="loading" :initial-loaded="loaded" :pagination="pagination" @page="(p) => { pagination.current_page = p; load(); }">
            <template #toolbar><button @click="load">Refresh</button><button @click="exportCsv(activeRows, `${tab}.csv`)">Export CSV</button><button @click="window.print()">Print</button></template>
            <template #section-actions><div class="tabs"><button v-for="t in ['bins','balances','requests','allocation']" :key="t" :class="{ active: tab === t }" @click="tab = t">{{ t }}</button></div></template>
            <template #filters>
                <input v-model="filters.search" placeholder="Search product, batch, serial or transfer" @keyup.enter="load" />
                <select v-model="filters.branch_id" @change="load"><option value="">All branches</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select>
                <select v-model="filters.warehouse_id" @change="load"><option value="">All warehouses</option><option v-for="w in refs.warehouses" :key="w.id" :value="w.id">{{ w.name }}</option></select>
                <select v-model="filters.per_page" @change="load"><option>10</option><option>25</option><option>50</option><option>100</option></select>
                <button @click="clearFilters">Clear</button>
            </template>

            <p v-if="message" class="notice">{{ message }}</p>

            <section v-if="tab === 'bins'" class="mini-panel">
                <div class="form-grid">
                    <select v-model="locationForm.branch_id"><option value="">Branch</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select>
                    <select v-model="locationForm.warehouse_id"><option value="">Warehouse</option><option v-for="w in refs.warehouses" :key="w.id" :value="w.id">{{ w.name }}</option></select>
                    <input v-model="locationForm.zone" placeholder="Zone" />
                    <input v-model="locationForm.aisle" placeholder="Aisle" />
                    <input v-model="locationForm.rack" placeholder="Rack" />
                    <input v-model="locationForm.shelf" placeholder="Shelf" />
                    <input v-model="locationForm.bin" placeholder="Bin" />
                    <select v-model="locationForm.status"><option>active</option><option>inactive</option><option>blocked</option></select>
                    <button :disabled="loading" @click="saveLocation">{{ editingLocationId ? 'Update Location' : 'Save Location' }}</button>
                </div>
                <InventoryTable :columns="[{key:'zone',label:'Zone'},{key:'aisle',label:'Aisle'},{key:'rack',label:'Rack'},{key:'shelf',label:'Shelf'},{key:'bin',label:'Bin'},{key:'status',label:'Status'}]" :rows="locations">
                    <template #actions="{ row }"><button @click="editLocation(row)">Edit</button></template>
                </InventoryTable>
            </section>

            <InventoryTable v-if="tab === 'balances'" :columns="[{key:'product_name',label:'Product'},{key:'sku',label:'SKU'},{key:'branch_name',label:'Branch'},{key:'warehouse_name',label:'Warehouse'},{key:'quantity',label:'Quantity'},{key:'stock_value',label:'Value'}]" :rows="balances" />

            <InventoryTable v-if="tab === 'requests'" :columns="[{key:'voucher_number',label:'Transfer No.'},{key:'transfer_date',label:'Date'},{key:'status',label:'Status'},{key:'source_warehouse_id',label:'From'},{key:'destination_warehouse_id',label:'To'},{key:'total_quantity',label:'Qty'}]" :rows="transfers">
                <template #cell-source_warehouse_id="{ value }">{{ selectedWarehouseName(value) }}</template>
                <template #cell-destination_warehouse_id="{ value }">{{ selectedWarehouseName(value) }}</template>
            </InventoryTable>

            <InventoryTable v-if="tab === 'allocation'" :columns="[{key:'batch_number',label:'Batch / Serial'},{key:'serial_number',label:'Serial'},{key:'product',label:'Product'},{key:'branch_id',label:'Branch'},{key:'warehouse_id',label:'Warehouse'},{key:'current_status',label:'Status'}]" :rows="activeRows">
                <template #cell-product="{ row }">{{ row.product?.name || row.product_name || '-' }}</template>
                <template #cell-branch_id="{ value }">{{ selectedBranchName(value) }}</template>
                <template #cell-warehouse_id="{ value }">{{ selectedWarehouseName(value) }}</template>
            </InventoryTable>
        </InventoryModuleScaffold>
    </Layout>
</template>

<style scoped>
.tabs{display:flex;flex-wrap:wrap;gap:8px}.tabs button.active{background:#142139;color:#fff}.notice{background:#eef6ff;border:1px solid #cfe3ff;border-radius:8px;color:#1d4f8f;font-size:12px;font-weight:800;padding:10px}.mini-panel{display:grid;gap:14px}.form-grid{display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));margin-bottom:14px}input,select,button{border:1px solid #d8e0eb;border-radius:8px;font-size:12px;min-height:38px;padding:8px 10px}button{background:#fff;cursor:pointer;font-weight:800}
</style>
