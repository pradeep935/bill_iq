<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import Layout from '../Layout.vue';
import InventoryApi from './InventoryApi';
import AppToast from '../../Components/Common/AppToast.vue';
import TableLoadingState from '../../Components/Common/TableLoadingState.vue';

const props = defineProps({ page: { type: String, default: 'inventory' }, title: { type: String, default: 'Inventory Control' }, initial_tab: { type: String, default: 'dashboard' } });

const tab = ref(props.initial_tab === 'adjustments' ? 'voucher' : props.initial_tab);
const saving = ref(false);
const loading = ref(false);
const errors = ref({});
const toast = ref(null);
const refs = ref({ branches: [], warehouses: [], reasons: [], statuses: [], products: [] });
const dashboard = ref({});
const reports = ref({ ledger: [], movement_report: [], inventory_valuation: [], branch_report: [], warehouse_report: [], adjustment_report: [], transfer_report: [], variance_report: [], batch_report: [], damage_report: [], expiry_report: [] });
const adjustments = ref([]);
const counts = ref([]);
const transfers = ref([]);
const movements = ref([]);
const warehouseLocations = ref([]);
const reasons = ref([]);
const tabs = ['dashboard', 'voucher', 'register', 'counts', 'transfers', 'locations', 'reasons', 'reports'];
const voucherType = ref(props.initial_tab === 'transfers' ? 'stock_transfer' : props.initial_tab === 'counts' ? 'stock_count' : 'stock_adjustment');
const registerFilters = reactive({ search: '', status: '', branch_id: '', warehouse_id: '', voucher_type: '', date_from: '', date_to: '', reason: '' });
const permissions = reactive({ create: true, edit_draft: true, approve: true, cancel: true, print: true });
const activeReport = ref('movement_report');

const today = new Date().toISOString().slice(0, 10);
const adjustment = reactive({ branch_id: '', warehouse_id: '', adjustment_date: today, adjustment_reason_id: '', adjustment_type: 'mixed', source: 'manual', status: 'draft', remarks: '', items: [{ product_id: '', unit_id: '', adjustment_quantity: 1, direction: 'in', unit_cost: 0, warehouse_location: '', condition_status: 'saleable', reason: '' }] });
const count = reactive({ branch_id: '', warehouse_id: '', count_date: today, count_type: 'full', freeze_stock: false, status: 'draft', remarks: '', items: [{ product_id: '', counted_quantity: 0, unit_cost: 0, warehouse_location: '', review_status: 'accepted' }] });
const transfer = reactive({ transfer_date: today, source_branch_id: '', source_warehouse_id: '', destination_branch_id: '', destination_warehouse_id: '', transfer_type: 'immediate', expected_delivery_date: '', status: 'draft', remarks: '', items: [{ product_id: '', requested_quantity: 1, approved_quantity: '', unit_cost: 0, source_batch_id: '', destination_batch_id: '', source_serial_id: '', destination_serial_id: '', source_location: '', destination_location: '' }] });
const location = reactive({ branch_id: '', warehouse_id: '', movement_date: today, status: 'draft', remarks: '', items: [{ product_id: '', quantity: 1, from_location: '', to_location: '' }] });
const locationMaster = reactive({ id: null, branch_id: '', warehouse_id: '', zone: '', aisle: '', rack: '', shelf: '', bin: '', status: 'active' });
const reason = reactive({ id: null, reason_code: '', reason_name: '', default_direction: 'out', default_condition_status: 'saleable', accounting_account_id: '', approval_required: true, status: 'active' });

