<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import Layout from '../Layout.vue';
import InventoryApi from './InventoryApi';
import AppToast from '../../Components/Common/AppToast.vue';
import TableLoadingState from '../../Components/Common/TableLoadingState.vue';
import InventoryModuleScaffold from './Shared/InventoryModuleScaffold.vue';
import InventoryTable from './Shared/InventoryTable.vue';
import InventoryModal from './Shared/InventoryModal.vue';
import BarcodeScannerInput from './Shared/BarcodeScannerInput.vue';
import BarcodePreview from './Shared/BarcodePreview.vue';
import RowActionMenu from '../../Components/Common/RowActionMenu.vue';

defineProps({ page: { type: String, default: 'inventory-serials' }, title: { type: String, default: 'Serial Numbers' } });

const loading = ref(false);
const initialLoaded = ref(false);
const saving = ref(false);
const rows = ref([]);
const refs = ref({ products: [], branches: [], warehouses: [], batches: [], statuses: [], conditions: [] });
const dashboard = ref({});
const reports = ref({});
const selected = ref(null);
const modal = ref(null);
const errors = ref({});
const toast = ref(null);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });
const filters = ref({ search: '', product_id: '', branch_id: '', warehouse_id: '', batch_id: '', status: '', condition: '', warranty_filter: '', date_from: '', date_to: '', per_page: 15 });
const form = ref({ product_id: '', branch_id: '', warehouse_id: '', batch_id: '', serial_number: '', secondary_serial_number: '', imei_1: '', imei_2: '', condition: 'new', purchase_reference: '', sale_reference: '', purchase_date: '', warranty_expiry_date: '', remarks: '' });
const bulkText = ref('');
const activeReport = ref('serial_stock');
const openActionMenuId = ref(null);
let timer = null;

const columns = [
    { key: 'serial', label: 'Serial/IMEI' },
    { key: 'product', label: 'Product' },
    { key: 'variant', label: 'Variant' },
    { key: 'batch', label: 'Batch' },
    { key: 'branch', label: 'Branch' },
    { key: 'warehouse', label: 'Warehouse' },
    { key: 'condition', label: 'Condition' },
    { key: 'warranty', label: 'Warranty' },
    { key: 'purchase_reference', label: 'Purchase Ref' },
    { key: 'sale_reference', label: 'Sale Ref' },
    { key: 'status', label: 'Status' },
];
const cards = computed(() => [
    { label: 'Total Serials', value: dashboard.value.total_serials || 0, tone: 'info' },
    { label: 'In Stock', value: dashboard.value.in_stock || 0, tone: 'good' },
    { label: 'Reserved', value: dashboard.value.reserved || 0, tone: 'warn' },
    { label: 'Sold', value: dashboard.value.sold || 0, tone: 'money' },
    { label: 'Damaged', value: dashboard.value.damaged || 0, tone: 'bad' },
    { label: 'Under Repair', value: dashboard.value.under_repair || 0, tone: 'warn' },
    { label: 'Blocked', value: dashboard.value.blocked || 0, tone: 'bad' },
    { label: 'Warranty Expiring', value: dashboard.value.warranty_expiring || 0, tone: 'warn' },
]);
const reportRows = computed(() => reports.value?.[activeReport.value] || []);
const filteredWarehouses = computed(() => !filters.value.branch_id ? refs.value.warehouses : refs.value.warehouses.filter((w) => Number(w.branch_id || 0) === Number(filters.value.branch_id)));
const filteredFormWarehouses = computed(() => !form.value.branch_id ? refs.value.warehouses : refs.value.warehouses.filter((w) => Number(w.branch_id || 0) === Number(form.value.branch_id)));
const filteredBatches = computed(() => !form.value.product_id ? refs.value.batches : refs.value.batches.filter((b) => Number(b.product_id) === Number(form.value.product_id)));

