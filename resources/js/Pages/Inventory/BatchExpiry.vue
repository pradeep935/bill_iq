<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import Layout from '../Layout.vue';
import InventoryApi from './InventoryApi';
import AppToast from '../../Components/Common/AppToast.vue';
import TableLoadingState from '../../Components/Common/TableLoadingState.vue';
import RowActionMenu from '../../Components/Common/RowActionMenu.vue';

defineProps({
    page: { type: String, default: 'inventory-batches' },
    title: { type: String, default: 'Batch & Expiry' },
});

const loading = ref(false);
const initialLoaded = ref(false);
const detailLoading = ref(false);
const rows = ref([]);
const references = ref({ products: [], branches: [], warehouses: [], statuses: [] });
const dashboard = ref({});
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });
const toast = ref(null);
const selected = ref(null);
const reports = ref({});
const activeReport = ref('batch_stock');
const showActions = ref(null);
const openActionMenuId = ref(null);
const actionModal = ref(null);
const actionForm = ref({});
const actionSaving = ref(false);

const filters = ref({
    search: '',
    product_id: '',
    branch_id: '',
    warehouse_id: '',
    batch_status: '',
    expiry_filter: '',
    mfg_from: '',
    mfg_to: '',
    expiry_from: '',
    expiry_to: '',
    per_page: 15,
});

const permissions = {
    create: true,
    view: true,
    edit: true,
    block: true,
    unblock: true,
    quarantine: true,
    transfer: true,
    split: true,
    merge: true,
    print: true,
    export: true,
};

const reportTabs = [
    { key: 'batch_stock', label: 'Batch Stock Report' },
    { key: 'expiry_report', label: 'Expiry Report' },
    { key: 'expire_today_report', label: 'Expire Today' },
    { key: 'near_expiry_report', label: 'Near Expiry Report' },
    { key: 'expired_report', label: 'Expired Report' },
    { key: 'blocked_report', label: 'Blocked Report' },
    { key: 'quarantine_report', label: 'Quarantine Report' },
    { key: 'fefo_priority', label: 'FEFO Priority' },
    { key: 'batch_movement', label: 'Batch Movement' },
    { key: 'batch_valuation', label: 'Batch Valuation' },
];

const summaryCards = computed(() => [
    { label: 'Active Batches', value: dashboard.value.active_batches || 0, tone: 'good' },
    { label: 'Near Expiry', value: dashboard.value.near_expiry || 0, tone: 'warn' },
    { label: 'Expired', value: dashboard.value.expired || 0, tone: 'bad' },
    { label: 'Total Batch Quantity', value: qty(dashboard.value.total_batch_quantity), tone: 'info' },
    { label: 'Total Batch Value', value: `Rs. ${money(dashboard.value.total_batch_value)}`, tone: 'money' },
    { label: 'Blocked', value: dashboard.value.blocked_batches || 0, tone: 'bad' },
    { label: 'Quarantine', value: dashboard.value.quarantined_batches || 0, tone: 'warn' },
]);

const expiryCards = computed(() => [
    { label: 'Expire Today', value: dashboard.value.expire_today || 0, tone: 'bad' },
    { label: 'Expire in 7 Days', value: dashboard.value.expire_7_days || 0, tone: 'warn' },
    { label: 'Expire in 30 Days', value: dashboard.value.expire_30_days || 0, tone: 'info' },
    { label: 'Expired', value: dashboard.value.expired || 0, tone: 'bad' },
]);

const filteredWarehouses = computed(() => {
    if (!filters.value.branch_id) return references.value.warehouses || [];
    return (references.value.warehouses || []).filter((w) => Number(w.branch_id || 0) === Number(filters.value.branch_id));
});

const reportRows = computed(() => reports.value?.[activeReport.value] || []);

let timer = null;

