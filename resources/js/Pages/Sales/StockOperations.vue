<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import Layout from '../Layout.vue';
import InventoryModuleScaffold from '../Inventory/Shared/InventoryModuleScaffold.vue';
import InventoryTable from '../Inventory/Shared/InventoryTable.vue';

const props = defineProps({
    page: String,
    title: String,
    initial_tab: { type: String, default: 'outward' },
    module: String,
    filters: { type: Object, default: () => ({}) },
    references: { type: Object, default: () => ({}) },
    summary: { type: Object, default: () => ({}) },
    rows: { type: Object, default: () => ({}) },
    permissions: { type: Object, default: () => ({}) },
    endpoints: { type: Object, default: () => ({}) },
    stock_rule: String,
});

const reservedMode = computed(() => props.page === 'inventory-reserved' || props.module === 'reserved-stock');
const stockTabs = ['outward', 'reserved', 'ledger'];
const reservationTabs = ['active', 'expiring', 'dispatched', 'released', 'ledger'];
const allowedTabs = computed(() => reservedMode.value ? reservationTabs : stockTabs);
const defaultTab = computed(() => reservedMode.value ? 'active' : props.initial_tab);
const activeTab = computed(() => allowedTabs.value.includes(props.filters?.tab) ? props.filters.tab : defaultTab.value);
const loading = ref(false);
const message = ref('');

const filterForm = reactive({
    tab: activeTab.value,
    search: props.filters?.search || '',
    per_page: props.filters?.per_page || 25,
    date_from: props.filters?.date_from || '',
    date_to: props.filters?.date_to || '',
    branch_id: props.filters?.branch_id || '',
    warehouse_id: props.filters?.warehouse_id || '',
    status: props.filters?.status || '',
    reference_type: props.filters?.reference_type || '',
    source_type: props.filters?.source_type || '',
    expiry_status: props.filters?.expiry_status || '',
    dispatch_status: props.filters?.dispatch_status || '',
    sort: props.filters?.sort || 'date',
    direction: props.filters?.direction || 'desc',
});

const currentRows = computed(() => props.rows?.[activeTab.value]?.data || []);
const pagination = computed(() => props.rows?.[activeTab.value] || { current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });
const filteredWarehouses = computed(() => (props.references?.warehouses || []).filter((w) => !filterForm.branch_id || Number(w.branch_id) === Number(filterForm.branch_id)));
const canExport = computed(() => Boolean(props.permissions?.export));
const money = (value) => new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' }).format(Number(value || 0));
const qty = (value) => Number(value || 0).toLocaleString('en-IN', { maximumFractionDigits: 3 });

const cards = computed(() => reservedMode.value ? [
    { label: 'Active Reservations', value: props.summary?.active_reservations || 0, tone: 'info', tab: 'active' },
    { label: 'Reserved Quantity', value: qty(props.summary?.reserved_quantity), tone: 'warn', tab: 'active' },
    { label: 'Reserved Value', value: money(props.summary?.reserved_value), tone: 'money', tab: 'active' },
    { label: 'Expiring Soon', value: props.summary?.expiring_soon || 0, tone: 'bad', tab: 'expiring' },
    { label: 'Pending Dispatch', value: props.summary?.pending_dispatch || 0, tone: 'good', tab: 'active', dispatch_status: 'pending' },
    { label: 'Unallocated', value: props.summary?.unallocated_reservations || 0, tone: 'warn', tab: 'active' },
] : [
    { label: 'Dispatch Docs', value: props.summary?.dispatch_docs || 0, tone: 'info', tab: 'outward', type: 'dispatch' },
    { label: 'Reserved Orders', value: props.summary?.reserved_orders || 0, tone: 'warn', tab: 'reserved' },
    { label: 'Outward Lines', value: props.summary?.outward_lines || 0, tone: 'money', tab: 'ledger', movement: 'out' },
    { label: 'Pending Dispatch', value: props.summary?.pending_dispatch || 0, tone: 'bad', tab: 'outward', status: 'pending' },
]);

