<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import Layout from '../Layout.vue';
import OrderApi from '../Orders/OrderApi';
import InventoryApi from '../Inventory/InventoryApi';
import InventoryModuleScaffold from '../Inventory/Shared/InventoryModuleScaffold.vue';
import InventoryTable from '../Inventory/Shared/InventoryTable.vue';

const props = defineProps({ page: String, title: String, initial_tab: { type: String, default: 'outward' } });
const tab = ref(props.initial_tab);
const loading = ref(false);
const loaded = ref(false);
const filters = reactive({ search: '', per_page: 25 });
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });
const challans = ref([]);
const salesOrders = ref([]);
const ledger = ref([]);
const message = ref('');

const cards = computed(() => [
    { label: 'Dispatch Docs', value: challans.value.length, tone: 'info' },
    { label: 'Reserved Orders', value: salesOrders.value.filter((o) => ['reserved', 'partial'].includes(o.reservation_status)).length, tone: 'warn' },
    { label: 'Outward Lines', value: ledger.value.length, tone: 'money' },
    { label: 'Pending Dispatch', value: salesOrders.value.filter((o) => o.dispatch_status !== 'dispatched').length, tone: 'bad' },
]);

const load = async () => {
    loading.value = true;
    message.value = '';
    try {
        const [dc, so, reports] = await Promise.all([
            OrderApi.deliveryChallans({ search: filters.search, per_page: filters.per_page }).catch(() => ({ delivery_challans: [] })),
            OrderApi.salesOrders({ search: filters.search, per_page: filters.per_page }).catch(() => ({ sales_orders: [] })),
            InventoryApi.inventoryReports({ search: filters.search, transaction_type: 'sale', per_page: filters.per_page }).catch(() => ({})),
        ]);
        challans.value = dc.delivery_challans || [];
        salesOrders.value = so.sales_orders || [];
        ledger.value = reports.movement_report || reports.stock_movement || [];
        pagination.value = dc.pagination || pagination.value;
    } finally {
        loaded.value = true;
        loading.value = false;
    }
};

const runAction = async (callback, success) => {
    loading.value = true;
    message.value = '';
    try {
        await callback();
        message.value = success;
        await load();
    } catch (e) {
        message.value = e?.response?.data?.message || Object.values(e?.response?.data?.errors || {})[0]?.[0] || 'Action failed.';
    } finally {
        loading.value = false;
    }
};

const reserveOrder = (row) => runAction(() => OrderApi.approveSalesOrder(row.id), 'Stock reserved successfully.');
const releaseOrder = (row) => {
    const reason = window.prompt('Reason for releasing reserved stock?', 'Released from reserved stock screen') || '';
    return runAction(() => OrderApi.releaseSalesOrderReservation(row.id, reason), 'Reservation released successfully.');
};
const makeChallan = (row, dispatch = false) => runAction(() => OrderApi.createDeliveryFromOrder(row.id, dispatch), dispatch ? 'Delivery challan created and dispatched.' : 'Delivery challan created.');
const dispatchChallan = (row) => runAction(() => OrderApi.dispatchChallan(row.id), 'Stock outward posted successfully.');

const exportCsv = (rows, filename) => {
    const headers = Object.keys(rows[0] || { empty: '' }).filter((h) => typeof rows[0]?.[h] !== 'object');
    const csv = [headers.join(','), ...rows.map((r) => headers.map((h) => `"${String(r[h] ?? '').replaceAll('"', '""')}"`).join(','))].join('\n');
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
    link.download = filename;
    link.click();
};

onMounted(load);
</script>

