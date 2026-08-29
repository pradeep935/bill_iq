<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import Layout from '../Layout.vue';
import ActionToolbar from '../../Components/Dispatch/ActionToolbar.vue';
import DispatchDrawer from '../../Components/Dispatch/DispatchDrawer.vue';
import DispatchTable from '../../Components/Dispatch/DispatchTable.vue';
import FilterPanel from '../../Components/Dispatch/FilterPanel.vue';
import StatusBadge from '../../Components/Dispatch/StatusBadge.vue';
import SummaryCards from '../../Components/Dispatch/SummaryCards.vue';
import RowActionMenu from '../../Components/Common/RowActionMenu.vue';

const props = defineProps({
  page: String,
  title: String,
  initial_tab: { type: String, default: 'sale_dispatch' },
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
const tabs = computed(() => props.references?.tabs || (reservedMode.value ? ['active', 'expiring', 'consumed', 'released', 'ledger'] : ['sale_dispatch', 'manual_outward', 'ledger']));
const activeTab = computed(() => tabs.value.includes(filterForm.tab) ? filterForm.tab : (reservedMode.value ? 'active' : 'sale_dispatch'));
const loading = ref(false);
const message = ref('');
const selectedRow = ref(null);
const drawerOpen = ref(false);
const drawerLoading = ref(false);
const drawerDispatch = ref(null);
const barcodeScan = ref('');
const openActionMenuId = ref(null);
let timer = null;

const filterForm = reactive({
  tab: props.filters?.tab || props.initial_tab || 'sale_dispatch',
  search: props.filters?.search || '',
  per_page: props.filters?.per_page || 25,
  date_from: props.filters?.date_from || '',
  date_to: props.filters?.date_to || '',
  branch_id: props.filters?.branch_id || '',
  warehouse_id: props.filters?.warehouse_id || '',
  customer_id: props.filters?.customer_id || '',
  sales_invoice: props.filters?.sales_invoice || '',
  order_number: props.filters?.order_number || '',
  status: props.filters?.status || '',
  expiry_status: props.filters?.expiry_status || '',
  source_type: props.filters?.source_type || '',
  delivery_status: props.filters?.delivery_status || '',
  transporter: props.filters?.transporter || '',
  vehicle_number: props.filters?.vehicle_number || '',
  reference_type: props.filters?.reference_type || '',
  sort: props.filters?.sort || 'date',
  direction: props.filters?.direction || 'desc',
});

const currentRows = computed(() => props.rows?.[activeTab.value]?.data || []);
const pagination = computed(() => props.rows?.[activeTab.value] || { current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });
const filteredWarehouses = computed(() => (props.references?.warehouses || []).filter((warehouse) => !filterForm.branch_id || Number(warehouse.branch_id) === Number(filterForm.branch_id)));
const qty = (value) => Number(value || 0).toLocaleString('en-IN', { maximumFractionDigits: 3 });
const money = (value) => Number(value || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 });
const urlWithId = (template, id) => template?.replace('__ID__', id);
const tabLabel = (tab) => ({ sale_dispatch: 'Sale Dispatch', manual_outward: 'Manual Outward', active: 'Active', expiring: 'Expiring', consumed: 'Consumed', released: 'Released', ledger: 'History' }[tab] || tab.replaceAll('_', ' '));
const rowActionId = (row) => `${row.row_type || activeTab.value}-${row.id}`;
const toggleActionMenu = (row) => {
  const id = rowActionId(row);
  openActionMenuId.value = openActionMenuId.value === id ? null : id;
};
const closeActionMenu = () => {
  openActionMenuId.value = null;
};

const summaryCards = computed(() => reservedMode.value ? [
  { label: 'Active Reservations', value: props.summary?.active_reservations || 0, tab: 'active' },
  { label: 'Reserved Qty', value: qty(props.summary?.reserved_quantity || 0), tab: 'active' },
  { label: 'Reserved Value', value: `Rs. ${money(props.summary?.reserved_value || 0)}`, tab: 'active' },
  { label: 'Expiring Soon', value: props.summary?.expiring_soon || 0, tab: 'expiring' },
  { label: 'Consumed', value: props.summary?.consumed_reservations || 0, tab: 'consumed' },
  { label: 'Released', value: props.summary?.released_reservations || 0, tab: 'released' },
] : [
  { label: 'Sale-Linked Dispatch', value: props.summary?.sale_linked_dispatch || 0, tab: 'sale_dispatch', status: '' },
  { label: 'Manual Outward', value: props.summary?.manual_outward || 0, tab: 'manual_outward', status: '' },
  { label: 'Ready / Draft', value: props.summary?.ready_for_dispatch || 0, tab: 'manual_outward', status: 'ready' },
  { label: 'Pending Pick/Pack', value: props.summary?.pending_dispatch || 0, tab: 'manual_outward', status: 'pending' },
  { label: 'Outward Posted', value: props.summary?.stock_posted_manual || 0, tab: 'manual_outward', status: 'dispatched' },
  { label: 'Delivered', value: props.summary?.completed_dispatch || 0, tab: 'manual_outward', status: 'delivered' },
]);

const dispatchColumns = [
  { key: 'source_type', label: 'Type' },
  { key: 'number', label: 'Document No' },
  { key: 'reference_number', label: 'Reference' },
  { key: 'customer_name', label: 'Customer' },
  { key: 'branch_name', label: 'Branch' },
  { key: 'warehouse_name', label: 'Warehouse' },
  { key: 'total_lines', label: 'Total Items' },
  { key: 'total_quantity', label: 'Total Quantity' },
  { key: 'dispatch_status', label: 'Dispatch Status' },
  { key: 'stock_status', label: 'Stock Status' },
  { key: 'stock_policy', label: 'Inventory Rule' },
  { key: 'date', label: 'Dispatch Date' },
  { key: 'created_by_name', label: 'Created By' },
];
const reservedColumns = [
  { key: 'reservation_number', label: 'Reservation No' },
  { key: 'source_number', label: 'Order Number' },
  { key: 'customer_name', label: 'Customer' },
  { key: 'branch_name', label: 'Branch' },
  { key: 'warehouse_name', label: 'Warehouse' },
  { key: 'product_lines', label: 'Total Items' },
  { key: 'reserved_quantity', label: 'Reserved Qty' },
  { key: 'consumed_quantity', label: 'Consumed Qty' },
  { key: 'released_quantity', label: 'Released Qty' },
  { key: 'remaining_quantity', label: 'ATS Held Qty' },
  { key: 'reserved_value', label: 'Reserved Value' },
  { key: 'expiry', label: 'Expiry' },
  { key: 'stock_effect', label: 'Stock Effect' },
  { key: 'status', label: 'Status' },
];
const ledgerColumns = [
  { key: 'date', label: 'Date' },
  { key: 'product_name', label: 'Product' },
  { key: 'sku', label: 'SKU' },
  { key: 'batch_number', label: 'Batch' },
  { key: 'branch_name', label: 'Branch' },
  { key: 'warehouse_name', label: 'Warehouse' },
  { key: 'quantity_out', label: 'Qty Out' },
  { key: 'transaction_type', label: 'Movement' },
  { key: 'reference_number', label: 'Reference' },
  { key: 'created_by_name', label: 'Created By' },
];
const reservationLedgerColumns = [
  { key: 'date', label: 'Date' },
  { key: 'reservation_number', label: 'Reservation No' },
  { key: 'product_name', label: 'Product' },
  { key: 'batch_number', label: 'Batch' },
  { key: 'warehouse_name', label: 'Warehouse' },
  { key: 'action', label: 'Action' },
  { key: 'quantity', label: 'Quantity' },
  { key: 'new_remaining', label: 'Remaining Qty' },
  { key: 'reference', label: 'Reference' },
  { key: 'performed_by', label: 'User' },
];
const tableColumns = computed(() => activeTab.value === 'ledger' ? (reservedMode.value ? reservationLedgerColumns : ledgerColumns) : (reservedMode.value || activeTab.value === 'reserved' ? reservedColumns : dispatchColumns));

const params = (extra = {}) => Object.fromEntries(Object.entries({ ...filterForm, ...extra }).filter(([, value]) => value !== '' && value !== null && value !== undefined));
const navigate = (extra = {}, preserveScroll = true) => {
  loading.value = true;
  router.get(props.endpoints.index || (reservedMode.value ? '/app/sales/reserved-stock' : '/app/sales/stock-outward'), params(extra), {
    preserveState: true,
    preserveScroll,
    replace: false,
    onFinish: () => { loading.value = false; },
  });
};
const debounceNavigate = () => {
  clearTimeout(timer);
  timer = setTimeout(() => navigate({ page: 1 }), 320);
};
const switchTab = (tab, extra = {}) => {
  filterForm.tab = tabs.value.includes(tab) ? tab : tabs.value[0];
  filterForm.status = extra.status || '';
  navigate({ ...extra, page: 1 });
};
const clearFilters = () => {
  const tab = activeTab.value;
  Object.assign(filterForm, { tab, search: '', per_page: props.filters?.per_page || 25, date_from: '', date_to: '', branch_id: '', warehouse_id: '', customer_id: '', sales_invoice: '', order_number: '', status: '', expiry_status: '', source_type: '', delivery_status: '', transporter: '', vehicle_number: '', reference_type: '', sort: 'date', direction: 'desc' });
  navigate({ page: 1 });
};
const refresh = () => navigate({ page: pagination.value.current_page || 1 });
const pageTo = (page) => navigate({ page });
const exportCsv = () => { if (props.permissions?.export) window.location.href = `${props.endpoints.export}?${new URLSearchParams(params()).toString()}`; };
const printReport = () => window.open(`${props.endpoints.print}?${new URLSearchParams(params()).toString()}`, '_blank', 'noopener');
const printDocument = (type) => {
  const search = selectedRow.value?.number || selectedRow.value?.reservation_number || filterForm.search;
  window.open(`${props.endpoints.print}?${new URLSearchParams(params({ search, document: type })).toString()}`, '_blank', 'noopener');
};
const postWorkflow = async (row, action, success) => {
  if (!row || row.row_type === 'invoice') { message.value = 'Sale-linked dispatch is physical tracking only. The invoice already posted stock, so no Stock Outward deduction is available.'; return; }
  const url = urlWithId(props.endpoints.workflow || props.endpoints.dispatch, row.id);
  if (!url) return;
  loading.value = true;
  try {
    await axios.post(url, { action });
    message.value = success;
    refresh();
  } catch (error) {
    message.value = error?.response?.data?.message || Object.values(error?.response?.data?.errors || {})[0]?.[0] || 'Action failed.';
  } finally {
    loading.value = false;
  }
};
const releaseReservation = async (row) => {
  const url = urlWithId(props.endpoints.releaseReservation, row.id);
  if (!url) return;
  const reason = window.prompt('Reason for releasing reserved stock?', 'Released from dispatch queue') || '';
  if (!reason.trim()) return;
  loading.value = true;
  try {
    await axios.post(url, { reason });
    message.value = 'Reservation released successfully.';
    refresh();
  } catch (error) {
    message.value = error?.response?.data?.message || 'Unable to release reservation.';
  } finally {
    loading.value = false;
  }
};
const extendReservation = async (row) => {
  const url = urlWithId(props.endpoints.extendReservation, row.id);
  if (!url) return;
  const expiryDate = window.prompt('Extend reservation until date (YYYY-MM-DD)', row.expiry || '');
  if (!expiryDate?.trim()) return;
  const reason = window.prompt('Reason for extending reservation?', 'Customer hold extended') || '';
  loading.value = true;
  try {
    await axios.post(url, { expiry_date: expiryDate.trim(), reason });
    message.value = 'Reservation expiry extended.';
    refresh();
  } catch (error) {
    message.value = error?.response?.data?.message || Object.values(error?.response?.data?.errors || {})[0]?.[0] || 'Unable to extend reservation.';
  } finally {
    loading.value = false;
  }
};
const openDrawer = async (row) => {
  selectedRow.value = row;
  if (reservedMode.value || activeTab.value === 'ledger') return;
  drawerOpen.value = true;
  drawerLoading.value = true;
  drawerDispatch.value = null;
  try {
    const response = await axios.get(urlWithId(props.endpoints.detail, row.id), { params: { row_type: row.row_type } });
    drawerDispatch.value = response.data.dispatch;
  } catch (error) {
    message.value = error?.response?.data?.message || 'Unable to load dispatch details.';
    drawerOpen.value = false;
  } finally {
    drawerLoading.value = false;
  }
};
const createOutward = () => { message.value = 'Manual outward is created from sales order or delivery challan workflows. Posted sales invoices stay in Sale Dispatch and are never deducted again here.'; };
const createReservation = () => { message.value = 'Reservations are created by approving sales orders, so available-to-sell is held without changing physical inventory.'; };
const scanBarcode = () => {
  if (!barcodeScan.value.trim()) return;
  message.value = `Barcode ${barcodeScan.value.trim()} captured for warehouse picking.`;
  barcodeScan.value = '';
};

watch(() => filterForm.search, debounceNavigate);
watch(() => [filterForm.per_page, filterForm.date_from, filterForm.date_to, filterForm.branch_id, filterForm.warehouse_id, filterForm.customer_id, filterForm.sales_invoice, filterForm.order_number, filterForm.status, filterForm.expiry_status, filterForm.source_type, filterForm.delivery_status, filterForm.transporter, filterForm.vehicle_number, filterForm.reference_type, filterForm.sort, filterForm.direction], () => navigate({ page: 1 }));
</script>

<template>
  <Layout :page="page" :title="title">
    <template #topbar-title>
      <div class="bill-page-title">
        <span>{{ reservedMode ? 'STOCK ALLOCATION' : 'WAREHOUSE DISPATCH' }}</span>
        <h1>{{ title }}</h1>
        <p>{{ reservedMode ? 'Allocate stock to orders and customers while keeping physical inventory unchanged until a ledger transaction is posted.' : 'Physical stock movement tracking for invoice dispatch and manual outward without duplicate inventory deduction.' }}</p>
      </div>
    </template>

    <div class="dispatch-page">
      <ActionToolbar>
        <button v-if="reservedMode" type="button" title="Create reservations through sales order approval" @click="createReservation">New Reservation</button>
        <button v-else type="button" title="Create manual outward through order or challan workflows" @click="createOutward">Manual Outward</button>
        <button type="button" :title="reservedMode ? 'Print reserved stock report' : 'Print selected dispatch report'" @click="printDocument(reservedMode ? 'reservation' : 'dispatch')">Print</button>
        <button type="button" :title="reservedMode ? 'Export reservation data' : 'Export dispatch data'" :disabled="!permissions.export" @click="exportCsv">Export</button>
        <button type="button" :title="reservedMode ? 'Refresh reservations' : 'Refresh dispatch queue'" :disabled="loading" @click="refresh">{{ loading ? 'Refreshing...' : 'Refresh' }}</button>
      </ActionToolbar>

      <SummaryCards :cards="summaryCards" @select="(card) => switchTab(card.tab, card)" />

      <FilterPanel>
        <template #actions><button type="button" title="Clear all filters" @click="clearFilters">Clear Filters</button></template>
        <label><span>Date From</span><input v-model="filterForm.date_from" type="date" /></label>
        <label><span>Date To</span><input v-model="filterForm.date_to" type="date" /></label>
        <label><span>Branch</span><select v-model="filterForm.branch_id"><option value="">All branches</option><option v-for="branch in references.branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option></select></label>
        <label><span>Warehouse</span><select v-model="filterForm.warehouse_id"><option value="">All warehouses</option><option v-for="warehouse in filteredWarehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option></select></label>
        <label><span>Customer</span><select v-model="filterForm.customer_id"><option value="">All customers</option><option v-for="customer in references.customers || []" :key="customer.id" :value="customer.id">{{ customer.customer_name }}</option></select></label>
        <label v-if="!reservedMode"><span>Sales Invoice</span><input v-model="filterForm.sales_invoice" placeholder="Invoice number" /></label>
        <label v-if="!reservedMode"><span>Order Number</span><input v-model="filterForm.order_number" placeholder="Order number" /></label>
        <label><span>{{ reservedMode ? 'Reservation Status' : 'Dispatch Status' }}</span><select v-model="filterForm.status"><option value="">All statuses</option><option v-for="status in references.statuses || []" :key="status" :value="status">{{ status }}</option></select></label>
        <label v-if="reservedMode"><span>Expiry</span><select v-model="filterForm.expiry_status"><option value="">All expiry</option><option v-for="status in references.expiry_statuses || []" :key="status" :value="status">{{ status }}</option></select></label>
        <label v-if="reservedMode"><span>Source</span><select v-model="filterForm.source_type"><option value="">All sources</option><option v-for="source in references.source_types || []" :key="source" :value="source">{{ source }}</option></select></label>
        <label v-if="!reservedMode"><span>Transporter</span><input v-model="filterForm.transporter" list="transporters" placeholder="Transporter" /><datalist id="transporters"><option v-for="name in references.transporters || []" :key="name" :value="name" /></datalist></label>
        <label v-if="!reservedMode"><span>Vehicle Number</span><input v-model="filterForm.vehicle_number" placeholder="Vehicle no" /></label>
        <label><span>Global Search</span><input v-model="filterForm.search" :placeholder="reservedMode ? 'Order, customer, product, batch' : 'Document no, customer, mobile'" /></label>
      </FilterPanel>

      <section class="bill-ui-card">
        <div class="bill-ui-card-head">
          <div><span>{{ reservedMode ? 'ALLOCATIONS' : 'QUEUE' }}</span><h2>{{ reservedMode ? 'Reservation Register' : 'Dispatch Queue' }}</h2></div>
          <div class="dispatch-tabs">
            <button v-for="tab in tabs" :key="tab" type="button" :class="{ active: activeTab === tab }" :title="`Show ${tabLabel(tab)}`" @click="switchTab(tab)">{{ tabLabel(tab) }}</button>
          </div>
        </div>
        <p v-if="message" class="dispatch-message">{{ message }}</p>
        <p class="dispatch-rule">{{ stock_rule }}</p>
        <div v-if="!reservedMode" class="barcode-row">
          <input v-model="barcodeScan" placeholder="Scan barcode for picking" title="Barcode scanning for warehouse picking" @keyup.enter="scanBarcode" />
          <button type="button" title="Capture scanned barcode" @click="scanBarcode">Scan</button>
        </div>
        <DispatchTable :columns="tableColumns" :rows="currentRows" :empty-text="reservedMode ? 'No reserved stock records found for the selected filters.' : 'No dispatch records found for the selected filters.'" @open="openDrawer">
          <template #cell-total_quantity="{ value }">{{ qty(value) }}</template>
          <template #cell-reserved_quantity="{ value }">{{ qty(value) }}</template>
          <template #cell-consumed_quantity="{ value }">{{ qty(value) }}</template>
          <template #cell-released_quantity="{ value }">{{ qty(value) }}</template>
          <template #cell-remaining_quantity="{ value }">{{ qty(value) }}</template>
          <template #cell-quantity="{ value }">{{ qty(value) }}</template>
          <template #cell-new_remaining="{ value }">{{ qty(value) }}</template>
          <template #cell-reserved_value="{ value }">Rs. {{ money(value) }}</template>
          <template #cell-quantity_out="{ value }">{{ qty(value) }}</template>
          <template #cell-dispatch_status="{ value }"><StatusBadge :status="value" /></template>
          <template #cell-stock_status="{ value }"><StatusBadge :status="value" /></template>
          <template #cell-status="{ value }"><StatusBadge :status="value" /></template>
          <template #actions="{ row }">
            <div class="row-actions">
              <RowActionMenu :open="openActionMenuId === rowActionId(row)" :show-view="false" more-label="Actions" more-title="Stock operation actions" @toggle="toggleActionMenu(row)" @close="closeActionMenu">
                <button v-if="!reservedMode" type="button" title="Open dispatch details" @click="openDrawer(row); closeActionMenu()">Details</button>
                <button v-if="reservedMode && ['active','expiring'].includes(activeTab)" type="button" title="Extend reservation expiry" @click="extendReservation(row); closeActionMenu()">Extend</button>
                <button v-if="reservedMode && ['active','expiring'].includes(activeTab)" type="button" title="Release reservation" @click="releaseReservation(row); closeActionMenu()">Release</button>
                <button v-if="!reservedMode && row.row_type === 'challan' && ['draft','ready','ready_to_pick','pending'].includes(row.dispatch_status)" type="button" title="Start picking" @click="postWorkflow(row, 'pick', 'Picking started.'); closeActionMenu()">Pick</button>
                <button v-if="!reservedMode && row.row_type === 'challan' && ['picking'].includes(row.dispatch_status)" type="button" title="Mark packed" @click="postWorkflow(row, 'pack', 'Packing completed.'); closeActionMenu()">Pack</button>
                <button v-if="!reservedMode && row.row_type === 'challan' && row.dispatch_status === 'packed'" type="button" title="Post manual stock outward" @click="postWorkflow(row, 'dispatch', 'Manual stock outward posted.'); closeActionMenu()">Post Outward</button>
                <button v-if="!reservedMode && row.row_type === 'challan' && row.dispatch_status === 'dispatched'" type="button" title="Mark delivered" @click="postWorkflow(row, 'deliver', 'Delivery completed.'); closeActionMenu()">Deliver</button>
                <button v-if="!reservedMode && row.row_type === 'challan' && !['dispatched','delivered','cancelled'].includes(row.dispatch_status)" type="button" class="danger" title="Cancel dispatch" @click="postWorkflow(row, 'cancel', 'Dispatch cancelled.'); closeActionMenu()">Cancel</button>
              </RowActionMenu>
            </div>
          </template>
        </DispatchTable>
        <div v-if="(pagination.total || 0) > 0 && (pagination.last_page || 1) > 1" class="dispatch-pagination"><button :disabled="pagination.current_page <= 1" @click="pageTo(pagination.current_page - 1)">Previous</button><span>{{ pagination.from || 0 }}-{{ pagination.to || 0 }} of {{ pagination.total || 0 }}</span><button :disabled="pagination.current_page >= pagination.last_page" @click="pageTo(pagination.current_page + 1)">Next</button></div>
      </section>
      <DispatchDrawer :open="drawerOpen" :loading="drawerLoading" :dispatch="drawerDispatch" @close="drawerOpen = false" />
    </div>
  </Layout>
</template>

<style scoped>
.dispatch-page{padding:4px 0 28px}.dispatch-tabs,.row-actions,.barcode-row,.dispatch-pagination{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.dispatch-tabs button,.row-actions button,.barcode-row button,.dispatch-pagination button,.outward-history button{min-height:34px;padding:7px 10px;border:1px solid #d8e0eb;border-radius:8px;background:#fff;color:#344159;font-size:12px;font-weight:850;cursor:pointer;text-transform:capitalize}.dispatch-tabs button.active{color:#fff;background:#142139;border-color:#142139}.row-actions .danger{color:#d23f49;background:#fff3f4;border-color:#ffd6da}.dispatch-message{margin:0 0 10px;padding:10px;border:1px solid #cfe3ff;border-radius:8px;background:#eef6ff;color:#1d4f8f;font-size:12px;font-weight:850}.dispatch-rule{margin:0 0 12px;padding:10px;border:1px solid #e5eaf2;border-radius:8px;background:#f8fafc;color:#536174;font-size:12px;font-weight:750}.barcode-row{margin-bottom:12px}.barcode-row input{min-height:38px;min-width:280px;padding:8px 10px;border:1px solid #d8e0eb;border-radius:8px;font-size:12px}.dispatch-pagination{justify-content:flex-end;margin-top:12px}.outward-history{margin-top:14px}@media(max-width:720px){.barcode-row input{min-width:0;width:100%}.row-actions{flex-direction:column;align-items:stretch}.row-actions button{width:100%}}
</style>