let timer = null;
const params = (extra = {}) => Object.fromEntries(Object.entries({ ...filterForm, ...extra }).filter(([, value]) => value !== '' && value !== null && value !== undefined));
const navigate = (extra = {}, preserveScroll = true) => {
    loading.value = true;
    message.value = '';
    router.get(props.endpoints.index || (reservedMode.value ? '/app/sales/reserved-stock' : '/app/sales/stock-outward'), params(extra), {
        preserveState: true,
        preserveScroll,
        replace: false,
        onFinish: () => { loading.value = false; },
    });
};

const debounceNavigate = () => {
    clearTimeout(timer);
    timer = setTimeout(() => navigate({ page: 1 }), 350);
};

const switchTab = (tab, extra = {}) => {
    filterForm.tab = allowedTabs.value.includes(tab) ? tab : defaultTab.value;
    filterForm.status = extra.status || '';
    filterForm.expiry_status = extra.expiry_status || '';
    filterForm.dispatch_status = extra.dispatch_status || '';
    navigate({ ...extra, page: 1 });
};

const clearFilters = () => {
    const tab = activeTab.value;
    Object.assign(filterForm, { tab, search: '', per_page: props.filters?.per_page || 25, date_from: '', date_to: '', branch_id: '', warehouse_id: '', status: '', reference_type: '', source_type: '', expiry_status: '', dispatch_status: '', sort: 'date', direction: 'desc' });
    navigate({ page: 1 });
};

const refresh = () => navigate({ page: pagination.value.current_page || 1 });
const pageTo = (page) => navigate({ page });
const exportCsv = () => {
    if (!canExport.value) return;
    window.location.href = `${props.endpoints.export}?${new URLSearchParams(params()).toString()}`;
};
const printReport = () => {
    window.open(`${props.endpoints.print}?${new URLSearchParams(params()).toString()}`, '_blank', 'noopener');
};

const postAction = async (url, success, payload = {}) => {
    loading.value = true;
    message.value = '';
    try {
        await axios.post(url, payload);
        message.value = success;
        refresh();
    } catch (error) {
        message.value = error?.response?.data?.message || Object.values(error?.response?.data?.errors || {})[0]?.[0] || 'Action failed.';
    } finally {
        loading.value = false;
    }
};

const dispatchChallan = (row) => {
    if (!props.endpoints.dispatch || row.row_type !== 'challan') return;
    postAction(props.endpoints.dispatch.replace('__ID__', row.id), 'Stock outward posted successfully.');
};

const releaseReservation = (row) => {
    const url = props.endpoints.releaseReservation;
    if (!url) return;
    const reason = window.prompt('Reason for releasing reserved stock?', 'Released from Reserved Stock') || '';
    if (!reason.trim()) return;
    postAction(url.replace('__ID__', row.id), 'Reservation released successfully.', { reason });
};

const extendReservation = (row) => {
    const url = props.endpoints.extendReservation;
    if (!url) return;
    const expiry = window.prompt('New expiry date (YYYY-MM-DD)', row.expiry || new Date().toISOString().slice(0, 10)) || '';
    if (!expiry.trim()) return;
    postAction(url.replace('__ID__', row.id), 'Reservation extended successfully.', { expiry_date: expiry, reason: 'Extended from Reserved Stock' });
};

const dispatchReservation = (row) => {
    const url = props.endpoints.dispatchReservation;
    if (!url) return;
    postAction(url.replace('__ID__', row.id), 'Dispatch created and posted from reservation.');
};

watch(() => filterForm.search, debounceNavigate);
watch(() => [filterForm.per_page, filterForm.date_from, filterForm.date_to, filterForm.branch_id, filterForm.warehouse_id, filterForm.status, filterForm.reference_type, filterForm.source_type, filterForm.expiry_status, filterForm.dispatch_status, filterForm.sort, filterForm.direction], () => navigate({ page: 1 }));
</script>