const statusLabel = (value) => String(value || '-').replaceAll('_', ' ');
const showToast = (message, type = 'success') => { toast.value = { title: 'Serial Numbers', message, type }; };
const toggleActionMenu = (row) => {
    openActionMenuId.value = openActionMenuId.value === row.id ? null : row.id;
};
const closeActionMenu = () => {
    openActionMenuId.value = null;
};
const cellRow = (row) => ({
    ...row,
    serial: row.serial_number,
    product: row.product?.name || '-',
    variant: row.variant?.sku || '-',
    batch: row.batch?.batch_no || row.batch?.batch_number || '-',
    branch: row.branch?.name || '-',
    warehouse: row.warehouse?.name || '-',
    warranty: row.warranty_expiry_date || '-',
    status: row.current_status,
});

const loadRefs = async () => { refs.value = await InventoryApi.serialReferences(); };
const load = async (page = 1) => {
    loading.value = true;
    try {
        const [list, reportData] = await Promise.all([InventoryApi.serialList({ ...filters.value, page }), InventoryApi.serialReports(filters.value)]);
        rows.value = (list.items || []).map(cellRow);
        dashboard.value = list.dashboard || {};
        pagination.value = list.pagination || pagination.value;
        reports.value = reportData || {};
        initialLoaded.value = true;
    } finally {
        loading.value = false;
    }
};
const resetForm = () => { form.value = { product_id: '', branch_id: '', warehouse_id: '', batch_id: '', serial_number: '', secondary_serial_number: '', imei_1: '', imei_2: '', condition: 'new', purchase_reference: '', sale_reference: '', purchase_date: '', warranty_expiry_date: '', remarks: '' }; bulkText.value = ''; errors.value = {}; };
const openAdd = () => { resetForm(); modal.value = 'add'; };
const openBulk = () => { resetForm(); modal.value = 'bulk'; };
const editRow = (row) => { form.value = { ...form.value, ...row, product_id: row.product_id, branch_id: row.branch_id || '', warehouse_id: row.warehouse_id || '', batch_id: row.batch_id || '' }; modal.value = 'edit'; };
const closeModal = () => { if (!saving.value) modal.value = null; };
const viewRow = async (row) => { selected.value = await InventoryApi.serialDetail(row.id); modal.value = 'detail'; };
const saveSerial = async () => {
    saving.value = true; errors.value = {};
    try {
        await InventoryApi.saveSerial(form.value, modal.value === 'edit' ? form.value.id : null);
        showToast('Serial saved.');
        closeModal();
        await load(pagination.value.current_page || 1);
    } catch (e) {
        errors.value = e?.response?.data?.errors || {};
        showToast(e?.response?.data?.message || 'Unable to save serial.', 'error');
    } finally { saving.value = false; }
};
const bulkSave = async () => {
    const serials = bulkText.value.split(/\r?\n/).map((line) => line.trim()).filter(Boolean).map((serial_number) => ({ serial_number }));
    saving.value = true;
    try {
        await InventoryApi.bulkSerials({ ...form.value, serials });
        showToast(`${serials.length} serials imported.`);
        closeModal();
        await load(1);
    } catch (e) { showToast(e?.response?.data?.message || 'Bulk import failed.', 'error'); } finally { saving.value = false; }
};
const changeStatus = async (row, current_status) => {
    const remarks = window.prompt(`Reason for ${statusLabel(current_status)}`) || '';
    try { await InventoryApi.serialStatus(row.id, { current_status, remarks }); showToast('Serial status updated.'); await load(pagination.value.current_page || 1); } catch (e) { showToast(e?.response?.data?.message || 'Status update failed.', 'error'); }
};
const openTransfer = (row) => { form.value = { id: row.id, destination_branch_id: row.branch_id || '', destination_warehouse_id: row.warehouse_id || '', remarks: '' }; modal.value = 'transfer'; };
const transferSerial = async () => {
    saving.value = true;
    try { await InventoryApi.serialTransfer(form.value.id, form.value); showToast('Serial transferred.'); closeModal(); await load(pagination.value.current_page || 1); } catch (e) { showToast(e?.response?.data?.message || 'Transfer failed.', 'error'); } finally { saving.value = false; }
};
const scanSerial = (value) => { filters.value.search = value; load(1); };
const clearFilters = () => { filters.value = { search: '', product_id: '', branch_id: '', warehouse_id: '', batch_id: '', status: '', condition: '', warranty_filter: '', date_from: '', date_to: '', per_page: 15 }; };
const printLabel = (row) => {
    const win = window.open('', '_blank');
    win.document.write(`<html><body style="font-family:Arial;padding:20px;text-align:center"><h3>${row.product}</h3><p>${row.serial_number}</p><div style="font-size:36px;letter-spacing:2px;border-top:1px solid #111;border-bottom:1px solid #111;padding:12px">${row.imei_1 || row.serial_number}</div><p>${row.status}</p></body></html>`);
    win.document.close(); win.print();
};
const exportRows = (format) => {
    if (format === 'pdf') { window.print(); return; }
    const source = activeReport.value === 'serial_stock' ? rows.value : reportRows.value;
    const csvRows = source.map((r) => ({ serial: r.serial_number, product: r.product?.name || r.product || '', branch: r.branch?.name || r.branch || '', warehouse: r.warehouse?.name || r.warehouse || '', condition: r.condition, warranty: r.warranty_expiry_date, status: r.current_status || r.status }));
    const csv = [Object.keys(csvRows[0] || { report: activeReport.value }).join(','), ...csvRows.map((r) => Object.values(r).map((v) => `"${String(v ?? '').replace(/"/g, '""')}"`).join(','))].join('\n');
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([csv], { type: format === 'excel' ? 'application/vnd.ms-excel' : 'text/csv;charset=utf-8;' }));
    link.download = `serial-${activeReport.value}.${format === 'excel' ? 'xls' : 'csv'}`;
    link.click(); URL.revokeObjectURL(link.href);
};

