<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import Layout from '../Layout.vue';
import InventoryApi from '../Inventory/InventoryApi';
import InventoryModuleScaffold from '../Inventory/Shared/InventoryModuleScaffold.vue';
import InventoryTable from '../Inventory/Shared/InventoryTable.vue';
import { formatInventoryDateTime } from '../Inventory/Shared/formatters';

const props = defineProps({ page: String, title: String, initial_tab: { type: String, default: 'inventory' } });
const tab = ref(props.initial_tab);
const loading = ref(false);
const loaded = ref(false);
const filters = reactive({ search: '', from_date: '', to_date: '', per_page: 50 });
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });
const stock = ref([]);
const movement = ref([]);
const valuation = ref({});
const serials = ref({});
const barcodes = ref({});
const manufacturing = ref({});

const tabs = ['inventory', 'ledger', 'valuation', 'audit', 'acceptance', 'serials', 'barcodes', 'manufacturing'];
const activeRows = computed(() => {
    if (tab.value === 'inventory') return stock.value;
    if (tab.value === 'ledger' || tab.value === 'audit' || tab.value === 'acceptance') return movement.value;
    if (tab.value === 'valuation') return valuation.value.stock_value_by_location || valuation.value.layers || [];
    if (tab.value === 'serials') return serials.value.serial_stock || serials.value.warranty_expiry || [];
    if (tab.value === 'barcodes') return barcodes.value.product_barcodes || barcodes.value.missing_barcodes || [];
    return manufacturing.value.production_register || manufacturing.value.bom_report || [];
});
const cards = computed(() => [
    { label: 'Stock Lines', value: stock.value.length, tone: 'info' },
    { label: 'Ledger Moves', value: movement.value.length, tone: 'money' },
    { label: 'Stock Value', value: Number(valuation.value.total_value || 0).toLocaleString('en-IN'), tone: 'good' },
    { label: 'Serial Issues', value: (serials.value.damaged_blocked || []).length, tone: 'bad' },
    { label: 'Barcode Issues', value: (barcodes.value.duplicates || []).length, tone: 'warn' },
    { label: 'Production Rows', value: activeRows.value.length, tone: 'info' },
]);

const load = async () => {
    loading.value = true;
    try {
        const params = { ...filters, page: pagination.value.current_page };
        const [stockRows, reports, valueRows, serialRows, barcodeRows, mfgRows] = await Promise.all([
            InventoryApi.stockSummary(params).catch(() => ({ stocks: [], pagination: {} })),
            InventoryApi.inventoryReports(params).catch(() => ({})),
            InventoryApi.inventoryValuation(params).catch(() => ({})),
            InventoryApi.serialReports(params).catch(() => ({})),
            InventoryApi.barcodeReports(params).catch(() => ({})),
            InventoryApi.manufacturingReports(params).catch(() => ({})),
        ]);
        stock.value = stockRows.stocks || [];
        movement.value = reports.movement_report || reports.stock_movement || [];
        valuation.value = valueRows || {};
        serials.value = serialRows || {};
        barcodes.value = barcodeRows || {};
        manufacturing.value = mfgRows || {};
        pagination.value = stockRows.pagination || pagination.value;
    } finally {
        loaded.value = true;
        loading.value = false;
    }
};

const exportFile = (type = 'csv') => {
    const rows = activeRows.value || [];
    const headers = Object.keys(rows[0] || { empty: '' }).filter((h) => typeof rows[0]?.[h] !== 'object');
    const csv = [headers.join(','), ...rows.map((r) => headers.map((h) => `"${String(r[h] ?? '').replaceAll('"', '""')}"`).join(','))].join('\n');
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([csv], { type: type === 'excel' ? 'application/vnd.ms-excel' : 'text/csv' }));
    link.download = `${tab.value}-report.${type === 'excel' ? 'xls' : 'csv'}`;
    link.click();
};

onMounted(load);
</script>