const money = (value) => Number(value || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const qty = (value) => Number(value || 0).toLocaleString('en-IN', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
const showToast = (message, type = 'success', title = 'Batch & Expiry') => { toast.value = { title, message, type }; };
const rowActionId = (row) => `${row.id}-${row.branch_id || 0}-${row.warehouse_id || 0}`;
const toggleActionMenu = (row) => {
    const id = rowActionId(row);
    openActionMenuId.value = openActionMenuId.value === id ? null : id;
};
const closeActionMenu = () => {
    openActionMenuId.value = null;
};

const loadReferences = async () => {
    references.value = await InventoryApi.batchReferences();
};

const load = async (page = 1) => {
    loading.value = true;
    try {
        const [response, reportResponse] = await Promise.all([
            InventoryApi.batchList({ ...filters.value, page }),
            InventoryApi.batchReports(filters.value),
        ]);
        rows.value = response.items || [];
        dashboard.value = response.dashboard || {};
        pagination.value = response.pagination || pagination.value;
        reports.value = reportResponse || {};
        initialLoaded.value = true;
    } finally {
        loading.value = false;
    }
};

const clearFilters = () => {
    filters.value = { search: '', product_id: '', branch_id: '', warehouse_id: '', batch_status: '', expiry_filter: '', mfg_from: '', mfg_to: '', expiry_from: '', expiry_to: '', per_page: 15 };
};

const openDetail = async (row) => {
    detailLoading.value = true;
    try {
        selected.value = await InventoryApi.batchDetail(row.id, { branch_id: row.branch_id || '', warehouse_id: row.warehouse_id || '' });
    } finally {
        detailLoading.value = false;
    }
};

const closeDetail = () => { selected.value = null; };

const openActionModal = (row, type) => {
    showActions.value = null;
    closeActionMenu();
    actionModal.value = { row, type };
    actionForm.value = {
        reason: '',
        release_outcome: 'saleable',
        quantity: type === 'transfer' ? availableQty(row) : '',
        destination_branch_id: '',
        destination_warehouse_id: '',
        destination_location: '',
        batch_number: '',
        target_batch_id: '',
    };
};

const closeActionModal = () => {
    if (actionSaving.value) return;
    actionModal.value = null;
    actionForm.value = {};
};

const availableQty = (row) => Number(row.saleable_quantity_available ?? row.quantity_available ?? 0);
const canMove = (row) => availableQty(row) > 0 && !['blocked', 'quarantined', 'expired', 'empty'].includes(row.batch_status);

const submitAction = async () => {
    if (!actionModal.value) return;
    const { row, type } = actionModal.value;
    actionSaving.value = true;
    try {
        if (type === 'block' || type === 'quarantine' || type === 'release') {
            const status = type === 'block' ? 'blocked' : type === 'quarantine' ? 'quarantined' : 'active';
            await InventoryApi.updateBatchStatus(row.id, {
                status,
                reason: actionForm.value.reason,
                release_outcome: type === 'release' ? actionForm.value.release_outcome : null,
            });
            showToast(type === 'release' ? 'Batch release posted.' : `Batch ${type} posted.`);
        }

        if (type === 'transfer') {
            if (!canMove(row)) throw new Error('Only active batches with available stock can be transferred.');
            await InventoryApi.transferBatch(row.id, {
                source_branch_id: row.branch_id,
                source_warehouse_id: row.warehouse_id,
                destination_branch_id: actionForm.value.destination_branch_id,
                destination_warehouse_id: actionForm.value.destination_warehouse_id,
                destination_location: actionForm.value.destination_location,
                quantity: actionForm.value.quantity,
                remarks: actionForm.value.reason || 'Batch transfer from Batch & Expiry register',
            });
            showToast('Batch transfer posted.');
        }

        if (type === 'split') {
            if (!canMove(row)) throw new Error('Only active batches with available stock can be split.');
            await InventoryApi.splitBatch(row.id, { batch_number: actionForm.value.batch_number, quantity: actionForm.value.quantity });
            showToast('Batch split posted.');
        }

        if (type === 'merge') {
            await InventoryApi.mergeBatch(row.id, { target_batch_id: actionForm.value.target_batch_id });
            showToast('Batch merge posted.');
        }

        actionModal.value = null;
        actionForm.value = {};
        await load(pagination.value.current_page || 1);
    } catch (error) {
        showToast(error?.response?.data?.message || error?.message || 'Batch action failed.', 'error');
    } finally {
        actionSaving.value = false;
    }
};

const statusLabel = (status) => String(status || '-').replaceAll('_', ' ');

const printBarcode = (row) => {
    const html = `<div style="font-family:Arial;padding:18px;width:320px"><h2>${row.product_name}</h2><p>Batch: ${row.batch_number}</p><p>MFG: ${row.mfg_date || '-'}</p><p>Expiry: ${row.expiry_date || '-'}</p><div style="font-family:monospace;font-size:28px;letter-spacing:2px;border-top:1px solid #111;border-bottom:1px solid #111;padding:12px 0">${row.barcode || row.batch_number}</div></div>`;
    const win = window.open('', '_blank');
    win.document.write(html);
    win.document.close();
    win.print();
};

const exportRows = (format) => {
    if (format === 'pdf') {
        window.print();
        return;
    }
    const source = activeReport.value === 'batch_stock' ? rows.value : reportRows.value;
    const data = source.map((row) => ({
        batch_number: row.batch_number,
        product: row.product_name,
        branch: row.branch_name,
        warehouse: row.warehouse_name,
        mfg_date: row.mfg_date,
        expiry_date: row.expiry_date,
        current_qty: row.quantity_on_hand,
        reserved_qty: row.reserved_quantity,
        available_qty: row.quantity_available,
        average_cost: row.average_cost,
        batch_value: row.batch_value,
        status: row.batch_status,
    }));
    const csv = [Object.keys(data[0] || { report: activeReport.value }).join(','), ...data.map((row) => Object.values(row).map((value) => `"${String(value ?? '').replace(/"/g, '""')}"`).join(','))].join('\n');
    const blob = new Blob([csv], { type: format === 'excel' ? 'application/vnd.ms-excel' : 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `batch-${activeReport.value}.${format === 'excel' ? 'xls' : 'csv'}`;
    link.click();
    URL.revokeObjectURL(link.href);
};

watch(filters, () => {
    clearTimeout(timer);
    timer = setTimeout(() => load(1), 350);
}, { deep: true });

onMounted(async () => {
    await loadReferences();
    await load();
});
</script>

<template>
    <Layout :page="page" :title="title">
        <template #topbar-title>
            <div class="bill-page-title">
                <span>INVENTORY CONTROL</span>
                <h1>Batch & Expiry</h1>
                <p>Ledger-backed batch inventory, expiry alerts, FEFO support, blocking, quarantine and batch valuation.</p>
            </div>
        </template>

        <div class="batch-page">
            <AppToast v-if="toast" show :title="toast.title" :message="toast.message" :type="toast.type" />

            <div class="toolbar">
                <button :disabled="loading" @click="load()">Refresh</button>
                <button v-if="permissions.export" @click="exportRows('csv')">CSV</button>
                <button v-if="permissions.export" @click="exportRows('excel')">Excel</button>
                <button v-if="permissions.export" @click="exportRows('pdf')">PDF</button>
            </div>

            <div class="batch-card-grid primary-cards">
                <div v-for="card in summaryCards" :key="card.label" class="batch-card" :class="`tone-${card.tone}`">
                    <span>{{ card.label }}</span>
                    <strong>{{ card.value }}</strong>
                </div>
            </div>

            <div class="expiry-strip">
                <div v-for="card in expiryCards" :key="card.label" class="expiry-item" :class="`tone-${card.tone}`">
                    <span>{{ card.label }}</span>
                    <strong>{{ card.value }}</strong>
                </div>
            </div>

            <TableLoadingState
                v-if="loading && !initialLoaded"
                title="Loading Batch & Expiry..."
                description="Preparing batch register, expiry alerts and ledger-backed valuation."
            />

            <section v-else class="panel register-panel loading-host">
                <div class="filters">
                    <input v-model="filters.search" placeholder="Search batch, product, SKU, barcode" />
                    <select v-model="filters.product_id"><option value="">All Products</option><option v-for="product in references.products" :key="product.id" :value="product.id">{{ product.name }}</option></select>
                    <select v-model="filters.branch_id"><option value="">All Branches</option><option v-for="branch in references.branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option></select>
                    <select v-model="filters.warehouse_id"><option value="">All Warehouses</option><option v-for="warehouse in filteredWarehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option></select>
                    <select v-model="filters.batch_status"><option value="">All Status</option><option value="active">Active</option><option value="expire_today">Expire Today</option><option value="near_expiry">Near Expiry</option><option value="expired">Expired</option><option value="blocked">Blocked</option><option value="quarantined">Quarantined</option><option value="empty">Empty</option></select>
                    <select v-model="filters.expiry_filter"><option value="">Expiry Filter</option><option value="near">Near Expiry</option><option value="expired">Expired</option></select>
                    <input v-model="filters.mfg_from" type="date" />
                    <input v-model="filters.expiry_to" type="date" />
                    <select v-model="filters.per_page"><option :value="15">15 / page</option><option :value="25">25 / page</option><option :value="50">50 / page</option></select>
                    <button @click="clearFilters">Clear</button>
                </div>

                <TableLoadingState
                    v-if="loading"
                    overlay
                    title="Refreshing batches..."
                    description="Fetching latest batch stock from stock ledgers."
                    :show-skeleton="false"
                />

                <div class="table-wrapper batch-table-wrapper">
                    <table class="batch-register-table">
                        <thead>
                            <tr><th>Batch Number</th><th>Product</th><th>FEFO</th><th>Condition</th><th>Branch</th><th>Warehouse</th><th>Expiry</th><th>Days</th><th>Current Qty</th><th>Available Qty</th><th>Batch Value</th><th>Status</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in rows" :key="`${row.id}-${row.branch_id}-${row.warehouse_id}`">
                                <td><strong>{{ row.batch_number }}</strong></td>
                                <td>{{ row.product_name }}<span>{{ row.sku }}</span></td>
                                <td>{{ row.fefo_priority || '-' }}</td>
                                <td>{{ row.condition_status || 'saleable' }}</td>
                                <td>{{ row.branch_name || '-' }}</td>
                                <td>{{ row.warehouse_name || '-' }}</td>
                                <td>{{ row.expiry_date || '-' }}</td>
                                <td>{{ row.days_remaining ?? '-' }}</td>
                                <td>{{ qty(row.quantity_on_hand) }}</td>
                                <td>{{ qty(availableQty(row)) }}</td>
                                <td><strong>Rs. {{ money(row.batch_value) }}</strong></td>
                                <td><span class="status" :class="row.batch_status">{{ statusLabel(row.batch_status) }}</span></td>
                                <td class="actions-cell">
                                    <div class="row-actions">
                                        <RowActionMenu :open="openActionMenuId === rowActionId(row)" :show-view="false" more-label="Actions" more-title="Batch actions" @toggle="toggleActionMenu(row)" @close="closeActionMenu">
                                            <button title="View batch details" @click="openDetail(row); closeActionMenu()">View</button>
                                            <button title="Open batch ledger" @click="openDetail(row); closeActionMenu()">Ledger</button>
                                            <button v-if="permissions.print" title="Print batch label" @click="printBarcode(row); closeActionMenu()">Print</button>
                                            <button v-if="permissions.block && !['blocked','empty','expired'].includes(row.batch_status)" title="Block batch from sale" @click="openActionModal(row, 'block')">Block</button>
                                            <button v-if="permissions.unblock && row.batch_status === 'blocked'" title="Unblock batch" @click="openActionModal(row, 'release')">Unblock</button>
                                            <button v-if="permissions.quarantine && !['quarantined','empty','expired'].includes(row.batch_status)" title="Move batch to quarantine" @click="openActionModal(row, 'quarantine')">QRT</button>
                                            <button v-if="permissions.unblock && row.batch_status === 'quarantined'" title="Release quarantined batch" @click="openActionModal(row, 'release')">Release</button>
                                            <button v-if="permissions.transfer && canMove(row)" title="Transfer batch stock" @click="openActionModal(row, 'transfer')">Move</button>
                                            <button v-if="permissions.split && canMove(row)" title="Split batch quantity" @click="openActionModal(row, 'split')">Split</button>
                                            <button v-if="permissions.merge" title="Merge identical batch" @click="openActionModal(row, 'merge')">Merge</button>
                                            <button title="Open batch history" @click="openDetail(row); closeActionMenu()">History</button>
                                        </RowActionMenu>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!rows.length"><td colspan="13" class="empty">No batch stock found.</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="pager">
                    <button :disabled="pagination.current_page <= 1" @click="load(pagination.current_page - 1)">Previous</button>
                    <span>{{ pagination.from || 0 }}-{{ pagination.to || 0 }} of {{ pagination.total || 0 }}</span>
                    <button :disabled="pagination.current_page >= pagination.last_page" @click="load(pagination.current_page + 1)">Next</button>
                </div>
            </section>

            <section v-if="initialLoaded" class="panel reports-panel">
                <div class="section-head">
                    <div><h2>Batch Reports</h2><p>Reports are generated from the same batch ledger and current stock calculations.</p></div>
                    <div class="toolbar compact"><button @click="exportRows('csv')">CSV</button><button @click="exportRows('excel')">Excel</button><button @click="exportRows('pdf')">PDF</button></div>
                </div>
                <div class="tabs"><button v-for="report in reportTabs" :key="report.key" :class="{active: activeReport === report.key}" @click="activeReport = report.key">{{ report.label }}</button></div>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>Batch</th><th>Product</th><th>Branch</th><th>Warehouse</th><th>Expiry</th><th>Qty</th><th>Cost</th><th>Value</th><th>Status</th></tr></thead>
                        <tbody><tr v-for="(row, index) in reportRows" :key="index"><td>{{ row.batch_number }}</td><td>{{ row.product_name }}</td><td>{{ row.branch_name || '-' }}</td><td>{{ row.warehouse_name || '-' }}</td><td>{{ row.expiry_date || '-' }}</td><td>{{ qty(row.quantity_on_hand || row.quantity) }}</td><td>Rs. {{ money(row.average_cost) }}</td><td>Rs. {{ money(row.batch_value) }}</td><td>{{ statusLabel(row.batch_status) }}</td></tr><tr v-if="!reportRows.length"><td colspan="9" class="empty">No report rows found.</td></tr></tbody>
                    </table>
                </div>
            </section>

            <div v-if="selected" class="modal-backdrop" @click.self="closeDetail">
                <section class="detail-modal">
                    <button class="close" @click="closeDetail">Close</button>
                    <TableLoadingState v-if="detailLoading" title="Loading batch..." description="Preparing batch summary and ledger." variant="compact" :show-skeleton="false" />
                    <template v-else>
                        <div class="detail-head">
                            <div><h2>{{ selected.batch.batch_number }}</h2><p>{{ selected.batch.product }}</p></div>
                            <span class="status" :class="selected.batch.status">{{ statusLabel(selected.batch.status) }}</span>
                        </div>
                        <p class="detail-sub">{{ selected.batch.sku || '-' }} | {{ selected.batch.barcode || '-' }} | Condition: {{ selected.batch.condition_status || 'saleable' }}</p>
                        <div class="detail-grid">
                            <div><span>MFG</span><strong>{{ selected.batch.mfg_date || '-' }}</strong></div>
                            <div><span>Expiry</span><strong>{{ selected.batch.expiry_date || '-' }}</strong></div>
                            <div><span>Current Qty</span><strong>{{ qty(selected.summary.current_qty) }}</strong></div>
                            <div><span>Reserved</span><strong>{{ qty(selected.summary.reserved_qty) }}</strong></div>
                            <div><span>Available</span><strong>{{ qty(selected.summary.available_qty) }}</strong></div>
                            <div><span>Value</span><strong>Rs. {{ money(selected.summary.batch_value) }}</strong></div>
                            <div><span>Opening</span><strong>{{ qty(selected.summary.opening_stock) }}</strong></div>
                            <div><span>Purchases</span><strong>{{ qty(selected.summary.purchases) }}</strong></div>
                            <div><span>Purchase Returns</span><strong>{{ qty(selected.summary.purchase_returns) }}</strong></div>
                            <div><span>Sales</span><strong>{{ qty(selected.summary.sales) }}</strong></div>
                            <div><span>Sale Returns</span><strong>{{ qty(selected.summary.sale_returns) }}</strong></div>
                            <div><span>Adjusted In</span><strong>{{ qty(selected.summary.adjusted_in) }}</strong></div>
                            <div><span>Adjusted Out</span><strong>{{ qty(selected.summary.adjusted_out) }}</strong></div>
                            <div><span>Transfer In</span><strong>{{ qty(selected.summary.transferred_in) }}</strong></div>
                            <div><span>Transfer Out</span><strong>{{ qty(selected.summary.transferred_out) }}</strong></div>
                            <div><span>Produced</span><strong>{{ qty(selected.summary.produced_quantity) }}</strong></div>
                        </div>
                        <h3>Batch Ledger</h3>
                        <div class="table-wrapper"><table><thead><tr><th>Date</th><th>Voucher</th><th>Type</th><th>Branch</th><th>Warehouse</th><th>IN</th><th>OUT</th><th>Balance</th><th>Cost</th><th>User</th></tr></thead><tbody><tr v-for="line in selected.ledger" :key="line.id"><td>{{ line.date }}</td><td>{{ line.voucher }}</td><td>{{ line.type }}</td><td>{{ line.branch || '-' }}</td><td>{{ line.warehouse || '-' }}</td><td>{{ qty(line.in) }}</td><td>{{ qty(line.out) }}</td><td>{{ qty(line.balance) }}</td><td>Rs. {{ money(line.cost) }}</td><td>{{ line.user }}</td></tr><tr v-if="!selected.ledger.length"><td colspan="10" class="empty">No ledger movement found.</td></tr></tbody></table></div>
                        <h3>Timeline</h3>
                        <div class="timeline">
                            <div v-for="event in selected.history" :key="event.id" class="timeline-item">
                                <strong>{{ statusLabel(event.event_type) }}</strong>
                                <span>{{ event.date }} | {{ event.user }} | {{ event.remarks || '-' }}</span>
                            </div>
                            <div v-if="!selected.history?.length" class="empty">No audit events found.</div>
                        </div>
                    </template>
                </section>
            </div>

            <div v-if="actionModal" class="modal-backdrop" @click.self="closeActionModal">
                <section class="action-modal">
                    <button class="close" type="button" @click="closeActionModal">Close</button>
                    <div class="detail-head">
                        <div>
                            <h2>{{ statusLabel(actionModal.type) }}</h2>
                            <p>{{ actionModal.row.batch_number }} | {{ actionModal.row.product_name }}</p>
                        </div>
                        <span class="status" :class="actionModal.row.batch_status">{{ statusLabel(actionModal.row.batch_status) }}</span>
                    </div>

                    <div class="action-form">
                        <label v-if="['block','quarantine','release','transfer'].includes(actionModal.type)">
                            Reason / Remarks
                            <textarea v-model="actionForm.reason" rows="3" placeholder="Enter reason or remarks"></textarea>
                        </label>

                        <label v-if="actionModal.type === 'release'">
                            Release Outcome
                            <select v-model="actionForm.release_outcome">
                                <option value="saleable">Saleable</option>
                                <option value="damaged">Damaged</option>
                                <option value="expired">Expired</option>
                                <option value="blocked">Blocked</option>
                                <option value="return_to_supplier">Return to Supplier</option>
                            </select>
                        </label>

                        <template v-if="actionModal.type === 'transfer'">
                            <label>
                                Quantity
                                <input v-model.number="actionForm.quantity" type="number" min="0.001" step="0.001" />
                            </label>
                            <label>
                                Destination Branch
                                <select v-model="actionForm.destination_branch_id">
                                    <option value="">Select branch</option>
                                    <option v-for="branch in references.branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                                </select>
                            </label>
                            <label>
                                Destination Warehouse
                                <select v-model="actionForm.destination_warehouse_id">
                                    <option value="">Select warehouse</option>
                                    <option v-for="warehouse in references.warehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option>
                                </select>
                            </label>
                            <label>
                                Destination Location
                                <input v-model="actionForm.destination_location" placeholder="Rack / shelf / bin" />
                            </label>
                        </template>

                        <template v-if="actionModal.type === 'split'">
                            <label>
                                New Batch Number
                                <input v-model="actionForm.batch_number" placeholder="Example: BDM-2026-07-A" />
                            </label>
                            <label>
                                Split Quantity
                                <input v-model.number="actionForm.quantity" type="number" min="0.001" step="0.001" />
                            </label>
                        </template>

                        <label v-if="actionModal.type === 'merge'">
                            Target Batch ID
                            <input v-model.number="actionForm.target_batch_id" type="number" min="1" placeholder="Enter target batch ID" />
                        </label>
                    </div>

                    <div class="modal-actions">
                        <button type="button" :disabled="actionSaving" @click="closeActionModal">Cancel</button>
                        <button class="primary" type="button" :disabled="actionSaving" @click="submitAction">
                            {{ actionSaving ? 'Saving...' : 'Submit' }}
                        </button>
                    </div>
                </section>
            </div>
        </div>
    </Layout>
</template>

<style scoped>
.batch-page {
  padding: 0 0 28px;
}

.toolbar {
  display: flex;
  gap: 10px;
  justify-content: flex-end;
  margin: -4px 0 14px;
}

.toolbar.compact {
  margin: 0;
}

.batch-card-grid {
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  margin-bottom: 12px;
}

.batch-card,
.expiry-item {
  background: #fff;
  border: 1px solid #dfe6ef;
  border-left: 4px solid #cbd5e1;
  border-radius: 8px;
  box-shadow: 0 8px 22px rgba(25, 50, 84, .035);
  min-height: 76px;
  padding: 13px 14px;
}

.batch-card span,
.expiry-item span {
  color: #7f8da4;
  display: block;
  font-size: 11px;
  font-weight: 800;
  margin-bottom: 7px;
}

.batch-card strong,
.expiry-item strong {
  color: #142139;
  display: block;
  font-size: 20px;
  font-weight: 900;
  line-height: 1.1;
}

.tone-good {
  border-left-color: #22c55e;
}

.tone-warn {
  border-left-color: #f59e0b;
}

.tone-bad {
  border-left-color: #ef4444;
}

.tone-info {
  border-left-color: #2563eb;
}

.tone-money {
  border-left-color: #14b8a6;
}

.expiry-strip {
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  margin-bottom: 16px;
}

.panel {
  background: #fff;
  border: 1px solid #dfe6ef;
  border-radius: 8px;
  margin-top: 18px;
  padding: 18px;
}

.register-panel {
  margin-top: 0;
}

.reports-panel {
  margin-top: 16px;
}

.loading-host {
  position: relative;
}

.filters {
  display: grid;
  gap: 10px;
  grid-template-columns: minmax(230px, 1.7fr) repeat(5, minmax(118px, 1fr)) repeat(2, minmax(118px, .9fr)) minmax(105px, .8fr) minmax(76px, auto);
  margin-bottom: 14px;
}

.filters input,
.filters select,
button {
  background: #fff;
  border: 1px solid #d8e0eb;
  border-radius: 8px;
  color: #344159;
  font-size: 12px;
  font-weight: 750;
  min-height: 38px;
  padding: 8px 10px;
}

.section-head {
  align-items: flex-start;
  display: flex;
  justify-content: space-between;
  margin-bottom: 12px;
}

.section-head h2 {
  font-size: 18px;
  margin: 0;
}

.section-head p {
  color: #758197;
  font-size: 12px;
  margin: 4px 0 0;
}

.tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 12px;
}

.tabs button {
  min-height: 34px;
  padding: 7px 10px;
}

.tabs .active {
  background: #2563eb;
  border-color: #2563eb;
  color: #fff;
}

.table-wrapper {
  border: 1px solid #edf1f5;
  border-radius: 8px;
  overflow: auto;
}

table {
  border-collapse: collapse;
  width: 100%;
}

.batch-register-table {
  min-width: 1320px;
}

th,
td {
  border-bottom: 1px solid #edf1f5;
  font-size: 12px;
  padding: 11px 10px;
  text-align: left;
  white-space: nowrap;
}

th {
  background: #f8fafc;
  color: #69758a;
  font-size: 10px;
  letter-spacing: .04em;
  text-transform: uppercase;
}

td span {
  color: #758197;
  display: block;
  font-size: 11px;
  margin-top: 3px;
}

.status {
  background: #edf2ff;
  border-radius: 7px;
  color: #2457d6;
  display: inline-flex;
  font-size: 10px;
  font-weight: 800;
  padding: 5px 8px;
  text-transform: capitalize;
}

.status.expire_today,
.status.near_expiry {
  background: #fff7ed;
  color: #c2410c;
}

.status.expired,
.status.blocked {
  background: #fff1f2;
  color: #be123c;
}

.status.quarantined {
  background: #fef3c7;
  color: #92400e;
}

.status.empty {
  background: #f1f5f9;
  color: #475569;
}

.actions-cell {
  background: #fff;
  min-width: 330px;
  position: sticky;
  right: 0;
  z-index: 2;
}

.row-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  max-width: 320px;
}

.row-actions button,
.action-menu button {
  min-height: 30px;
  padding: 5px 8px;
}

.action-menu {
  background: #fff;
  border: 1px solid #dfe6ef;
  border-radius: 8px;
  box-shadow: 0 18px 40px rgba(15, 23, 42, .16);
  display: grid;
  gap: 4px;
  padding: 8px;
  position: absolute;
  right: 8px;
  top: 42px;
  z-index: 4;
}

.action-menu button {
  text-align: left;
}

.pager {
  align-items: center;
  display: flex;
  gap: 12px;
  justify-content: flex-end;
  margin-top: 14px;
}

.empty {
  color: #8490a2;
  text-align: center;
}

.modal-backdrop {
  align-items: center;
  background: rgba(15, 23, 42, .55);
  bottom: 0;
  display: flex;
  justify-content: center;
  left: 0;
  position: fixed;
  right: 0;
  top: 0;
  z-index: 40;
}

.detail-modal {
  background: #fff;
  border-radius: 8px;
  box-shadow: 0 24px 60px rgba(15, 23, 42, .28);
  max-height: 86vh;
  max-width: 980px;
  overflow: auto;
  padding: 22px;
  position: relative;
  width: min(92vw, 980px);
}

.action-modal {
  background: #fff;
  border-radius: 8px;
  box-shadow: 0 24px 60px rgba(15, 23, 42, .28);
  max-width: 620px;
  padding: 22px;
  position: relative;
  width: min(92vw, 620px);
}

.close {
  position: absolute;
  right: 16px;
  top: 16px;
}

.detail-head {
  align-items: flex-start;
  display: flex;
  justify-content: space-between;
}

.detail-head h2 {
  font-size: 24px;
  margin: 0;
}

.detail-head p,
.detail-sub {
  color: #758197;
  margin: 4px 0 0;
}

.detail-grid {
  display: grid;
  gap: 10px;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  margin: 18px 0;
}

.detail-grid div {
  background: #f8fafc;
  border: 1px solid #e3e9f2;
  border-radius: 8px;
  padding: 12px;
}

.detail-grid span {
  color: #7b8798;
  font-size: 11px;
  font-weight: 800;
  text-transform: uppercase;
}

.detail-grid strong {
  display: block;
  font-size: 16px;
  margin-top: 6px;
}

.timeline {
  display: grid;
  gap: 8px;
}

.timeline-item {
  border: 1px solid #e3e9f2;
  border-radius: 8px;
  padding: 10px;
}

.timeline-item strong {
  display: block;
  font-size: 12px;
  text-transform: capitalize;
}

.timeline-item span {
  color: #758197;
  font-size: 12px;
}

.action-form {
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  margin-top: 18px;
}

.action-form label {
  color: #344159;
  display: grid;
  font-size: 12px;
  font-weight: 800;
  gap: 7px;
}

.action-form textarea,
.action-form input,
.action-form select {
  background: #fff;
  border: 1px solid #d8e0eb;
  border-radius: 8px;
  color: #344159;
  font-size: 12px;
  font-weight: 700;
  min-height: 40px;
  padding: 9px 10px;
}

.action-form textarea {
  grid-column: 1 / -1;
  resize: vertical;
}

.modal-actions {
  border-top: 1px solid #edf1f5;
  display: flex;
  gap: 10px;
  justify-content: flex-end;
  margin-top: 18px;
  padding-top: 14px;
}

.modal-actions .primary {
  background: #2563eb;
  border-color: #2563eb;
  color: #fff;
}

@media (max-width: 1400px) {
  .batch-card-grid {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
}

@media (max-width: 1100px) {
  .batch-card-grid,
  .expiry-strip,
  .filters,
  .action-form,
  .detail-grid {
    grid-template-columns: 1fr;
  }

  .toolbar,
  .section-head {
    align-items: flex-start;
    flex-direction: column;
  }

  .action-menu {
    left: 0;
    right: auto;
  }
}
</style>