const filteredWarehouses = (branchId) => !branchId ? refs.value.warehouses : refs.value.warehouses.filter((w) => Number(w.branch_id || 0) === Number(branchId));
const money = (v) => Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const qty = (v) => Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
const capture = (e) => { errors.value = e?.response?.data?.errors || { form: [e?.response?.data?.message || 'Unable to save.'] }; };
const clearErrors = () => { errors.value = {}; };
const selectedReason = computed(() => refs.value.reasons.find((r) => Number(r.id) === Number(adjustment.adjustment_reason_id)));
const applyReason = () => { if (selectedReason.value) adjustment.items.forEach((i) => { i.direction = selectedReason.value.default_direction; i.condition_status = selectedReason.value.default_condition_status || 'saleable'; }); };
const voucherTypes = [
    { key: 'stock_adjustment', label: 'Stock Adjustment', tab: 'voucher', source: 'manual', direction: 'out', adjustment_type: 'decrease' },
    { key: 'damage', label: 'Damage / Breakage', tab: 'voucher', source: 'damage', direction: 'out', adjustment_type: 'decrease', condition_status: 'damaged' },
    { key: 'expired_stock', label: 'Expired Stock', tab: 'voucher', source: 'expired_stock', direction: 'out', adjustment_type: 'decrease', condition_status: 'expired' },
    { key: 'production_consumption', label: 'Production Consumption', tab: 'voucher', source: 'production_consumption', direction: 'out', adjustment_type: 'decrease' },
    { key: 'production_output', label: 'Production Output', tab: 'voucher', source: 'production_output', direction: 'in', adjustment_type: 'increase' },
    { key: 'stock_transfer', label: 'Stock Transfer', tab: 'transfers' },
    { key: 'stock_count', label: 'Stock Count', tab: 'counts' },
];
const currentVoucherType = computed(() => voucherTypes.find((type) => type.key === voucherType.value) || voucherTypes[0]);
const isAdjustmentVoucher = computed(() => currentVoucherType.value.tab === 'voucher');
const isOutVoucher = computed(() => isAdjustmentVoucher.value && currentVoucherType.value.direction === 'out');
const showDestinationWarehouse = computed(() => voucherType.value === 'stock_transfer');
const reportTabs = [
    { key: 'movement_report', label: 'Stock Movement' },
    { key: 'inventory_valuation', label: 'Inventory Valuation' },
    { key: 'branch_report', label: 'Branch-wise' },
    { key: 'warehouse_report', label: 'Warehouse-wise' },
    { key: 'adjustment_report', label: 'Adjustment' },
    { key: 'transfer_report', label: 'Transfer' },
    { key: 'damage_report', label: 'Damage' },
    { key: 'expiry_report', label: 'Expiry' },
    { key: 'batch_report', label: 'Batch' },
];
const currentReportRows = computed(() => Array.isArray(reports.value?.[activeReport.value]) ? reports.value[activeReport.value] : []);
const countDifference = (item) => Number(item.counted_quantity || 0) - Number(item.system_quantity || 0);
const adjustmentQty = (item) => Math.abs(countDifference(item));
const registerRows = computed(() => [
    ...adjustments.value.map((row) => ({ id: `adjustment-${row.id}`, raw: row, type: row.source || 'stock_adjustment', number: row.voucher_number, date: row.adjustment_date, branch: row.branch?.name || '-', warehouse: row.warehouse?.name || '-', items: row.items?.length || 0, quantity: Number(row.total_quantity_in || 0) + Number(row.total_quantity_out || 0), status: row.status, action: 'adjustment' })),
    ...transfers.value.map((row) => ({ id: `transfer-${row.id}`, raw: row, type: row.transfer_type || 'stock_transfer', number: row.voucher_number, date: row.transfer_date, branch: `${row.source_branch?.name || '-'} -> ${row.destination_branch?.name || '-'}`, warehouse: `${row.source_warehouse?.name || '-'} -> ${row.destination_warehouse?.name || '-'}`, items: row.items?.length || 0, quantity: row.items?.reduce((sum, item) => sum + Number(item.approved_quantity || item.requested_quantity || 0), 0) || 0, status: row.status, action: 'transfer' })),
    ...counts.value.map((row) => ({ id: `count-${row.id}`, raw: row, type: 'stock_count', number: row.session_number, date: row.count_date, branch: row.branch?.name || '-', warehouse: row.warehouse?.name || '-', items: row.items?.length || 0, quantity: row.items?.reduce((sum, item) => sum + Math.abs(Number(item.variance_quantity || 0)), 0) || 0, status: row.status, action: 'count' })),
    ...movements.value.map((row) => ({ id: `movement-${row.id}`, raw: row, type: 'location_movement', number: row.voucher_number, date: row.movement_date, branch: row.branch?.name || '-', warehouse: row.warehouse?.name || '-', items: row.items?.length || 0, quantity: row.items?.reduce((sum, item) => sum + Number(item.quantity || 0), 0) || 0, status: row.status, action: 'location' })),
]);

const setVoucherType = () => {
    const type = currentVoucherType.value;
    tab.value = type.tab;
    if (type.tab === 'voucher') {
        adjustment.source = type.source;
        adjustment.adjustment_type = type.adjustment_type || 'mixed';
        adjustment.items.forEach((item) => {
            item.direction = type.direction || item.direction;
            item.condition_status = type.condition_status || item.condition_status || 'saleable';
        });
    }
};

const applyDefaultSelections = () => {
    const firstBranch = refs.value.branches?.[0];
    if (!adjustment.branch_id && firstBranch) adjustment.branch_id = firstBranch.id;
    if (!transfer.source_branch_id && firstBranch) transfer.source_branch_id = firstBranch.id;
    if (!count.branch_id && firstBranch) count.branch_id = firstBranch.id;
    if (!locationMaster.branch_id && firstBranch) locationMaster.branch_id = firstBranch.id;

    const firstWarehouse = filteredWarehouses(adjustment.branch_id)?.[0] || refs.value.warehouses?.[0];
    if (!adjustment.warehouse_id && firstWarehouse) adjustment.warehouse_id = firstWarehouse.id;
    if (!transfer.source_warehouse_id && firstWarehouse) transfer.source_warehouse_id = firstWarehouse.id;
    if (!count.warehouse_id && firstWarehouse) count.warehouse_id = firstWarehouse.id;
    if (!locationMaster.warehouse_id && firstWarehouse) locationMaster.warehouse_id = firstWarehouse.id;

    const destinationWarehouse = refs.value.warehouses?.find((warehouse) => Number(warehouse.id) !== Number(transfer.source_warehouse_id));
    if (!transfer.destination_branch_id && destinationWarehouse?.branch_id) transfer.destination_branch_id = destinationWarehouse.branch_id;
    if (!transfer.destination_warehouse_id && destinationWarehouse) transfer.destination_warehouse_id = destinationWarehouse.id;

    const firstReason = refs.value.reasons?.[0];
    if (!adjustment.adjustment_reason_id && firstReason) adjustment.adjustment_reason_id = firstReason.id;

    const firstProduct = refs.value.products?.[0];
    adjustment.items.forEach((item) => {
        if (!item.product_id && firstProduct) item.product_id = firstProduct.id;
    });
    transfer.items.forEach((item) => {
        if (!item.product_id && firstProduct) item.product_id = firstProduct.id;
    });
};

const load = async () => {
    loading.value = true;
    try {
        refs.value = await InventoryApi.controlReferences();
        applyDefaultSelections();
        dashboard.value = await InventoryApi.inventoryDashboard();
        adjustments.value = (await InventoryApi.stockAdjustments(registerFilters)).adjustments || [];
        counts.value = (await InventoryApi.stockCounts()).sessions || [];
        transfers.value = (await InventoryApi.stockTransfers()).transfers || [];
        movements.value = (await InventoryApi.locationTransfers()).movements || [];
        warehouseLocations.value = (await InventoryApi.warehouseLocations()).locations || [];
        reasons.value = (await InventoryApi.adjustmentReasons()).reasons || [];
        reports.value = await InventoryApi.inventoryReports();
    } finally {
        loading.value = false;
    }
};