<template>
    <Layout :page="page" :title="title">
        <template #topbar-title><div class="bill-page-title"><span>REPORTS</span><h1>{{ title }}</h1><p>Inventory, ledger, valuation, audit and traceability reports from live modules.</p></div></template>
        <InventoryModuleScaffold :title="title" :cards="cards" :loading="loading" :initial-loaded="loaded" :pagination="pagination" @page="(p) => { pagination.current_page = p; load(); }">
            <template #toolbar><button @click="load">Refresh</button><button @click="exportFile('csv')">CSV</button><button @click="exportFile('excel')">Excel</button><button @click="window.print()">PDF / Print</button></template>
            <template #section-actions><div class="tabs"><button v-for="t in tabs" :key="t" :class="{ active: tab === t }" @click="tab = t">{{ t }}</button></div></template>
            <template #filters>
                <input v-model="filters.search" placeholder="Search report data" @keyup.enter="load" />
                <input v-model="filters.from_date" type="date" @change="load" />
                <input v-model="filters.to_date" type="date" @change="load" />
                <select v-model="filters.per_page" @change="load"><option>25</option><option>50</option><option>100</option></select>
                <button @click="filters.search = ''; filters.from_date = ''; filters.to_date = ''; load()">Clear</button>
            </template>
            <InventoryTable v-if="tab === 'inventory'" :columns="[{key:'product_name',label:'Product'},{key:'sku',label:'SKU'},{key:'branch_name',label:'Branch'},{key:'warehouse_name',label:'Warehouse'},{key:'quantity',label:'Qty'},{key:'stock_value',label:'Value'}]" :rows="stock" />
            <InventoryTable v-else-if="tab === 'valuation'" :columns="[{key:'product_name',label:'Product'},{key:'warehouse_name',label:'Warehouse'},{key:'quantity',label:'Qty'},{key:'average_cost',label:'Avg Cost'},{key:'stock_value',label:'Value'}]" :rows="activeRows" />
            <InventoryTable v-else-if="tab === 'serials'" :columns="[{key:'serial_number',label:'Serial'},{key:'imei_1',label:'IMEI 1'},{key:'product',label:'Product'},{key:'current_status',label:'Status'},{key:'warranty_expiry_date',label:'Warranty'}]" :rows="activeRows">
                <template #cell-product="{ row }">{{ row.product?.name || row.product_name || '-' }}</template>
            </InventoryTable>
            <InventoryTable v-else-if="tab === 'barcodes'" :columns="[{key:'barcode',label:'Barcode'},{key:'product',label:'Product'},{key:'barcode_type',label:'Type'},{key:'is_primary',label:'Primary'},{key:'is_active',label:'Active'}]" :rows="activeRows">
                <template #cell-product="{ row }">{{ row.product?.name || row.product_name || '-' }}</template>
            </InventoryTable>
            <InventoryTable v-else-if="tab === 'manufacturing'" :columns="[{key:'order_number',label:'Order'},{key:'bom_code',label:'BOM'},{key:'finished_product',label:'Finished Product'},{key:'planned_quantity',label:'Planned'},{key:'produced_quantity',label:'Produced'},{key:'status',label:'Status'}]" :rows="activeRows" />
            <InventoryTable v-else :columns="[{key:'posted_at',label:'Date & Time'},{key:'transaction_date',label:'Document Date'},{key:'transaction_type',label:'Type'},{key:'product',label:'Product'},{key:'movement',label:'Movement'},{key:'quantity_in',label:'In'},{key:'quantity_out',label:'Out'},{key:'reference_number',label:'Reference'}]" :rows="activeRows">
                <template #cell-posted_at="{ row }">{{ formatInventoryDateTime(row.posted_at || row.created_at) }}</template>
                <template #cell-product="{ row }">{{ row.product?.name || row.product_name || '-' }}</template>
            </InventoryTable>
        </InventoryModuleScaffold>
    </Layout>
</template>

<style scoped>
.tabs{display:flex;flex-wrap:wrap;gap:8px}.tabs button.active{background:#142139;color:#fff}input,select,button{border:1px solid #d8e0eb;border-radius:8px;font-size:12px;min-height:38px;padding:8px 10px}button{background:#fff;cursor:pointer;font-weight:800}
</style>