<template>
    <Layout :page="page" :title="title">
        <template #topbar-title>
            <div class="bill-page-title">
                <span>SALES INVENTORY</span>
                <h1>{{ title }}</h1>
                <p>Sales dispatch and reserved stock from live order and ledger records.</p>
            </div>
        </template>

        <InventoryModuleScaffold :title="title" :cards="cards" :loading="loading" :initial-loaded="true" :pagination="pagination" @page="pageTo">
            <template #toolbar>
                <button :disabled="loading" @click="refresh">{{ loading ? 'Refreshing...' : 'Refresh' }}</button>
                <button :disabled="loading || !canExport" @click="exportCsv">Export CSV</button>
                <button :disabled="loading || !permissions.print" @click="printReport">Print</button>
            </template>

            <template #section-actions>
                <div class="tabs">
                    <button v-for="tab in allowedTabs" :key="tab" :class="{ active: activeTab === tab }" @click="switchTab(tab)">{{ tab }}</button>
                </div>
            </template>

            <template #filters>
                <label><span>Search</span><input v-model="filterForm.search" placeholder="Search document, customer or product" /></label>
                <label><span>From</span><input v-model="filterForm.date_from" type="date" /></label>
                <label><span>To</span><input v-model="filterForm.date_to" type="date" /></label>
                <label><span>Branch</span><select v-model="filterForm.branch_id"><option value="">All branches</option><option v-for="branch in references.branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option></select></label>
                <label><span>Warehouse</span><select v-model="filterForm.warehouse_id"><option value="">All warehouses</option><option v-for="warehouse in filteredWarehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option></select></label>
                <label><span>Status</span><select v-model="filterForm.status"><option value="">All statuses</option><option v-for="status in references.statuses" :key="status" :value="status">{{ status }}</option></select></label>
                <label v-if="reservedMode"><span>Source</span><select v-model="filterForm.source_type"><option value="">All sources</option><option v-for="type in references.source_types" :key="type" :value="type">{{ type }}</option></select></label>
                <label v-if="reservedMode"><span>Expiry</span><select v-model="filterForm.expiry_status"><option value="">All expiry</option><option v-for="status in references.expiry_statuses" :key="status" :value="status">{{ status }}</option></select></label>
                <label v-if="!reservedMode"><span>Reference</span><select v-model="filterForm.reference_type"><option value="">All references</option><option v-for="type in references.reference_types" :key="type" :value="type">{{ type }}</option></select></label>
                <label><span>Per page</span><select v-model.number="filterForm.per_page"><option v-for="size in references.per_page" :key="size" :value="size">{{ size }}</option></select></label>
                <button @click="clearFilters">Clear Filters</button>
            </template>

            <div class="card-clicks">
                <button v-for="card in cards" :key="card.label" @click="switchTab(card.tab, card)">{{ card.label }}</button>
            </div>

            <p v-if="message" class="notice">{{ message }}</p>
            <p class="rule">{{ stock_rule }}</p>

            <InventoryTable v-if="reservedMode && activeTab !== 'ledger'" :empty-text="activeTab === 'expiring' ? 'No reservations are expiring in the selected period.' : activeTab === 'dispatched' ? 'No dispatched reservations found.' : activeTab === 'released' ? 'No released or expired reservations found.' : 'No active stock reservations found.'" :columns="[
                { key: 'reservation_number', label: 'Reservation No.' },
                { key: 'reserved_date', label: 'Reserved Date' },
                { key: 'source_type', label: 'Source Type' },
                { key: 'source_number', label: 'Source No.' },
                { key: 'customer_name', label: 'Customer' },
                { key: 'branch_name', label: 'Branch' },
                { key: 'warehouse_name', label: 'Warehouse' },
                { key: 'product_lines', label: 'Lines' },
                { key: 'reserved_quantity', label: 'Reserved' },
                { key: 'dispatched_quantity', label: 'Dispatched' },
                { key: 'released_quantity', label: 'Released' },
                { key: 'remaining_quantity', label: 'Remaining' },
                { key: 'reserved_value', label: 'Reserved Value' },
                { key: 'expiry', label: 'Expiry' },
                { key: 'status', label: 'Status' },
            ]" :rows="currentRows">
                <template #cell-reserved_quantity="{ value }">{{ qty(value) }}</template>
                <template #cell-dispatched_quantity="{ value }">{{ qty(value) }}</template>
                <template #cell-released_quantity="{ value }">{{ qty(value) }}</template>
                <template #cell-remaining_quantity="{ value }">{{ qty(value) }}</template>
                <template #cell-reserved_value="{ value }">{{ permissions.view_value ? money(value) : 'Restricted' }}</template>
                <template #cell-status="{ value }"><span class="status" :class="value">{{ value }}</span></template>
                <template #actions="{ row }">
                    <div class="row-actions">
                        <button @click="switchTab('ledger', { search: row.reservation_number })">History</button>
                        <button v-if="permissions.extend && !['released','fully_dispatched'].includes(row.status)" @click="extendReservation(row)">Extend</button>
                        <button v-if="permissions.release && !['released','fully_dispatched'].includes(row.status)" @click="releaseReservation(row)">Release</button>
                        <button v-if="permissions.dispatch && Number(row.remaining_quantity || 0) > 0" @click="dispatchReservation(row)">Dispatch</button>
                        <button @click="printReport">Print</button>
                    </div>
                </template>
            </InventoryTable>

            <InventoryTable v-if="reservedMode && activeTab === 'ledger'" :empty-text="'No reservation history found.'" :columns="[
                { key: 'date', label: 'Date' },
                { key: 'reservation_number', label: 'Reservation' },
                { key: 'product_name', label: 'Product' },
                { key: 'variant_name', label: 'Variant' },
                { key: 'batch_number', label: 'Batch' },
                { key: 'warehouse_name', label: 'Warehouse' },
                { key: 'action', label: 'Action' },
                { key: 'quantity', label: 'Quantity' },
                { key: 'previous_remaining', label: 'Previous' },
                { key: 'new_remaining', label: 'New Remaining' },
                { key: 'reference', label: 'Reference' },
                { key: 'performed_by', label: 'Performed By' },
            ]" :rows="currentRows">
                <template #cell-quantity="{ value }">{{ qty(value) }}</template>
                <template #cell-new_remaining="{ value }">{{ qty(value) }}</template>
            </InventoryTable>

            <InventoryTable v-if="!reservedMode && activeTab === 'outward'" :empty-text="'No stock-outward documents found for the selected filters.'" :columns="[
                { key: 'number', label: 'Outward / Challan' },
                { key: 'date', label: 'Date' },
                { key: 'reference_type', label: 'Reference Type' },
                { key: 'reference_number', label: 'Reference No.' },
                { key: 'customer_name', label: 'Customer' },
                { key: 'branch_name', label: 'Branch' },
                { key: 'warehouse_name', label: 'Warehouse' },
                { key: 'total_lines', label: 'Lines' },
                { key: 'total_quantity', label: 'Qty' },
                { key: 'dispatch_status', label: 'Dispatch' },
                { key: 'stock_status', label: 'Stock' },
                { key: 'created_by_name', label: 'Created By' },
            ]" :rows="currentRows">
                <template #cell-dispatch_status="{ value }"><span class="status" :class="value">{{ value }}</span></template>
                <template #actions="{ row }"><div class="row-actions"><button @click="switchTab('ledger', { search: row.number })">Ledger</button><button @click="printReport">Print</button><button v-if="permissions.dispatch && row.row_type === 'challan' && row.dispatch_status === 'draft'" @click="dispatchChallan(row)">Dispatch</button></div></template>
            </InventoryTable>

            <InventoryTable v-if="!reservedMode && activeTab === 'reserved'" :empty-text="'No active reserved-stock records found.'" :columns="[
                { key: 'reservation_number', label: 'Reservation No.' },
                { key: 'reserved_date', label: 'Reserved Date' },
                { key: 'source_type', label: 'Source Type' },
                { key: 'source_number', label: 'Source No.' },
                { key: 'customer_name', label: 'Customer' },
                { key: 'branch_name', label: 'Branch' },
                { key: 'warehouse_name', label: 'Warehouse' },
                { key: 'product_lines', label: 'Lines' },
                { key: 'reserved_quantity', label: 'Reserved' },
                { key: 'dispatched_quantity', label: 'Dispatched' },
                { key: 'remaining_quantity', label: 'Remaining' },
                { key: 'expiry', label: 'Expiry' },
                { key: 'status', label: 'Status' },
            ]" :rows="currentRows">
                <template #cell-status="{ value }"><span class="status" :class="value">{{ value }}</span></template>
                <template #actions="{ row }"><div class="row-actions"><button @click="switchTab('outward', { search: row.source_number })">Source</button><button v-if="permissions.release && row.status !== 'fully_dispatched'" @click="releaseReservation(row)">Release</button></div></template>
            </InventoryTable>

            <InventoryTable v-if="!reservedMode && activeTab === 'ledger'" :empty-text="'No stock-outward ledger entries found.'" :columns="[
                { key: 'date', label: 'Date' },
                { key: 'product_name', label: 'Product' },
                { key: 'sku', label: 'SKU' },
                { key: 'variant_name', label: 'Variant' },
                { key: 'batch_number', label: 'Batch' },
                { key: 'branch_name', label: 'Branch' },
                { key: 'warehouse_name', label: 'Warehouse' },
                { key: 'quantity_out', label: 'Qty Out' },
                { key: 'unit_cost', label: 'Unit Cost' },
                { key: 'value', label: 'Value' },
                { key: 'transaction_type', label: 'Type' },
                { key: 'reference_number', label: 'Reference' },
                { key: 'created_by_name', label: 'Created By' },
            ]" :rows="currentRows">
                <template #cell-unit_cost="{ value }">{{ permissions.view_cost ? value : 'Restricted' }}</template>
                <template #cell-value="{ value }">{{ permissions.view_cost ? value : 'Restricted' }}</template>
            </InventoryTable>

            <p v-if="!currentRows.length" class="empty-hint">{{ reservedMode ? 'No stock is currently available to reserve for the selected filters.' : 'No warehouse or dispatchable stock may be available for the selected filters.' }}</p>
        </InventoryModuleScaffold>
    </Layout>