watch(filters, () => { clearTimeout(timer); timer = setTimeout(() => load(1), 350); }, { deep: true });
onMounted(async () => { await loadRefs(); await load(); });
</script>

<template>
    <Layout :page="page" :title="title">
        <template #topbar-title><div class="bill-page-title"><span>INVENTORY CONTROL</span><h1>Serial Numbers</h1><p>Trace individual products, IMEI, warranty, location and movement history from stock-ledger transactions.</p></div></template>
        <AppToast v-if="toast" show :title="toast.title" :message="toast.message" :type="toast.type" />
        <TableLoadingState v-if="loading && !initialLoaded" title="Loading serial numbers..." description="Preparing serial register and warranty status." />
        <InventoryModuleScaffold v-else title="Serial Register" subtitle="Search, filter, scan, transfer and manage serial-number inventory." :cards="cards" :loading="loading" :initial-loaded="initialLoaded" :pagination="pagination" @page="load">
            <template #toolbar><button @click="openAdd">Add Serial</button><button @click="openBulk">Bulk Add</button><a class="button-link" href="/app/inventory/serials/import-sample">Sample</a><button @click="exportRows('csv')">CSV</button><button @click="exportRows('excel')">Excel</button><button @click="exportRows('pdf')">PDF</button></template>
            <template #filters>
                <BarcodeScannerInput v-model="filters.search" placeholder="Scan or search serial, IMEI, product, SKU" @scan="scanSerial" />
                <input v-model="filters.search" placeholder="Search serial, IMEI, product, SKU" />
                <select v-model="filters.product_id"><option value="">All Products</option><option v-for="p in refs.products" :key="p.id" :value="p.id">{{ p.name }}</option></select>
                <select v-model="filters.branch_id"><option value="">All Branches</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select>
                <select v-model="filters.warehouse_id"><option value="">All Warehouses</option><option v-for="w in filteredWarehouses" :key="w.id" :value="w.id">{{ w.name }}</option></select>
                <select v-model="filters.batch_id"><option value="">All Batches</option><option v-for="b in refs.batches" :key="b.id" :value="b.id">{{ b.batch_no || b.batch_number }}</option></select>
                <select v-model="filters.status"><option value="">All Status</option><option v-for="s in refs.statuses" :key="s" :value="s">{{ statusLabel(s) }}</option></select>
                <select v-model="filters.condition"><option value="">All Condition</option><option v-for="c in refs.conditions" :key="c" :value="c">{{ c }}</option></select>
                <select v-model="filters.warranty_filter"><option value="">Warranty</option><option value="expiring">Expiring</option><option value="expired">Expired</option></select>
                <input v-model="filters.date_from" type="date" /><input v-model="filters.date_to" type="date" />
                <select v-model="filters.per_page"><option :value="15">15 / page</option><option :value="25">25 / page</option><option :value="50">50 / page</option></select>
                <button @click="clearFilters">Clear</button>
            </template>
            <InventoryTable :columns="columns" :rows="rows" empty-text="No serial numbers found.">
                <template #cell-serial="{ row }"><strong>{{ row.serial_number }}</strong><span>{{ row.imei_1 || row.imei_2 || '-' }}</span></template>
                <template #cell-status="{ row }"><span class="status" :class="row.status">{{ statusLabel(row.status) }}</span></template>
                <template #actions="{ row }"><div class="row-actions"><RowActionMenu :open="openActionMenuId === row.id" :show-view="false" more-label="Actions" more-title="Serial actions" @toggle="toggleActionMenu(row)" @close="closeActionMenu"><button @click="viewRow(row); closeActionMenu()">View</button><button @click="editRow(row); closeActionMenu()">Edit</button><button @click="viewRow(row); closeActionMenu()">History</button><button @click="openTransfer(row); closeActionMenu()">Transfer</button><button @click="printLabel(row); closeActionMenu()">Print</button><button @click="changeStatus(row, 'damaged'); closeActionMenu()">Damaged</button><button @click="changeStatus(row, 'under_repair'); closeActionMenu()">Repair</button><button @click="changeStatus(row, 'blocked'); closeActionMenu()">Block</button></RowActionMenu></div></template>
            </InventoryTable>
            <section class="reports"><div class="tabs"><button v-for="r in ['serial_stock','serial_movement','sold_serials','warranty_expiry','damaged_blocked']" :key="r" :class="{active: activeReport === r}" @click="activeReport = r">{{ statusLabel(r) }}</button></div></section>
        </InventoryModuleScaffold>

        <InventoryModal v-if="['add','edit','bulk','transfer'].includes(modal)" :title="modal === 'bulk' ? 'Bulk Add Serials' : modal === 'transfer' ? 'Transfer Serial' : 'Serial Number'" :errors="errors" @close="closeModal">
            <div class="form-grid" v-if="modal !== 'transfer'">
                <select v-model="form.product_id"><option value="">Product</option><option v-for="p in refs.products" :key="p.id" :value="p.id">{{ p.name }}</option></select>
                <select v-model="form.branch_id"><option value="">Branch</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select>
                <select v-model="form.warehouse_id"><option value="">Warehouse</option><option v-for="w in filteredFormWarehouses" :key="w.id" :value="w.id">{{ w.name }}</option></select>
                <select v-model="form.batch_id"><option value="">Batch</option><option v-for="b in filteredBatches" :key="b.id" :value="b.id">{{ b.batch_no || b.batch_number }}</option></select>
                <input v-if="modal !== 'bulk'" v-model="form.serial_number" placeholder="Serial number" />
                <input v-if="modal !== 'bulk'" v-model="form.imei_1" placeholder="IMEI 1" />
                <input v-if="modal !== 'bulk'" v-model="form.imei_2" placeholder="IMEI 2" />
                <select v-model="form.condition"><option v-for="c in refs.conditions" :key="c">{{ c }}</option></select>
                <input v-model="form.purchase_reference" placeholder="Purchase reference" />
                <input v-model="form.warranty_expiry_date" type="date" />
                <textarea v-if="modal === 'bulk'" v-model="bulkText" rows="8" placeholder="One serial per line"></textarea>
                <textarea v-model="form.remarks" placeholder="Remarks"></textarea>
            </div>
            <div class="form-grid" v-else><select v-model="form.destination_branch_id"><option value="">Destination Branch</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select><select v-model="form.destination_warehouse_id"><option value="">Destination Warehouse</option><option v-for="w in refs.warehouses" :key="w.id" :value="w.id">{{ w.name }}</option></select><textarea v-model="form.remarks" placeholder="Transfer remarks"></textarea></div>
            <div v-if="Object.keys(errors).length" class="alert">{{ Object.values(errors).flat().join(' ') }}</div>
            <footer class="modal-actions"><button @click="closeModal">Cancel</button><button class="primary" :disabled="saving" @click="modal === 'bulk' ? bulkSave() : modal === 'transfer' ? transferSerial() : saveSerial()">{{ saving ? 'Saving...' : 'Save' }}</button></footer>
        </InventoryModal>

        <InventoryModal v-if="modal === 'detail' && selected" title="Serial Details" :subtitle="selected.serial.product" wide @close="closeModal">
            <BarcodePreview :title="selected.serial.product" :subtitle="selected.serial.sku" :value="selected.serial.imei_1 || selected.serial.serial_number" />
            <div class="detail-grid"><div v-for="(v,k) in selected.serial" :key="k"><span>{{ statusLabel(k) }}</span><strong>{{ v || '-' }}</strong></div></div>
            <h3>Ledger</h3><InventoryTable :columns="[{key:'date',label:'Date'},{key:'type',label:'Type'},{key:'reference',label:'Reference'},{key:'branch',label:'Branch'},{key:'warehouse',label:'Warehouse'},{key:'in',label:'In'},{key:'out',label:'Out'},{key:'user',label:'User'}]" :rows="selected.ledger" empty-text="No ledger movement." />
            <h3>History</h3><InventoryTable :columns="[{key:'date',label:'Date'},{key:'event_type',label:'Event'},{key:'from_status',label:'From'},{key:'to_status',label:'To'},{key:'remarks',label:'Remarks'},{key:'user',label:'User'}]" :rows="selected.history" empty-text="No history." />
        </InventoryModal>
    </Layout>