const addRow = (list, row) => list.push({ ...row });
const showToast = (message, type = 'success', title = 'Inventory Voucher') => { toast.value = { title, message, type }; };
const validateAdjustmentClient = (status) => {
    if (!adjustment.warehouse_id) return 'Source warehouse is required.';
    if (status !== 'draft' && !adjustment.adjustment_reason_id && !adjustment.remarks) return 'Reason is required before posting.';
    if (!adjustment.items.length) return 'At least one product line is required.';
    const bad = adjustment.items.find((item) => !item.product_id || Number(item.adjustment_quantity || 0) <= 0);
    if (bad) return 'Each line must have product and quantity greater than zero.';
    const outWithoutStock = adjustment.items.find((item) => item.direction === 'out' && Number(item.current_stock || 0) < Number(item.adjustment_quantity || 0));
    if (outWithoutStock) return 'OUT quantity cannot be greater than available stock.';
    return '';
};
const saveAdjustment = async (status) => {
    const message = validateAdjustmentClient(status);
    if (message) { errors.value = { form: [message] }; showToast(message, 'error', 'Validation'); return; }
    saving.value = true; clearErrors();
    try {
        adjustment.status = status;
        await InventoryApi.saveStockAdjustment({ ...adjustment, items: adjustment.items.map((i) => ({ ...i })) });
        showToast(status === 'draft' ? 'Draft saved.' : 'Voucher posted successfully.');
        await load();
    } catch (e) { capture(e); showToast(e?.response?.data?.message || 'Unable to save voucher.', 'error'); } finally { saving.value = false; }
};
const postAdjustment = async (row) => { saving.value = true; try { await InventoryApi.postStockAdjustment(row.id); showToast('Stock adjustment posted.'); await load(); } catch (e) { capture(e); showToast(e?.response?.data?.message || 'Unable to post voucher.', 'error'); } finally { saving.value = false; } };
const reverseAdjustment = async (row) => { const remarks = window.prompt('Cancellation reason'); if (!remarks) return; saving.value = true; try { await InventoryApi.reverseStockAdjustment(row.id, remarks); showToast('Voucher cancelled with reversal entries.'); await load(); } catch (e) { capture(e); showToast(e?.response?.data?.message || 'Unable to cancel voucher.', 'error'); } finally { saving.value = false; } };
const saveCount = async (status) => { saving.value = true; clearErrors(); try { count.status = status; await InventoryApi.saveStockCount({ ...count, items: count.items.map((i) => ({ ...i, variance_quantity: countDifference(i), variance_value: adjustmentQty(i) * Number(i.unit_cost || 0) })) }); showToast(status === 'draft' ? 'Count draft saved.' : 'Count approved.'); await load(); } catch (e) { capture(e); showToast(e?.response?.data?.message || 'Unable to save count.', 'error'); } finally { saving.value = false; } };
const postVariance = async (row) => { saving.value = true; try { await InventoryApi.postCountVariance(row.id); showToast('Count variance posted.'); await load(); } catch (e) { capture(e); showToast(e?.response?.data?.message || 'Unable to post variance.', 'error'); } finally { saving.value = false; } };
const validateTransferClient = () => {
    if (!transfer.source_warehouse_id || !transfer.destination_warehouse_id) return 'Source and destination warehouse are required.';
    if (Number(transfer.source_warehouse_id) === Number(transfer.destination_warehouse_id)) return 'Source and destination warehouse cannot be same.';
    const bad = transfer.items.find((item) => !item.product_id || Number(item.requested_quantity || 0) <= 0);
    if (bad) return 'Each transfer line must have product and quantity greater than zero.';
    return '';
};
const saveTransfer = async (status) => { const message = validateTransferClient(); if (message) { errors.value = { form: [message] }; showToast(message, 'error', 'Validation'); return; } saving.value = true; clearErrors(); try { transfer.status = status; await InventoryApi.saveStockTransfer({ ...transfer, items: transfer.items.map((i) => ({ ...i, approved_quantity: i.approved_quantity || i.requested_quantity })) }); showToast(status === 'draft' ? 'Transfer draft saved.' : 'Transfer posted.'); await load(); } catch (e) { capture(e); showToast(e?.response?.data?.message || 'Unable to save transfer.', 'error'); } finally { saving.value = false; } };
const dispatchTransfer = async (row) => { saving.value = true; try { await InventoryApi.dispatchStockTransfer(row.id); showToast('Transfer dispatched.'); await load(); } catch (e) { capture(e); showToast(e?.response?.data?.message || 'Unable to dispatch transfer.', 'error'); } finally { saving.value = false; } };
const receiveTransfer = async (row) => { saving.value = true; try { await InventoryApi.receiveStockTransfer(row.id, { items: row.items?.map((i) => ({ id: i.id, received_quantity: i.dispatched_quantity || i.approved_quantity || i.requested_quantity, rejected_quantity: 0 })) || [] }); showToast('Transfer received.'); await load(); } catch (e) { capture(e); showToast(e?.response?.data?.message || 'Unable to receive transfer.', 'error'); } finally { saving.value = false; } };
const saveLocation = async (status) => { saving.value = true; clearErrors(); try { location.status = status; await InventoryApi.saveLocationTransfer({ ...location, items: location.items.map((i) => ({ ...i })) }); showToast('Location movement posted.'); await load(); } catch (e) { capture(e); showToast(e?.response?.data?.message || 'Unable to post location movement.', 'error'); } finally { saving.value = false; } };
const saveWarehouseLocation = async () => { saving.value = true; clearErrors(); try { await InventoryApi.saveWarehouseLocation({ ...locationMaster }, locationMaster.id); Object.assign(locationMaster, { id: null, zone: '', aisle: '', rack: '', shelf: '', bin: '', status: 'active' }); showToast('Warehouse location saved.'); await load(); } catch (e) { capture(e); showToast(e?.response?.data?.message || 'Unable to save warehouse location.', 'error'); } finally { saving.value = false; } };
const editWarehouseLocation = (row) => Object.assign(locationMaster, { id: row.id, branch_id: row.branch_id || '', warehouse_id: row.warehouse_id || '', zone: row.zone || '', aisle: row.aisle || '', rack: row.rack || '', shelf: row.shelf || '', bin: row.bin || '', status: row.status || 'active' });
const saveReason = async () => { saving.value = true; clearErrors(); try { await InventoryApi.saveAdjustmentReason({ ...reason }, reason.id); showToast('Reason saved.'); await load(); } catch (e) { capture(e); showToast(e?.response?.data?.message || 'Unable to save reason.', 'error'); } finally { saving.value = false; } };
const printPage = () => window.print();
const exportRows = (format) => {
    const rows = currentReportRows.value.map((row) => ({
        date: row.transaction_date || row.adjustment_date || row.transfer_date || row.expiry_date || '',
        type: row.transaction_type || row.source || row.transfer_type || activeReport.value,
        product: row.product?.name || row.product_name || row.name || '',
        branch: row.branch?.name || row.branch_name || row.source_branch?.name || '',
        warehouse: row.warehouse?.name || row.warehouse_name || row.source_warehouse?.name || '',
        quantity: row.quantity_available || row.quantity_in || row.total_quantity_in || '',
        value: row.stock_value || row.total_value_in || '',
        status: row.status || row.stock_status || '',
    }));
    if (format === 'pdf') { window.print(); return; }
    const csv = [Object.keys(rows[0] || { report: activeReport.value }).join(','), ...rows.map((row) => Object.values(row).map((value) => `"${String(value ?? '').replace(/"/g, '""')}"`).join(','))].join('\n');
    const blob = new Blob([csv], { type: format === 'excel' ? 'application/vnd.ms-excel' : 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `${activeReport.value}.${format === 'excel' ? 'xls' : 'csv'}`;
    link.click();
    URL.revokeObjectURL(link.href);
};
const refreshLineStock = async (item, source = adjustment) => {
    if (!item.product_id || !source.warehouse_id) return;
    const value = await InventoryApi.inventoryValuation({ branch_id: source.branch_id || '', warehouse_id: source.warehouse_id, product_id: item.product_id, product_variant_id: item.product_variant_id || '', batch_id: item.batch_id || item.source_batch_id || '' });
    item.current_stock = Number(value.available || value.quantity || 0);
    if (source === count) item.system_quantity = Number(value.quantity || value.available || 0);
    if (!Number(item.unit_cost || 0)) item.unit_cost = Number(value.average_cost || 0);
};
onMounted(load);
</script>

<template>
    <Layout :page="page" :title="title">
        <template #topbar-title>
            <div class="bill-page-title">
                <span>INVENTORY</span>
                <h1>Stock Operations</h1>
                <p>Adjustment, count, transfer, location movement and valuation from immutable stock ledgers.</p>
            </div>
        </template>
        <div class="inventory-control">
            <AppToast v-if="toast" show :title="toast.title" :message="toast.message" :type="toast.type" />
            <div class="page-toolbar"><button :disabled="loading" @click="load">Refresh</button></div>
            <div class="voucher-selector">
                <label>Voucher Type</label>
                <select v-model="voucherType" @change="setVoucherType">
                    <option v-for="type in voucherTypes" :key="type.key" :value="type.key">{{ type.label }}</option>
                </select>
                <span>{{ currentVoucherType.label }} controls required fields, validation and ledger posting type.</span>
            </div>
            <div class="tabs"><button v-for="t in tabs" :key="t" :class="{active: tab === t}" @click="tab = t">{{ t }}</button></div>
            <div v-if="errors.form" class="alert">{{ errors.form[0] }}</div>
            <TableLoadingState v-if="loading" title="Loading inventory vouchers..." description="Please wait while stock operation data is loaded." />

            <section v-if="!loading && tab === 'dashboard'" class="panel cards">
                <div><span>Stock Value</span><strong>Rs. {{ money(dashboard.total_stock_value) }}</strong></div><div><span>Saleable Qty</span><strong>{{ qty(dashboard.total_saleable_quantity) }}</strong></div><div><span>Low Stock</span><strong>{{ dashboard.low_stock_items || 0 }}</strong></div><div><span>Out of Stock</span><strong>{{ dashboard.out_of_stock_items || 0 }}</strong></div><div><span>Near Expiry</span><strong>{{ dashboard.near_expiry_items || 0 }}</strong></div><div><span>Expired</span><strong>{{ dashboard.expired_items || 0 }}</strong></div><div><span>In Transit</span><strong>{{ qty(dashboard.stock_in_transit) }}</strong></div><div><span>Pending Counts</span><strong>{{ dashboard.pending_stock_counts || 0 }}</strong></div>
            </section>

            <section v-if="!loading && tab === 'voucher'" class="panel">
                <div class="section-head"><div><h2>{{ currentVoucherType.label }}</h2><p>Draft does not update stock. Posting creates immutable stock ledger entries.</p></div><span class="status-pill">{{ adjustment.status }}</span></div>
                <div class="form-grid"><input value="Auto generated on save" disabled /><select v-model="adjustment.branch_id"><option value="">Branch</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select><select v-model="adjustment.warehouse_id"><option value="">Source Warehouse</option><option v-for="w in filteredWarehouses(adjustment.branch_id)" :key="w.id" :value="w.id">{{ w.name }}</option></select><input v-model="adjustment.adjustment_date" type="date" /><select v-model="adjustment.adjustment_reason_id" @change="applyReason"><option value="">Reason</option><option v-for="r in refs.reasons" :key="r.id" :value="r.id">{{ r.reason_name }}</option></select><select v-model="adjustment.adjustment_type"><option value="increase">Increase</option><option value="decrease">Decrease</option><option value="mixed">Mixed</option></select><input :value="currentVoucherType.label" disabled /><textarea v-model="adjustment.remarks" placeholder="Remarks / reason note"></textarea></div>
                <div class="hint-grid">
                    <span>Voucher number is generated when the voucher is saved.</span>
                    <span>Branch and source warehouse decide where stock is affected.</span>
                    <span>Reason is required before posting and is used for audit reports.</span>
                    <span>Draft vouchers remain editable and do not update stock.</span>
                </div>
                <div class="line-head"><span>Product</span><span>Current</span><span>Direction</span><span>Qty</span><span>Cost</span><span>Location</span><span>Condition</span><span></span></div>
                <div v-for="(item, i) in adjustment.items" :key="i" class="line-grid adjustment-row"><select v-model="item.product_id" @change="refreshLineStock(item)"><option value="">Product</option><option v-for="p in refs.products" :key="p.id" :value="p.id">{{ p.name }} - {{ p.sku }}</option></select><input :value="qty(item.current_stock)" disabled /><select v-model="item.direction"><option value="in">IN</option><option value="out">OUT</option></select><input v-model.number="item.adjustment_quantity" type="number" step="0.001" /><input v-model.number="item.unit_cost" type="number" step="0.01" /><input v-model="item.warehouse_location" placeholder="Location" /><select v-model="item.condition_status"><option>saleable</option><option>damaged</option><option>expired</option><option>defective</option><option>quarantined</option><option>lost</option></select><button @click="adjustment.items.splice(i,1)" :disabled="adjustment.items.length === 1">Remove</button></div>
                <section class="voucher-help-card">
                    <strong>Line guidance</strong>
                    <ul>
                        <li>Current stock is read-only and comes from the stock ledger.</li>
                        <li>IN increases stock; OUT decreases stock and cannot exceed available quantity.</li>
                        <li>Cost is used only for this voucher valuation and does not update Product Master price.</li>
                        <li>Location and condition help separate saleable, damaged, expired or lost stock.</li>
                    </ul>
                </section>
                <div class="actions"><button @click="addRow(adjustment.items, { product_id: '', unit_id: '', adjustment_quantity: 1, direction: 'in', unit_cost: 0, warehouse_location: '', condition_status: 'saleable', reason: '' })">Add Item</button><button :disabled="saving" @click="saveAdjustment('draft')">Save Draft</button><button :disabled="saving" @click="saveAdjustment('posted')">Post</button></div>
            </section>

            <section v-if="!loading && tab === 'register'" class="panel">
                <div class="section-head"><div><h2>Voucher Register</h2><p>Search, filter, view, post and cancel inventory stock operation vouchers.</p></div></div>
                <div class="form-grid filters"><input v-model="registerFilters.search" placeholder="Voucher no, product, reason, remarks" /><select v-model="registerFilters.voucher_type"><option value="">All Types</option><option value="manual">Stock Adjustment</option><option value="damage">Damage</option><option value="expired_stock">Expired</option><option value="production_consumption">Production Consumption</option><option value="production_output">Production Output</option></select><select v-model="registerFilters.status"><option value="">All Status</option><option>draft</option><option>posted</option><option>reversed</option><option>cancelled</option></select><select v-model="registerFilters.branch_id"><option value="">All Branches</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select><select v-model="registerFilters.warehouse_id"><option value="">All Warehouses</option><option v-for="w in refs.warehouses" :key="w.id" :value="w.id">{{ w.name }}</option></select><input v-model="registerFilters.date_from" type="date" /><input v-model="registerFilters.date_to" type="date" /><button @click="load">Apply</button></div>
                <div class="table-wrapper"><table><thead><tr><th>Voucher No</th><th>Type</th><th>Date</th><th>Branch</th><th>Warehouse</th><th>Items</th><th>Quantity</th><th>Status</th><th>Actions</th></tr></thead><tbody><tr v-for="row in registerRows" :key="row.id"><td>{{ row.number }}</td><td>{{ row.type }}</td><td>{{ row.date }}</td><td>{{ row.branch }}</td><td>{{ row.warehouse }}</td><td>{{ row.items }}</td><td>{{ qty(row.quantity) }}</td><td><span class="status-pill">{{ row.status }}</span></td><td><button @click="tab = 'reports'; activeReport = 'movement_report'">Ledger</button><button v-if="permissions.approve && row.action === 'adjustment' && ['draft','submitted','approved'].includes(row.status)" @click="postAdjustment(row.raw)">Post</button><button v-if="permissions.approve && row.action === 'transfer' && ['approved','draft','submitted'].includes(row.status)" @click="dispatchTransfer(row.raw)">Dispatch</button><button v-if="permissions.approve && row.action === 'transfer' && ['dispatched','partially_received'].includes(row.status)" @click="receiveTransfer(row.raw)">Receive</button><button v-if="permissions.approve && row.action === 'count' && row.status !== 'posted'" @click="postVariance(row.raw)">Post Variance</button><button v-if="permissions.cancel && row.action === 'adjustment' && row.status === 'posted'" @click="reverseAdjustment(row.raw)">Cancel</button><button v-if="permissions.print" @click="printPage">Print</button></td></tr><tr v-if="!registerRows.length"><td colspan="9" class="empty">No inventory vouchers found.</td></tr></tbody></table></div>
            </section>

            <section v-if="tab === 'counts'" class="panel">
                <div class="form-grid"><select v-model="count.branch_id"><option value="">Branch</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select><select v-model="count.warehouse_id"><option value="">Warehouse</option><option v-for="w in filteredWarehouses(count.branch_id)" :key="w.id" :value="w.id">{{ w.name }}</option></select><input v-model="count.count_date" type="date" /><select v-model="count.count_type"><option>full</option><option>cycle_count</option><option>category</option><option>brand</option><option>location</option><option>selected_products</option></select><label><input v-model="count.freeze_stock" type="checkbox" /> Freeze</label><textarea v-model="count.remarks" placeholder="Remarks"></textarea></div>
                <div class="hint-grid"><span>System Qty is read from current stock and remains read-only.</span><span>Physical Qty is the quantity counted by the user.</span><span>Difference = Physical Qty - System Qty.</span><span>Adjustment Qty is posted only after approval.</span></div>
                <div class="line-head count-head"><span>Product</span><span>System Qty</span><span>Physical Qty</span><span>Difference</span><span>Adjustment Qty</span><span>Cost</span><span>Location</span><span>Status</span><span></span></div>
                <div v-for="(item, i) in count.items" :key="i" class="line-grid count-row"><select v-model="item.product_id" @change="refreshLineStock(item, count)"><option value="">Product</option><option v-for="p in refs.products" :key="p.id" :value="p.id">{{ p.name }}</option></select><input :value="qty(item.system_quantity || item.current_stock)" disabled /><input v-model.number="item.counted_quantity" type="number" step="0.001" placeholder="Physical" /><input :value="qty(countDifference(item))" disabled /><input :value="qty(adjustmentQty(item))" disabled /><input v-model.number="item.unit_cost" type="number" step="0.01" placeholder="Cost" /><input v-model="item.warehouse_location" placeholder="Location" /><select v-model="item.review_status"><option>pending</option><option>accepted</option><option>rejected</option><option>recount_required</option></select><button @click="count.items.splice(i,1)" :disabled="count.items.length === 1">Remove</button></div>
                <div class="actions"><button @click="addRow(count.items, { product_id: '', system_quantity: 0, counted_quantity: 0, unit_cost: 0, warehouse_location: '', review_status: 'accepted' })">Add Line</button><button :disabled="saving" @click="saveCount('draft')">Save</button><button :disabled="saving" @click="saveCount('approved')">Approve</button></div>
                <div class="table-wrapper"><table><tbody><tr v-for="c in counts" :key="c.id"><td>{{ c.session_number }}</td><td>{{ c.count_date }}</td><td>{{ c.warehouse?.name }}</td><td>{{ c.status }}</td><td><button @click="postVariance(c)">Post Variance</button></td></tr></tbody></table></div>
            </section>

            <section v-if="tab === 'transfers'" class="panel">
                <div class="form-grid"><select v-model="transfer.source_branch_id"><option value="">Source Branch</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select><select v-model="transfer.source_warehouse_id"><option value="">Source Warehouse</option><option v-for="w in filteredWarehouses(transfer.source_branch_id)" :key="w.id" :value="w.id">{{ w.name }}</option></select><select v-model="transfer.destination_branch_id"><option value="">Destination Branch</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select><select v-model="transfer.destination_warehouse_id"><option value="">Destination Warehouse</option><option v-for="w in filteredWarehouses(transfer.destination_branch_id)" :key="w.id" :value="w.id">{{ w.name }}</option></select><input v-model="transfer.transfer_date" type="date" /><select v-model="transfer.transfer_type"><option>immediate</option><option>dispatch_receive</option><option>inter_branch</option><option>inter_warehouse</option></select></div>
                <div class="hint-grid"><span>Source branch and warehouse must contain enough available stock.</span><span>Destination warehouse must be different and belong to the selected branch.</span><span>Batch and serial fields are optional unless the product requires them.</span><span>Posting runs in one database transaction with OUT and IN ledger entries.</span></div>
                <div class="line-head transfer-head"><span>Product</span><span>Current</span><span>Qty</span><span>Approved</span><span>Cost</span><span>Source Batch</span><span>Dest Batch</span><span>Source Serial</span><span>Dest Serial</span><span>From</span><span>To</span><span></span></div>
                <div v-for="(item, i) in transfer.items" :key="i" class="line-grid transfer-row"><select v-model="item.product_id" @change="refreshLineStock(item, { branch_id: transfer.source_branch_id, warehouse_id: transfer.source_warehouse_id })"><option value="">Product</option><option v-for="p in refs.products" :key="p.id" :value="p.id">{{ p.name }}</option></select><input :value="qty(item.current_stock)" disabled /><input v-model.number="item.requested_quantity" type="number" step="0.001" /><input v-model.number="item.approved_quantity" type="number" step="0.001" placeholder="Approved" /><input v-model.number="item.unit_cost" type="number" step="0.01" /><input v-model="item.source_batch_id" placeholder="Batch ID" /><input v-model="item.destination_batch_id" placeholder="Batch ID" /><input v-model="item.source_serial_id" placeholder="Serial ID" /><input v-model="item.destination_serial_id" placeholder="Serial ID" /><input v-model="item.source_location" placeholder="From location" /><input v-model="item.destination_location" placeholder="To location" /><button @click="transfer.items.splice(i,1)" :disabled="transfer.items.length === 1">Remove</button></div>
                <div class="actions"><button @click="addRow(transfer.items, { product_id: '', requested_quantity: 1, approved_quantity: '', unit_cost: 0, source_batch_id: '', destination_batch_id: '', source_serial_id: '', destination_serial_id: '', source_location: '', destination_location: '' })">Add Item</button><button :disabled="saving" @click="saveTransfer('draft')">Save Draft</button><button :disabled="saving" @click="saveTransfer(transfer.transfer_type === 'immediate' ? 'approved' : 'dispatched')">Post</button></div>
                <div class="table-wrapper"><table><thead><tr><th>No.</th><th>Source</th><th>Destination</th><th>Type</th><th>Status</th><th></th></tr></thead><tbody><tr v-for="t in transfers" :key="t.id"><td>{{ t.voucher_number }}</td><td>{{ t.source_warehouse?.name }}</td><td>{{ t.destination_warehouse?.name }}</td><td>{{ t.transfer_type }}</td><td>{{ t.status }}</td><td><button v-if="['approved','draft','submitted'].includes(t.status)" @click="dispatchTransfer(t)">Dispatch</button><button v-if="['dispatched','partially_received'].includes(t.status)" @click="receiveTransfer(t)">Receive</button></td></tr></tbody></table></div>
            </section>

            <section v-if="tab === 'locations'" class="panel">
                <div class="section-head"><div><h2>Warehouse Locations</h2><p>Manage reusable warehouse locations as Warehouse -> Rack -> Shelf -> Bin.</p></div></div>
                <div class="form-grid"><select v-model="locationMaster.branch_id"><option value="">Branch</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select><select v-model="locationMaster.warehouse_id"><option value="">Warehouse</option><option v-for="w in filteredWarehouses(locationMaster.branch_id)" :key="w.id" :value="w.id">{{ w.name }}</option></select><input v-model="locationMaster.zone" placeholder="Zone" /><input v-model="locationMaster.aisle" placeholder="Aisle" /><input v-model="locationMaster.rack" placeholder="Rack" /><input v-model="locationMaster.shelf" placeholder="Shelf" /><input v-model="locationMaster.bin" placeholder="Bin" /><select v-model="locationMaster.status"><option>active</option><option>inactive</option><option>blocked</option></select></div>
                <div class="hint-grid"><span>Warehouse is the storage building selected from master data.</span><span>Rack groups shelves inside a warehouse aisle or zone.</span><span>Shelf is the level inside a rack.</span><span>Bin is the smallest picking/storage location.</span></div>
                <div class="actions"><button :disabled="saving" @click="saveWarehouseLocation">{{ locationMaster.id ? 'Update Location' : 'Save Location' }}</button></div>
                <div class="table-wrapper"><table><thead><tr><th>Warehouse</th><th>Zone</th><th>Aisle</th><th>Rack</th><th>Shelf</th><th>Bin</th><th>Status</th><th>Actions</th></tr></thead><tbody><tr v-for="loc in warehouseLocations" :key="loc.id"><td>{{ loc.warehouse?.name || '-' }}</td><td>{{ loc.zone || '-' }}</td><td>{{ loc.aisle || '-' }}</td><td>{{ loc.rack }}</td><td>{{ loc.shelf }}</td><td>{{ loc.bin }}</td><td><span class="status-pill">{{ loc.status }}</span></td><td><button @click="editWarehouseLocation(loc)">Edit</button></td></tr><tr v-if="!warehouseLocations.length"><td colspan="8" class="empty">No warehouse locations found.</td></tr></tbody></table></div>
                <div class="section-head secondary"><div><h2>Location Movement History</h2><p>Posted movement vouchers stay immutable and remain available for audit.</p></div></div>
                <div class="table-wrapper"><table><thead><tr><th>Voucher</th><th>Warehouse</th><th>Date</th><th>Items</th><th>Status</th></tr></thead><tbody><tr v-for="m in movements" :key="m.id"><td>{{ m.voucher_number }}</td><td>{{ m.warehouse?.name }}</td><td>{{ m.movement_date }}</td><td>{{ m.items?.length || 0 }}</td><td>{{ m.status }}</td></tr></tbody></table></div>
            </section>

            <section v-if="tab === 'reasons'" class="panel"><div class="form-grid"><input v-model="reason.reason_code" placeholder="Code" /><input v-model="reason.reason_name" placeholder="Name" /><select v-model="reason.default_direction"><option>in</option><option>out</option></select><select v-model="reason.default_condition_status"><option>saleable</option><option>damaged</option><option>expired</option><option>defective</option><option>quarantined</option><option>lost</option></select><select v-model="reason.status"><option>active</option><option>inactive</option></select><button :disabled="saving" @click="saveReason">Save Reason</button></div><div class="table-wrapper"><table><tbody><tr v-for="r in reasons" :key="r.id"><td>{{ r.reason_code }}</td><td>{{ r.reason_name }}</td><td>{{ r.default_direction }}</td><td>{{ r.default_condition_status }}</td><td>{{ r.status }}</td></tr></tbody></table></div></section>

            <section v-if="tab === 'reports'" class="panel">
                <div class="section-head"><div><h2>Inventory Reports</h2><p>Ledger-backed stock movement, valuation, branch, warehouse, adjustment, transfer, damage and expiry reports.</p></div><div class="actions inline"><button @click="exportRows('csv')">CSV</button><button @click="exportRows('excel')">Excel</button><button @click="exportRows('pdf')">PDF</button></div></div>
                <div class="tabs report-tabs"><button v-for="report in reportTabs" :key="report.key" :class="{active: activeReport === report.key}" @click="activeReport = report.key">{{ report.label }}</button></div>
                <div class="table-wrapper"><table><thead><tr><th>Date</th><th>Type</th><th>Product / Voucher</th><th>Branch</th><th>Warehouse</th><th>In</th><th>Out</th><th>Qty</th><th>Value</th><th>Status</th></tr></thead><tbody><tr v-for="(row, index) in currentReportRows" :key="row.id || index"><td>{{ row.transaction_date || row.adjustment_date || row.transfer_date || row.expiry_date || '-' }}</td><td>{{ row.transaction_type || row.source || row.transfer_type || activeReport }}</td><td>{{ row.product?.name || row.product_name || row.voucher_number || row.name || '-' }}</td><td>{{ row.branch?.name || row.branch_name || row.source_branch?.name || '-' }}</td><td>{{ row.warehouse?.name || row.warehouse_name || row.source_warehouse?.name || '-' }}</td><td>{{ qty(row.quantity_in) }}</td><td>{{ qty(row.quantity_out) }}</td><td>{{ qty(row.quantity_available || row.total_quantity_in || row.total_quantity_out) }}</td><td>Rs. {{ money(row.stock_value || row.total_value_in || row.total_value_out) }}</td><td>{{ row.status || row.stock_status || '-' }}</td></tr><tr v-if="!currentReportRows.length"><td colspan="10" class="empty">No report data found.</td></tr></tbody></table></div>
            </section>
        </div>
    </Layout>
</template>

<style scoped>
.inventory-control{padding:0 0 28px}.page-toolbar,.tabs,.actions{display:flex;align-items:center;gap:12px}.page-toolbar{justify-content:flex-end;margin:-6px 0 12px}.voucher-selector{align-items:center;background:#fff;border:1px solid #dfe6ef;border-radius:8px;display:flex;gap:10px;margin-bottom:12px;padding:12px}.voucher-selector label{color:#69758a;font-size:10px;font-weight:800;text-transform:uppercase}.voucher-selector span{color:#758197;font-size:12px}.tabs{flex-wrap:wrap;margin-bottom:14px}.tabs button.active{background:#173b77;color:#fff;border-color:#173b77}.panel{margin-bottom:18px;padding:18px;background:#fff;border:1px solid #dfe6ef;border-radius:8px}.section-head{align-items:flex-start;display:flex;justify-content:space-between;margin-bottom:14px}.section-head h2{color:#142139;font-size:18px;margin:0}.section-head p{color:#758197;font-size:12px;margin:4px 0 0}.status-pill{background:#edf2ff;border-radius:7px;color:#2457d6;display:inline-flex;font-size:10px;font-weight:800;padding:5px 8px;text-transform:capitalize}.cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.cards div{padding:14px;border:1px solid #edf1f5;border-radius:8px}.cards span{display:block;color:#69758a;font-size:11px}.cards strong{display:block;margin-top:6px;color:#142139;font-size:18px}.form-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:10px}.hint-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:0 0 16px}.hint-grid span,.voucher-help-card{background:#f8fafc;border:1px dashed #d9e2ef;border-radius:8px;color:#6f7b90;font-size:11px;font-weight:650;line-height:1.45;padding:9px 11px}.voucher-help-card{margin:10px 0 12px}.voucher-help-card strong{color:#27344c;display:block;font-size:12px;margin-bottom:6px}.voucher-help-card ul{margin:0;padding-left:17px}.voucher-help-card li{margin:3px 0}.line-head,.line-grid{display:grid;gap:8px;align-items:center;margin-bottom:8px}.line-head{color:#69758a;font-size:10px;font-weight:800;text-transform:uppercase}.adjustment-row,.line-head{grid-template-columns:1.5fr .7fr .7fr .7fr .7fr 1fr 1fr .7fr}.count-row,.count-head{grid-template-columns:1.5fr .75fr .75fr .75fr .75fr .7fr .9fr .9fr .65fr}.transfer-row,.transfer-head{grid-template-columns:1.4fr .65fr .65fr .65fr .65fr .8fr .8fr .8fr .8fr .9fr .9fr .65fr}.location-row{grid-template-columns:1.8fr .8fr 1fr 1fr .7fr}.actions{justify-content:flex-end;flex-wrap:wrap;margin:12px 0}.actions.inline{margin:0}.secondary{border-top:1px solid #edf1f5;margin-top:20px;padding-top:16px}.report-tabs{margin-bottom:10px}input,select,textarea,button{min-height:38px;padding:8px 10px;color:#344159;background:#fff;border:1px solid #d8e0eb;border-radius:8px;font-size:12px}textarea{min-height:38px}button{font-weight:750;cursor:pointer}.alert{padding:10px 12px;margin-bottom:12px;border-radius:8px;background:#fff4f4;color:#b42318;border:1px solid #ffd5d5;font-size:12px}.empty{color:#8490a2;text-align:center}.table-wrapper{overflow-x:auto}table{width:100%;border-collapse:collapse;margin-top:12px}th,td{padding:11px 10px;border-bottom:1px solid #edf1f5;text-align:left;white-space:nowrap;font-size:12px}th{color:#69758a;background:#f8fafc;font-size:10px;text-transform:uppercase}@media(max-width:1000px){.cards,.form-grid,.hint-grid,.line-grid,.line-head,.adjustment-row,.count-row,.transfer-row,.location-row{grid-template-columns:1fr}.page-toolbar{justify-content:flex-start}.voucher-selector{align-items:flex-start;flex-direction:column}}
</style>
