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

const props = defineProps({
  page: String,
  title: String,
  initial_tab: { type: String, default: 'ready' },
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
const tabs = computed(() => reservedMode.value ? ['reserved', 'dispatched', 'ledger'] : ['ready', 'reserved', 'picking', 'packing', 'dispatched', 'delivered', 'ledger']);
const activeTab = computed(() => tabs.value.includes(filterForm.tab) ? filterForm.tab : (reservedMode.value ? 'reserved' : 'ready'));
const loading = ref(false);
const message = ref('');
const selectedRow = ref(null);
const drawerOpen = ref(false);
const drawerLoading = ref(false);
const drawerDispatch = ref(null);
const barcodeScan = ref('');
let timer = null;

const filterForm = reactive({
  tab: props.filters?.tab || props.initial_tab || 'ready',
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
const urlWithId = (template, id) => template?.replace('__ID__', id);

const summaryCards = computed(() => reservedMode.value ? [
  { label: 'Reserved Orders', value: props.summary?.reserved_orders || 0, tab: 'reserved' },
  { label: 'Pending Dispatch', value: props.summary?.pending_dispatch || 0, tab: 'reserved' },
  { label: 'Completed Dispatch', value: props.summary?.completed_dispatch || 0, tab: 'dispatched' },
] : [
  { label: 'Total Dispatch Documents', value: props.summary?.total_dispatch_documents || 0, tab: 'ready', status: '' },
  { label: 'Reserved Orders', value: props.summary?.reserved_orders || 0, tab: 'reserved' },
  { label: 'Ready for Dispatch', value: props.summary?.ready_for_dispatch || 0, tab: 'ready', status: 'ready' },
  { label: 'Pending Dispatch', value: props.summary?.pending_dispatch || 0, tab: 'ready', status: 'pending' },
  { label: 'Completed Dispatch', value: props.summary?.completed_dispatch || 0, tab: 'delivered', status: 'delivered' },
  { label: 'Cancelled Dispatch', value: props.summary?.cancelled_dispatch || 0, tab: 'ready', status: 'cancelled' },
]);

const dispatchColumns = [
  { key: 'challan_number', label: 'Challan Number' },
  { key: 'sales_invoice', label: 'Sales Invoice' },
  { key: 'order_number', label: 'Order Number' },
  { key: 'customer_name', label: 'Customer' },
  { key: 'branch_name', label: 'Branch' },
  { key: 'warehouse_name', label: 'Warehouse' },
  { key: 'total_lines', label: 'Total Items' },
  { key: 'total_quantity', label: 'Total Quantity' },
  { key: 'dispatch_status', label: 'Dispatch Status' },
  { key: 'delivery_status', label: 'Delivery Status' },
  { key: 'transporter', label: 'Transporter' },
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
  { key: 'dispatched_quantity', label: 'Dispatched Qty' },
  { key: 'remaining_quantity', label: 'Backorder Qty' },
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
const tableColumns = computed(() => activeTab.value === 'ledger' ? ledgerColumns : (activeTab.value === 'reserved' ? reservedColumns : dispatchColumns));

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
  Object.assign(filterForm, { tab, search: '', per_page: props.filters?.per_page || 25, date_from: '', date_to: '', branch_id: '', warehouse_id: '', customer_id: '', sales_invoice: '', order_number: '', status: '', delivery_status: '', transporter: '', vehicle_number: '', reference_type: '', sort: 'date', direction: 'desc' });
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
  if (!row || row.row_type === 'invoice') { message.value = 'Sales invoice dispatch is already posted. No duplicate stock deduction is allowed here.'; return; }
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
const dispatchReservation = async (row) => {
  const url = urlWithId(props.endpoints.dispatchReservation, row.id);
  if (!url) return;
  loading.value = true;
  try {
    await axios.post(url);
    message.value = 'Dispatch created from reservation.';
    refresh();
  } catch (error) {
    message.value = error?.response?.data?.message || 'Unable to dispatch reservation.';
  } finally {
    loading.value = false;
  }
};
const openDrawer = async (row) => {
  selectedRow.value = row;
  if (activeTab.value === 'ledger' || activeTab.value === 'reserved') return;
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
const createOutward = () => { window.location.href = '/app/sales/orders'; };
const generateChallan = () => selectedRow.value?.reservation_number ? dispatchReservation(selectedRow.value) : (message.value = 'Select a reserved order to generate a challan.');
const generateEwayBill = () => { message.value = 'E-Way Bill number can be recorded against the challan once the government integration is connected.'; };
const scanBarcode = () => {
  if (!barcodeScan.value.trim()) return;
  message.value = `Barcode ${barcodeScan.value.trim()} captured for warehouse picking.`;
  barcodeScan.value = '';
};

watch(() => filterForm.search, debounceNavigate);
watch(() => [filterForm.per_page, filterForm.date_from, filterForm.date_to, filterForm.branch_id, filterForm.warehouse_id, filterForm.customer_id, filterForm.sales_invoice, filterForm.order_number, filterForm.status, filterForm.delivery_status, filterForm.transporter, filterForm.vehicle_number, filterForm.reference_type, filterForm.sort, filterForm.direction], () => navigate({ page: 1 }));
</script>

<template>
  <Layout :page="page" :title="title">
    <template #topbar-title>
      <div class="bill-page-title">
        <span>WAREHOUSE DISPATCH</span>
        <h1>{{ title }}</h1>
        <p>Dispatch queue, challan logistics, picking, packing and delivery tracking without duplicate stock deduction.</p>
      </div>
    </template>

    <div class="dispatch-page">
      <ActionToolbar>
        <button type="button" title="Create outward from order management" @click="createOutward">Create Outward</button>
        <button type="button" title="Generate challan from selected reserved order" @click="generateChallan">Generate Challan</button>
        <button type="button" title="Generate or record e-way bill" @click="generateEwayBill">Generate E-Way Bill</button>
        <button type="button" title="Print selected challan or dispatch report" @click="printDocument('challan')">Print Challan</button>
        <button type="button" title="Export dispatch data" :disabled="!permissions.export" @click="exportCsv">Export</button>
        <button type="button" title="Refresh dispatch queue" :disabled="loading" @click="refresh">{{ loading ? 'Refreshing...' : 'Refresh' }}</button>
      </ActionToolbar>

      <SummaryCards :cards="summaryCards" @select="(card) => switchTab(card.tab, card)" />

      <FilterPanel>
        <template #actions><button type="button" title="Clear all filters" @click="clearFilters">Clear Filters</button></template>
        <label><span>Date From</span><input v-model="filterForm.date_from" type="date" /></label>
        <label><span>Date To</span><input v-model="filterForm.date_to" type="date" /></label>
        <label><span>Branch</span><select v-model="filterForm.branch_id"><option value="">All branches</option><option v-for="branch in references.branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option></select></label>
        <label><span>Warehouse</span><select v-model="filterForm.warehouse_id"><option value="">All warehouses</option><option v-for="warehouse in filteredWarehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option></select></label>
        <label><span>Customer</span><select v-model="filterForm.customer_id"><option value="">All customers</option><option v-for="customer in references.customers || []" :key="customer.id" :value="customer.id">{{ customer.customer_name }}</option></select></label>
        <label><span>Sales Invoice</span><input v-model="filterForm.sales_invoice" placeholder="Invoice number" /></label>
        <label><span>Order Number</span><input v-model="filterForm.order_number" placeholder="Order number" /></label>
        <label><span>Dispatch Status</span><select v-model="filterForm.status"><option value="">All statuses</option><option v-for="status in references.statuses || []" :key="status" :value="status">{{ status }}</option></select></label>
        <label><span>Delivery Status</span><select v-model="filterForm.delivery_status"><option value="">All delivery</option><option v-for="status in references.delivery_statuses || []" :key="status" :value="status">{{ status }}</option></select></label>
        <label><span>Transporter</span><input v-model="filterForm.transporter" list="transporters" placeholder="Transporter" /><datalist id="transporters"><option v-for="name in references.transporters || []" :key="name" :value="name" /></datalist></label>
        <label><span>Vehicle Number</span><input v-model="filterForm.vehicle_number" placeholder="Vehicle no" /></label>
        <label><span>Global Search</span><input v-model="filterForm.search" placeholder="Dispatch no, customer, mobile" /></label>
      </FilterPanel>

      <section class="bill-ui-card">
        <div class="bill-ui-card-head">
          <div><span>QUEUE</span><h2>Dispatch Queue</h2></div>
          <div class="dispatch-tabs">
            <button v-for="tab in tabs" :key="tab" type="button" :class="{ active: activeTab === tab }" :title="`Show ${tab} dispatches`" @click="switchTab(tab)">{{ tab }}</button>
          </div>
        </div>
        <p v-if="message" class="dispatch-message">{{ message }}</p>
        <p class="dispatch-rule">{{ stock_rule }}</p>
        <div class="barcode-row">
          <input v-model="barcodeScan" placeholder="Scan barcode for picking" title="Barcode scanning for warehouse picking" @keyup.enter="scanBarcode" />
          <button type="button" title="Capture scanned barcode" @click="scanBarcode">Scan</button>
        </div>
        <DispatchTable :columns="tableColumns" :rows="currentRows" empty-text="No dispatch records found for the selected filters." @open="openDrawer">
          <template #cell-total_quantity="{ value }">{{ qty(value) }}</template>
          <template #cell-reserved_quantity="{ value }">{{ qty(value) }}</template>
          <template #cell-dispatched_quantity="{ value }">{{ qty(value) }}</template>
          <template #cell-remaining_quantity="{ value }">{{ qty(value) }}</template>
          <template #cell-quantity_out="{ value }">{{ qty(value) }}</template>
          <template #cell-dispatch_status="{ value }"><StatusBadge :status="value" /></template>
          <template #cell-delivery_status="{ value }"><StatusBadge :status="value" /></template>
          <template #cell-status="{ value }"><StatusBadge :status="value" /></template>
          <template #actions="{ row }">
            <div class="row-actions">
              <button type="button" title="Open dispatch details" @click="openDrawer(row)">Details</button>
              <button v-if="activeTab === 'reserved'" type="button" title="Create dispatch from reservation" @click="dispatchReservation(row)">Dispatch</button>
              <button v-if="activeTab === 'reserved'" type="button" title="Release reservation" @click="releaseReservation(row)">Release</button>
              <button v-if="row.row_type === 'challan' && ['draft','ready','ready_to_pick','pending'].includes(row.dispatch_status)" type="button" title="Start picking" @click="postWorkflow(row, 'pick', 'Picking started.')">Pick</button>
              <button v-if="row.row_type === 'challan' && ['picking'].includes(row.dispatch_status)" type="button" title="Mark packed" @click="postWorkflow(row, 'pack', 'Packing completed.')">Pack</button>
              <button v-if="row.row_type === 'challan' && ['packed','draft','ready','ready_to_pick','pending'].includes(row.dispatch_status)" type="button" title="Dispatch challan" @click="postWorkflow(row, 'dispatch', 'Dispatch posted.')">Dispatch</button>
              <button v-if="row.row_type === 'challan' && row.dispatch_status === 'dispatched'" type="button" title="Mark delivered" @click="postWorkflow(row, 'deliver', 'Delivery completed.')">Deliver</button>
              <button v-if="row.row_type === 'challan' && !['dispatched','delivered','cancelled'].includes(row.dispatch_status)" type="button" class="danger" title="Cancel dispatch" @click="postWorkflow(row, 'cancel', 'Dispatch cancelled.')">Cancel</button>
            </div>
          </template>
        </DispatchTable>
        <div v-if="(pagination.total || 0) > 0 && (pagination.last_page || 1) > 1" class="dispatch-pagination"><button :disabled="pagination.current_page <= 1" @click="pageTo(pagination.current_page - 1)">Previous</button><span>{{ pagination.from || 0 }}-{{ pagination.to || 0 }} of {{ pagination.total || 0 }}</span><button :disabled="pagination.current_page >= pagination.last_page" @click="pageTo(pagination.current_page + 1)">Next</button></div>
      </section>

      <section class="bill-ui-card outward-history">
        <div class="bill-ui-card-head"><div><span>HISTORY</span><h2>Outward History</h2></div><button type="button" title="Open ledger history" @click="switchTab('ledger')">Ledger</button></div>
        <DispatchTable :columns="[{key:'number',label:'Dispatch No'},{key:'sales_invoice',label:'Invoice No'},{key:'customer_name',label:'Customer'},{key:'warehouse_name',label:'Warehouse'},{key:'date',label:'Dispatch Date'},{key:'delivery_status',label:'Delivery Date'},{key:'dispatch_status',label:'Status'}]" :rows="activeTab === 'ledger' ? [] : currentRows.slice(0, 8)" empty-text="No outward history available." @open="openDrawer">
          <template #cell-delivery_status="{ row, value }">{{ value === 'delivered' ? row.date : value }}</template>
          <template #cell-dispatch_status="{ value }"><StatusBadge :status="value" /></template>
          <template #actions="{ row }"><div class="row-actions"><button type="button" title="Print dispatch document" @click="selectedRow = row; printDocument('challan')">Print</button><button type="button" title="Open dispatch details" @click="openDrawer(row)">Details</button></div></template>
        </DispatchTable>
      </section>

      <DispatchDrawer :open="drawerOpen" :loading="drawerLoading" :dispatch="drawerDispatch" @close="drawerOpen = false" />
    </div>
  </Layout>
</template>

<style scoped>
.dispatch-page{padding:4px 0 28px}.dispatch-tabs,.row-actions,.barcode-row,.dispatch-pagination{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.dispatch-tabs button,.row-actions button,.barcode-row button,.dispatch-pagination button,.outward-history button{min-height:34px;padding:7px 10px;border:1px solid #d8e0eb;border-radius:8px;background:#fff;color:#344159;font-size:12px;font-weight:850;cursor:pointer;text-transform:capitalize}.dispatch-tabs button.active{color:#fff;background:#142139;border-color:#142139}.row-actions .danger{color:#d23f49;background:#fff3f4;border-color:#ffd6da}.dispatch-message{margin:0 0 10px;padding:10px;border:1px solid #cfe3ff;border-radius:8px;background:#eef6ff;color:#1d4f8f;font-size:12px;font-weight:850}.dispatch-rule{margin:0 0 12px;padding:10px;border:1px solid #e5eaf2;border-radius:8px;background:#f8fafc;color:#536174;font-size:12px;font-weight:750}.barcode-row{margin-bottom:12px}.barcode-row input{min-height:38px;min-width:280px;padding:8px 10px;border:1px solid #d8e0eb;border-radius:8px;font-size:12px}.dispatch-pagination{justify-content:flex-end;margin-top:12px}.outward-history{margin-top:14px}@media(max-width:720px){.barcode-row input{min-width:0;width:100%}.row-actions{flex-direction:column;align-items:stretch}.row-actions button{width:100%}}
</style>