</template>

<style scoped>
input,select,textarea,button,.button-link{background:#fff;border:1px solid #d8e0eb;border-radius:8px;color:#344159;font-size:12px;font-weight:750;min-height:38px;padding:8px 10px}.button-link{align-items:center;display:inline-flex;text-decoration:none}.row-actions{display:flex;flex-wrap:wrap;gap:6px}.row-actions button{min-height:30px;padding:5px 8px}.status{background:#edf2ff;border-radius:7px;color:#2457d6;display:inline-flex;font-size:10px;font-weight:800;padding:5px 8px;text-transform:capitalize}.status.blocked,.status.damaged{background:#fff1f2;color:#be123c}.status.under_repair,.status.reserved{background:#fff7ed;color:#c2410c}.form-grid{display:grid;gap:10px;grid-template-columns:repeat(2,minmax(0,1fr))}.form-grid textarea{grid-column:1/-1}.modal-actions{border-top:1px solid #edf1f5;display:flex;gap:10px;justify-content:flex-end;margin-top:18px;padding-top:14px}.primary{background:#2563eb;color:#fff}.alert{background:#fff4f4;border:1px solid #ffd5d5;border-radius:8px;color:#b42318;font-size:12px;margin-top:12px;padding:10px}.detail-grid{display:grid;gap:10px;grid-template-columns:repeat(4,minmax(0,1fr));margin:16px 0}.detail-grid div{background:#f8fafc;border:1px solid #e3e9f2;border-radius:8px;padding:10px}.detail-grid span{color:#7b8798;display:block;font-size:10px;font-weight:800;text-transform:uppercase}.detail-grid strong{display:block;font-size:13px;margin-top:5px}.tabs{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}.tabs .active{background:#173b77;color:#fff}@media(max-width:800px){.form-grid,.detail-grid{grid-template-columns:1fr}}
</style>