<template>
    <Layout :page="page" :title="title">
        <template #topbar-title><div class="bill-page-title"><span>SALES INVENTORY</span><h1>{{ title }}</h1><p>Sales dispatch and reserved stock from live order and ledger records.</p></div></template>
        <InventoryModuleScaffold :title="title" :cards="cards" :loading="loading" :initial-loaded="loaded" :pagination="pagination" @page="(p) => { pagination.current_page = p; load(); }">
            <template #toolbar><button @click="load">Refresh</button><button @click="exportCsv(tab === 'reserved' ? salesOrders : challans, `${tab}-stock.csv`)">Export CSV</button><button @click="window.print()">Print</button></template>
            <template #section-actions><div class="tabs"><button :class="{ active: tab === 'outward' }" @click="tab = 'outward'">outward</button><button :class="{ active: tab === 'reserved' }" @click="tab = 'reserved'">reserved</button><button :class="{ active: tab === 'ledger' }" @click="tab = 'ledger'">ledger</button></div></template>
            <template #filters><input v-model="filters.search" placeholder="Search document, customer or product" @keyup.enter="load" /><select v-model="filters.per_page" @change="load"><option>10</option><option>25</option><option>50</option></select><button @click="filters.search = ''; load()">Clear</button></template>
            <p v-if="message" class="notice">{{ message }}</p>
            <InventoryTable v-if="tab === 'outward'" :columns="[{key:'challan_number',label:'Challan'},{key:'challan_date',label:'Date'},{key:'customer',label:'Customer'},{key:'warehouse',label:'Warehouse'},{key:'status',label:'Status'},{key:'dispatch_reference',label:'Reference'}]" :rows="challans">
                <template #cell-customer="{ row }">{{ row.customer?.customer_name || '-' }}</template>
                <template #cell-warehouse="{ row }">{{ row.warehouse?.name || '-' }}</template>
                <template #actions="{ row }"><button v-if="row.status === 'draft'" @click="dispatchChallan(row)">Dispatch</button><button @click="window.print()">Print</button></template>
            </InventoryTable>
            <InventoryTable v-if="tab === 'reserved'" :columns="[{key:'order_number',label:'Order'},{key:'order_date',label:'Date'},{key:'customer',label:'Customer'},{key:'warehouse',label:'Warehouse'},{key:'reservation_status',label:'Reservation'},{key:'dispatch_status',label:'Dispatch'},{key:'grand_total',label:'Value'}]" :rows="salesOrders">
                <template #cell-customer="{ row }">{{ row.customer?.customer_name || '-' }}</template>
                <template #cell-warehouse="{ row }">{{ row.warehouse?.name || '-' }}</template>
                <template #actions="{ row }"><button @click="reserveOrder(row)">Reserve</button><button v-if="['reserved','partial'].includes(row.reservation_status)" @click="makeChallan(row)">Create Challan</button><button v-if="['reserved','partial'].includes(row.reservation_status)" @click="makeChallan(row, true)">Dispatch</button><button v-if="['reserved','partial'].includes(row.reservation_status)" @click="releaseOrder(row)">Release</button></template>
            </InventoryTable>
            <InventoryTable v-if="tab === 'ledger'" :columns="[{key:'transaction_date',label:'Date'},{key:'transaction_type',label:'Type'},{key:'product',label:'Product'},{key:'quantity_out',label:'Out'},{key:'warehouse',label:'Warehouse'},{key:'reference_number',label:'Reference'}]" :rows="ledger">
                <template #cell-product="{ row }">{{ row.product?.name || row.product_name || '-' }}</template>
                <template #cell-warehouse="{ row }">{{ row.warehouse?.name || row.warehouse_name || '-' }}</template>
            </InventoryTable>
        </InventoryModuleScaffold>
    </Layout>
</template>

<style scoped>
.tabs{display:flex;gap:8px;flex-wrap:wrap}.tabs button.active{background:#142139;color:#fff}input,select,button{border:1px solid #d8e0eb;border-radius:8px;font-size:12px;min-height:38px;padding:8px 10px}button{background:#fff;cursor:pointer;font-weight:800}
.notice{background:#eef6ff;border:1px solid #cfe3ff;border-radius:8px;color:#1d4f8f;font-size:12px;font-weight:800;margin-bottom:12px;padding:10px}
</style>