</template>

<style scoped>
.tabs,.row-actions,.card-clicks{display:flex;gap:8px;flex-wrap:wrap}.tabs button.active{background:#142139;color:#fff}label{display:flex;flex-direction:column;gap:4px}label span{color:#69758a;font-size:10px;font-weight:900;text-transform:uppercase}input,select,button{border:1px solid #d8e0eb;border-radius:8px;font-size:12px;min-height:38px;padding:8px 10px}button{background:#fff;cursor:pointer;font-weight:800}button:disabled{cursor:not-allowed;opacity:.55}.notice{background:#eef6ff;border:1px solid #cfe3ff;border-radius:8px;color:#1d4f8f;font-size:12px;font-weight:800;margin-bottom:12px;padding:10px}.rule{background:#f8fafc;border:1px solid #e5eaf2;border-radius:8px;color:#536174;font-size:12px;font-weight:700;margin:0 0 12px;padding:10px}.status{background:#edf2ff;border-radius:7px;color:#2457d6;display:inline-flex;font-size:10px;font-weight:900;padding:5px 8px;text-transform:capitalize}.status.draft,.status.pending,.status.active,.status.partially_dispatched{background:#fff7ed;color:#c2410c}.status.dispatched,.status.delivered,.status.fully_dispatched{background:#ecfdf3;color:#027a48}.status.released,.status.cancelled,.status.expired{background:#fff1f2;color:#be123c}.empty-hint{color:#7b8798;font-size:12px;font-weight:700;margin-top:12px}.card-clicks{margin-bottom:12px}@media(max-width:760px){.card-clicks button,.tabs button{flex:1 1 auto}.row-actions{flex-direction:column}.row-actions button{width:100%}}
</style>
