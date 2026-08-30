<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import Layout from '../Layout.vue';
import InventoryApi from './InventoryApi';
import AppToast from '../../Components/Common/AppToast.vue';
import TableLoadingState from '../../Components/Common/TableLoadingState.vue';
import { formatInventoryDateTime, formatInventoryQty } from './Shared/formatters';

const props = defineProps({ page: { type: String, default: 'inventory' }, title: { type: String, default: 'Inventory Control' }, initial_tab: { type: String, default: 'dashboard' } });

const tab = ref(props.initial_tab === 'adjustments' ? 'voucher' : props.initial_tab);
const showOperationSelector = ref(false);
const barcodeScan = ref('');
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
const tabs = [
    { key: 'dashboard', label: 'Overview' },
    { key: 'register', label: 'Movement History' },
    { key: 'voucher', label: 'Adjustments' },
    { key: 'transfers', label: 'Transfers' },
    { key: 'counts', label: 'Stock Counts' },
    { key: 'locations', label: 'Locations' },
    { key: 'reasons', label: 'Reasons' },
    { key: 'reports', label: 'Reports' },
];
const voucherType = ref(props.initial_tab === 'transfers' ? 'stock_transfer' : props.initial_tab === 'counts' ? 'stock_count' : 'stock_adjustment');
const registerFilters = reactive({ search: '', status: '', branch_id: '', warehouse_id: '', voucher_type: '', date_from: '', date_to: '', reason: '' });
const permissions = reactive({ create: true, edit_draft: true, approve: true, cancel: true, print: true });
const activeReport = ref('movement_report');
const transferDrawerOpen = ref(false);
const editingTransferIndex = ref(null);
const transferView = ref('new');
const transferProductSearch = ref('');
const countDrawerOpen = ref(false);
const editingCountIndex = ref(null);
const countView = ref('new');
const countProductSearch = ref('');

const today = new Date().toISOString().slice(0, 10);
const adjustment = reactive({ branch_id: '', warehouse_id: '', adjustment_date: today, adjustment_reason_id: '', adjustment_type: 'mixed', source: 'manual', status: 'draft', remarks: '', items: [{ product_id: '', unit_id: '', adjustment_quantity: 1, direction: 'in', unit_cost: 0, warehouse_location: '', condition_status: 'saleable', source_condition_status: 'damaged', destination_condition_status: 'saleable', reason: '' }] });
const count = reactive({ id: null, branch_id: '', warehouse_id: '', count_date: today, count_type: 'full', freeze_stock: false, status: 'draft', remarks: '', items: [] });
const countItem = reactive({ product_id: '', product_variant_id: '', product_name: '', sku: '', barcode: '', unit: '', system_quantity: 0, counted_quantity: '', unit_cost: 0, warehouse_location: '', batch_id: '', serial_id: '', reason: '', review_status: 'accepted' });
const countFilters = reactive({ search: '', branch_id: '', warehouse_id: '', status: '', count_type: '', date_from: '', date_to: '' });
const transfer = reactive({ id: null, transfer_date: today, source_branch_id: '', source_warehouse_id: '', destination_branch_id: '', destination_warehouse_id: '', transfer_type: 'immediate', expected_delivery_date: '', status: 'draft', remarks: '', items: [] });
const transferItem = reactive({ product_id: '', product_variant_id: '', product_name: '', sku: '', barcode: '', unit: '', requested_quantity: 1, approved_quantity: '', unit_cost: 0, source_batch_id: '', destination_batch_id: '', source_serial_id: '', destination_serial_id: '', source_location: '', destination_location: '', current_stock: 0, destination_stock: 0, source_before: 0, source_after: 0, destination_before: 0, destination_after: 0 });
const transferFilters = reactive({ search: '', source_branch_id: '', destination_branch_id: '', status: '', date_from: '', date_to: '', transfer_type: '' });
const location = reactive({ branch_id: '', warehouse_id: '', movement_date: today, status: 'draft', remarks: '', items: [{ product_id: '', quantity: 1, from_location: '', to_location: '' }] });
const locationMaster = reactive({ id: null, branch_id: '', warehouse_id: '', zone: '', aisle: '', rack: '', shelf: '', bin: '', status: 'active' });
const reason = reactive({ id: null, reason_code: '', reason_name: '', default_direction: 'out', default_condition_status: 'saleable', accounting_account_id: '', approval_required: true, status: 'active' });

const filteredWarehouses = (branchId) => !branchId ? refs.value.warehouses : refs.value.warehouses.filter((w) => Number(w.branch_id || 0) === Number(branchId));
const warehouseName = (id) => refs.value.warehouses.find((w) => Number(w.id) === Number(id))?.name || 'selected warehouse';
const money = (v) => Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const qty = formatInventoryQty;
const capture = (e) => { errors.value = e?.response?.data?.errors || { form: [e?.response?.data?.message || 'Unable to save.'] }; };
const clearErrors = () => { errors.value = {}; };
const selectedReason = computed(() => refs.value.reasons.find((r) => Number(r.id) === Number(adjustment.adjustment_reason_id)));
const applyReason = () => {
    if (!selectedReason.value) return;
    adjustment.items.forEach((item) => {
        if (isConditionTransferLine(item)) {
            item.condition_status = item.destination_condition_status || 'saleable';
            return;
        }
        item.direction = selectedReason.value.default_direction;
        item.condition_status = item.condition_status || selectedReason.value.default_condition_status || 'saleable';
        syncOutboundCondition(item);
    });
    refreshAdjustmentStocks();
};
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
const countTypeOptions = [
    { value: 'full', label: 'Full Count' },
    { value: 'cycle_count', label: 'Cycle Count' },
    { value: 'selected_products', label: 'Spot Count' },
    { value: 'category', label: 'Category Count' },
    { value: 'brand', label: 'Brand Count' },
    { value: 'location', label: 'Location Count' },
];
const countStatusLabel = (value) => ({ draft: 'Draft', assigned: 'Assigned', counting: 'Counting', submitted: 'Pending Approval', reviewed: 'Reviewed', approved: 'Approved', posted: 'Posted' }[value] || labelize(value));
const countLineDifference = (item) => Number(item.counted_quantity === '' || item.counted_quantity === null ? 0 : item.counted_quantity) - Number(item.system_quantity || 0);
const countExpectedAfter = (item) => Number(item.system_quantity || 0) + countLineDifference(item);
const countAdjustmentLabel = (item) => countLineDifference(item) > 0 ? `Stock In ${qty(countLineDifference(item))}` : countLineDifference(item) < 0 ? `Stock Out ${qty(Math.abs(countLineDifference(item)))}` : 'No stock adjustment required';
const countLineTone = (item) => countLineDifference(item) > 0 ? 'positive' : countLineDifference(item) < 0 ? 'negative' : 'neutral';

const labelize = (value) => String(value || '-').replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
const formatIndianDateTime = formatInventoryDateTime;
const movementOptions = [{ value: 'in', label: 'Stock In' }, { value: 'out', label: 'Stock Out' }, { value: 'transfer', label: 'Condition Transfer' }];
const conditionOptions = [
    { value: 'saleable', label: 'Saleable' },
    { value: 'damaged', label: 'Damaged' },
    { value: 'expired', label: 'Expired' },
    { value: 'defective', label: 'Defective' },
    { value: 'quarantined', label: 'Quarantined' },
    { value: 'lost', label: 'Lost' },
];
const statusOptions = [{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }];
const optionLabel = (options, value, labelKey = 'label', valueKey = 'value') => {
    const option = options.find((row) => Number(row[valueKey] ?? row.id) === Number(value) || row[valueKey] === value);
    return option?.[labelKey] || option?.label || option?.name || labelize(value);
};
const reasonDisplayName = (row) => row ? `${row.reason_code} - ${row.reason_name}` : '';
const resetReasonForm = () => Object.assign(reason, { id: null, reason_code: '', reason_name: '', default_direction: 'out', default_condition_status: 'saleable', accounting_account_id: '', approval_required: true, status: 'active' });
const editReason = (row) => Object.assign(reason, {
    id: row.id,
    reason_code: row.reason_code || '',
    reason_name: row.reason_name || '',
    default_direction: row.default_direction || 'out',
    default_condition_status: row.default_condition_status || 'saleable',
    accounting_account_id: row.accounting_account_id || '',
    approval_required: row.approval_required ?? true,
    status: row.status || 'active',
});
const signedQty = (value) => `${Number(value || 0) > 0 ? '+' : ''}${qty(value)}`;
const activityQty = (row) => row.action === 'transfer' || row.action === 'location' || row.action === 'count' ? qty(row.quantity) : signedQty(row.quantity);
const isConditionTransferLine = (item) => item.direction === 'transfer';
const stockDelta = (item) => item.direction === 'out' ? -Number(item.adjustment_quantity || 0) : (item.direction === 'in' ? Number(item.adjustment_quantity || 0) : 0);
const lineNewStock = (item) => Number(item.current_stock || 0) + stockDelta(item);
const adjustmentTypeLabel = (value) => optionLabel(movementOptions, value);
const conditionName = (value) => optionLabel(conditionOptions, value);
const lineSourceQty = (item) => Number(item.source_condition_quantity ?? 0);
const lineDestinationQty = (item) => Number(item.destination_condition_quantity ?? 0);
const lineStockCondition = (item) => item.condition_status || 'saleable';
const syncOutboundCondition = (item) => {
    if (!isConditionTransferLine(item) && item.direction === 'out') item.source_condition_status = lineStockCondition(item);
};
const conditionTransferResult = (item) => {
    if (!item.product_id || !isConditionTransferLine(item)) return '';
    const qtyValue = Number(item.adjustment_quantity || 0);
    const from = item.source_condition_status || 'saleable';
    const to = item.destination_condition_status || 'saleable';
    return `${conditionName(from)} ${qty(lineSourceQty(item) - qtyValue)} / ${conditionName(to)} ${qty(lineDestinationQty(item) + qtyValue)}`;
};
const adjustmentSummary = computed(() => {
    const increases = adjustment.items.filter((item) => stockDelta(item) > 0).reduce((sum, item) => sum + stockDelta(item), 0);
    const decreases = Math.abs(adjustment.items.filter((item) => stockDelta(item) < 0).reduce((sum, item) => sum + stockDelta(item), 0));
    const transfers = adjustment.items.filter((item) => isConditionTransferLine(item)).reduce((sum, item) => sum + Number(item.adjustment_quantity || 0), 0);
    return { products: adjustment.items.filter((item) => item.product_id).length, increases, decreases, transfers, net: increases - decreases };
});
const kpiCards = computed(() => [
    { key: 'stock-value', label: 'Saleable Value', value: `Rs. ${money(dashboard.value.total_stock_value)}`, note: 'Saleable inventory valuation' },
    { key: 'saleable', label: 'Saleable Stock', value: qty(dashboard.value.total_saleable_quantity), note: 'Units available for sale' },
    { key: 'damaged', label: 'Damaged Stock', value: qty(dashboard.value.damaged_quantity), note: 'Physically present, not saleable', tone: 'danger', action: () => openCurrentStock('damaged') },
    { key: 'expired-condition', label: 'Expired Stock', value: qty(dashboard.value.expired_quantity), note: 'Expired condition stock', tone: 'danger', action: () => openCurrentStock('expired') },
    { key: 'physical', label: 'Physical Stock', value: qty(dashboard.value.physical_quantity), note: 'Saleable plus condition stock', tone: 'info', action: () => openCurrentStock('physical') },
    { key: 'low', label: 'Low Stock', value: dashboard.value.low_stock_items || 0, note: 'Below reorder level', tone: 'warning', action: () => openCurrentStock('low_stock') },
    { key: 'out', label: 'Out of Stock', value: dashboard.value.out_of_stock_items || 0, note: 'Products needing replenishment', tone: 'danger', action: () => openCurrentStock('out_of_stock') },
    { key: 'near-expiry', label: 'Near Expiry', value: dashboard.value.near_expiry_items || 0, note: 'Next 30 days', tone: 'warning', action: () => openBatchReport('near_expiry') },
    { key: 'expired-batches', label: 'Expired Batches', value: dashboard.value.expired_items || 0, note: 'Already expired batches', tone: 'danger', action: () => openBatchReport('expired') },
    { key: 'transit', label: 'In Transit', value: qty(dashboard.value.stock_in_transit), note: 'Transfer quantity moving now', tone: 'info', action: () => switchTab('transfers') },
    { key: 'counts', label: 'Pending Counts', value: dashboard.value.pending_stock_counts || 0, note: 'Awaiting review or posting', tone: 'info', action: () => switchTab('counts') },
]);
const inventoryAlerts = computed(() => [
    { label: 'Out of Stock', products: dashboard.value.out_of_stock_items || 0, action: () => openCurrentStock('out_of_stock') },
    { label: 'Low Stock', products: dashboard.value.low_stock_items || 0, action: () => openCurrentStock('low_stock') },
    { label: 'Near Expiry', products: dashboard.value.near_expiry_items || 0, action: () => openBatchReport('near_expiry') },
    { label: 'Expired Stock', products: dashboard.value.expired_quantity || 0, action: () => openCurrentStock('expired') },
    { label: 'Expired Batches', products: dashboard.value.expired_items || 0, action: () => openBatchReport('expired') },
    { label: 'Damaged Stock', products: dashboard.value.damaged_quantity || 0, action: () => openCurrentStock('damaged') },
    { label: 'Pending Transfers', products: transfers.value.filter((row) => ['draft', 'approved', 'dispatched', 'partially_received'].includes(row.status)).length, action: () => switchTab('transfers') },
    { label: 'Count Variances', products: counts.value.filter((row) => row.status !== 'posted' && (row.items || []).some((item) => Number(item.variance_quantity || 0) !== 0)).length, action: () => switchTab('counts') },
].filter((alert) => Number(alert.products || 0) > 0));
const recentActivity = computed(() => registerRows.value.slice().sort((a, b) => String(b.date || '').localeCompare(String(a.date || ''))).slice(0, 8));
const operationCards = [
    { key: 'stock_adjustment', label: 'Stock Adjustment', tab: 'voucher', detail: 'Correct available stock for damaged, lost, found, expired or manual correction cases.', bullets: ['Increase or decrease stock', 'Reason and warehouse required', 'Posts immutable ledger entries'] },
    { key: 'stock_transfer', label: 'Stock Transfer', tab: 'transfers', detail: 'Move stock between branches, warehouses or locations with dispatch and receive stages.', bullets: ['Source to destination', 'Tracks in-transit stock', 'Supports partial receive'] },
    { key: 'stock_count', label: 'Stock Count', tab: 'counts', detail: 'Verify physical stock before posting approved count variances as adjustments.', bullets: ['Full or cycle count', 'System vs counted quantity', 'Approval before posting'] },
    { key: 'production_output', label: 'Stock In', tab: 'voucher', detail: 'Record controlled manual stock increases where business rules allow it.', bullets: ['Found stock', 'Opening correction', 'Positive ledger effect'] },
    { key: 'production_consumption', label: 'Stock Out', tab: 'voucher', detail: 'Issue inventory for internal use, wastage, samples or production consumption.', bullets: ['Stock decrease', 'Available stock validation', 'Reason-led audit trail'] },
    { key: 'location_movement', label: 'Location Movement', tab: 'locations', detail: 'Move products within the same warehouse from one rack, shelf or bin to another.', bullets: ['Rack A-01 to Rack B-04', 'Warehouse location history', 'No branch transfer needed'] },
];
const quickActions = [
    { label: 'Adjust Stock', key: 'stock_adjustment' },
    { label: 'Transfer Stock', key: 'stock_transfer' },
    { label: 'Start Stock Count', key: 'stock_count' },
    { label: 'Move Location', key: 'location_movement' },
    { label: 'Scan Product', key: 'scan' },
    { label: 'View Stock Ledger', key: 'ledger' },
];
const selectOperation = (operation) => {
    showOperationSelector.value = false;
    if (operation.key === 'location_movement') { tab.value = 'locations'; return; }
    voucherType.value = operation.key;
    setVoucherType();
};
const runQuickAction = (action) => {
    if (action.key === 'scan') { barcodeScan.value = ''; showToast('Barcode scanner is ready in the operation header.', 'success', 'Scan Product'); return; }
    if (action.key === 'ledger') { tab.value = 'reports'; activeReport.value = 'movement_report'; return; }
    const operation = operationCards.find((item) => item.key === action.key);
    if (operation) selectOperation(operation);
};
const openCurrentStock = (status) => { window.location.href = `/app/inventory/current-stock?stock_status=${status}`; };
const openBatchReport = (status) => { tab.value = 'reports'; activeReport.value = 'expiry_report'; showToast(`${labelize(status)} filter can be applied in the expiry report.`, 'success', 'Inventory Alerts'); };
const scanBarcode = () => {
    const code = barcodeScan.value.trim();
    if (!code) return;
    showToast(`Barcode ${code} captured for stock operation lookup.`, 'success', 'Scan Barcode');
    barcodeScan.value = '';
};
const exportDashboard = () => exportRows('csv');
const productNameForMovement = (row) => row.product_name || row.product?.name || row.raw?.product?.name || row.items?.[0]?.product?.name || '-';
const userNameForMovement = (row) => row.user_name || row.poster?.name || row.approver?.name || row.creator?.name || row.created_by?.name || row.approved_by?.name || (row.created_by || row.approved_by ? 'User' : 'System');
const referenceForMovement = (row) => row.reference_number || row.voucher_number || row.session_number || row.remarks || `Ledger #${row.id}`;
const adjustmentSignedQuantity = (row) => Number(row.total_quantity_in || 0) - Number(row.total_quantity_out || 0);
const adjustmentMovement = (row) => {
    if ((row.items?.length || 0) !== 1) return '';
    const item = row.items[0];
    if (item.direction === 'transfer') return `${conditionName(item.source_condition_status)} -> ${conditionName(item.destination_condition_status)}`;
    const condition = conditionName(item.source_condition_status || item.condition_status || 'saleable');
    return item.direction === 'out' ? `${condition} -> Out` : `In -> ${condition}`;
};
const postedLedgerAdjustmentReferences = computed(() => new Set(
    (reports.value.movement_report || [])
        .filter((row) => row.reference_type === 'App\\Models\\StockAdjustmentVoucher' && row.reference_id)
        .map((row) => Number(row.reference_id))
));
const registerRows = computed(() => [
    ...(reports.value.movement_report || []).map((row) => ({
        id: `ledger-${row.id}`,
        raw: row,
        type: row.transaction_type || 'stock_movement',
        number: referenceForMovement(row),
        date: row.transaction_date,
        branch: row.branch?.name || '-',
        warehouse: row.warehouse?.name || '-',
        items: 1,
        quantity: row.display_quantity ?? (Number(row.quantity_in || 0) - Number(row.quantity_out || 0)),
        physicalChange: row.physical_change ?? (Number(row.quantity_in || 0) - Number(row.quantity_out || 0)),
        movement: row.movement || '',
        status: 'posted',
        action: 'ledger',
        productName: productNameForMovement(row),
        userName: userNameForMovement(row),
    })),
    ...adjustments.value.filter((row) => row.status !== 'posted' || !postedLedgerAdjustmentReferences.value.has(Number(row.id))).map((row) => ({ id: `adjustment-${row.id}`, raw: row, type: row.reason?.reason_name || row.source || 'stock_adjustment', number: row.voucher_number, date: row.adjustment_date, branch: row.branch?.name || '-', warehouse: row.warehouse?.name || '-', items: row.items?.length || 0, quantity: adjustmentSignedQuantity(row), physicalChange: adjustmentSignedQuantity(row), movement: adjustmentMovement(row), status: row.status, action: 'adjustment', productName: row.items?.length === 1 ? productNameForMovement(row.items[0]) : `${row.items?.length || 0} Products`, userName: userNameForMovement(row) })),
    ...transfers.value.map((row) => ({ id: `transfer-${row.id}`, raw: row, type: row.transfer_type || 'stock_transfer', number: row.voucher_number, date: row.transfer_date, branch: `${row.source_branch?.name || '-'} -> ${row.destination_branch?.name || '-'}`, warehouse: `${row.source_warehouse?.name || '-'} -> ${row.destination_warehouse?.name || '-'}`, items: row.items?.length || 0, quantity: row.items?.reduce((sum, item) => sum + Number(item.approved_quantity || item.requested_quantity || 0), 0) || 0, status: row.status, action: 'transfer', productName: row.items?.length === 1 ? productNameForMovement(row.items[0]) : `${row.items?.length || 0} Products`, userName: userNameForMovement(row) })),
    ...counts.value.map((row) => ({ id: `count-${row.id}`, raw: row, type: 'stock_count', number: row.session_number, date: row.count_date, branch: row.branch?.name || '-', warehouse: row.warehouse?.name || '-', items: row.items?.length || 0, quantity: row.items?.reduce((sum, item) => sum + Math.abs(Number(item.variance_quantity || 0)), 0) || 0, status: row.status, action: 'count', productName: row.items?.length === 1 ? productNameForMovement(row.items[0]) : `${row.items?.length || 0} Products`, userName: userNameForMovement(row) })),
    ...movements.value.map((row) => ({ id: `movement-${row.id}`, raw: row, type: 'location_movement', number: row.voucher_number, date: row.movement_date, branch: row.branch?.name || '-', warehouse: row.warehouse?.name || '-', items: row.items?.length || 0, quantity: row.items?.reduce((sum, item) => sum + Number(item.quantity || 0), 0) || 0, status: row.status, action: 'location', productName: row.items?.length === 1 ? productNameForMovement(row.items[0]) : `${row.items?.length || 0} Products`, userName: userNameForMovement(row) })),
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
            if (item.direction === 'transfer') adjustment.adjustment_type = 'condition_transfer';
        });
        refreshAdjustmentStocks();
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

    const firstProduct = refs.value.products?.[0];
    transfer.items.forEach((item) => {
        if (!item.product_id && firstProduct) item.product_id = firstProduct.id;
    });
};

const load = async () => {
    loading.value = true;
    try {
        refs.value = await InventoryApi.controlReferences();
        applyDefaultSelections();
        dashboard.value = await InventoryApi.inventoryDashboard(registerFilters);
        adjustments.value = (await InventoryApi.stockAdjustments(registerFilters)).adjustments || [];
        counts.value = (await InventoryApi.stockCounts()).sessions || [];
        transfers.value = (await InventoryApi.stockTransfers()).transfers || [];
        movements.value = (await InventoryApi.locationTransfers()).movements || [];
        warehouseLocations.value = (await InventoryApi.warehouseLocations()).locations || [];
        reasons.value = (await InventoryApi.adjustmentReasons()).reasons || [];
        reports.value = await InventoryApi.inventoryReports(registerFilters);
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
    const transferBad = adjustment.items.find((item) => isConditionTransferLine(item) && (!item.source_condition_status || !item.destination_condition_status || item.source_condition_status === item.destination_condition_status));
    if (transferBad) return 'Condition transfer requires different From and To conditions.';
    const transferOver = adjustment.items.find((item) => isConditionTransferLine(item) && lineSourceQty(item) < Number(item.adjustment_quantity || 0));
    if (transferOver) return `Only ${qty(lineSourceQty(transferOver))} units are available in ${conditionName(transferOver.source_condition_status)} stock.`;
    const mismatchedReason = selectedReason.value && adjustment.items.find((item) => !isConditionTransferLine(item) && item.direction !== selectedReason.value.default_direction);
    if (mismatchedReason) return `${selectedReason.value.reason_name} requires ${optionLabel(movementOptions, selectedReason.value.default_direction)}.`;
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
const validateCountClient = (status = 'draft') => {
    if (!count.warehouse_id) return 'Warehouse is required.';
    if (status === 'draft' && !count.items.length) return '';
    if (!count.items.length) return 'Add at least one product to approve this count.';
    const missing = count.items.find((item) => !item.product_id || item.counted_quantity === '' || item.counted_quantity === null || Number(item.counted_quantity) < 0);
    if (missing) return 'Each count item needs a product and physical quantity of zero or more.';
    const reasonMissing = count.items.find((item) => countLineDifference(item) !== 0 && !item.reason);
    if (reasonMissing) return 'Variance reason is required when difference is not zero.';
    return '';
};
const countPayloadItems = () => count.items.map((i) => ({ ...i, counted_quantity: Number(i.counted_quantity), variance_quantity: countLineDifference(i), variance_value: Math.abs(countLineDifference(i)) * Number(i.unit_cost || 0), review_status: 'accepted', reviewer_notes: i.reason || '' }));
const saveCount = async (status) => {
    const message = validateCountClient(status);
    if (message) { errors.value = { form: [message] }; showToast(message, 'error', 'Validation'); return; }
    saving.value = true; clearErrors();
    try {
        count.status = status;
        const response = await InventoryApi.saveStockCount({ ...count, items: countPayloadItems() }, count.id);
        const savedSession = response.session || response;
        if (status === 'approved') {
            await InventoryApi.postCountVariance(savedSession.id);
            showToast('Count approved and variance posted.');
            resetCountForm();
        } else {
            count.id = savedSession.id || count.id;
            showToast('Count draft saved.');
        }
        await load();
    } catch (e) { capture(e); showToast(e?.response?.data?.message || 'Unable to save count.', 'error'); } finally { saving.value = false; }
};
const postVariance = async (row) => { saving.value = true; try { await InventoryApi.postCountVariance(row.id); showToast('Count variance posted.'); await load(); } catch (e) { capture(e); showToast(e?.response?.data?.message || 'Unable to post variance.', 'error'); } finally { saving.value = false; } };
const validateTransferClient = (status = 'draft') => {
    if (status === 'draft' && !transfer.items.length) return '';
    if (!transfer.source_warehouse_id || !transfer.destination_warehouse_id) return 'Source and destination warehouse are required.';
    if (Number(transfer.source_warehouse_id) === Number(transfer.destination_warehouse_id)) return 'Source and destination warehouse cannot be same.';
    if (!transfer.items.length) return 'At least one transfer item is required.';
    const bad = transfer.items.find((item) => transferLineError(item));
    if (bad) return transferLineError(bad);
    return '';
};
const saveTransfer = async (status) => { const message = validateTransferClient(status); if (message) { errors.value = { form: [message] }; showToast(message, 'error', 'Validation'); return; } saving.value = true; clearErrors(); try { transfer.status = status; await InventoryApi.saveStockTransfer({ ...transfer, items: transfer.items.map((i) => ({ ...i, approved_quantity: i.approved_quantity || i.requested_quantity })) }, transfer.id); showToast(status === 'draft' ? 'Transfer draft saved.' : 'Transfer posted.'); await load(); } catch (e) { capture(e); showToast(e?.response?.data?.message || 'Unable to save transfer.', 'error'); } finally { saving.value = false; } };
const dispatchTransfer = async (row) => { saving.value = true; try { await InventoryApi.dispatchStockTransfer(row.id); showToast('Transfer dispatched.'); await load(); } catch (e) { capture(e); showToast(e?.response?.data?.message || 'Unable to dispatch transfer.', 'error'); } finally { saving.value = false; } };
const receiveTransfer = async (row) => { saving.value = true; try { await InventoryApi.receiveStockTransfer(row.id, { items: row.items?.map((i) => ({ id: i.id, received_quantity: i.dispatched_quantity || i.approved_quantity || i.requested_quantity, rejected_quantity: 0 })) || [] }); showToast('Transfer received.'); await load(); } catch (e) { capture(e); showToast(e?.response?.data?.message || 'Unable to receive transfer.', 'error'); } finally { saving.value = false; } };
const saveLocation = async (status) => { saving.value = true; clearErrors(); try { location.status = status; await InventoryApi.saveLocationTransfer({ ...location, items: location.items.map((i) => ({ ...i })) }); showToast('Location movement posted.'); await load(); } catch (e) { capture(e); showToast(e?.response?.data?.message || 'Unable to post location movement.', 'error'); } finally { saving.value = false; } };
const saveWarehouseLocation = async () => { saving.value = true; clearErrors(); try { await InventoryApi.saveWarehouseLocation({ ...locationMaster }, locationMaster.id); Object.assign(locationMaster, { id: null, zone: '', aisle: '', rack: '', shelf: '', bin: '', status: 'active' }); showToast('Warehouse location saved.'); await load(); } catch (e) { capture(e); showToast(e?.response?.data?.message || 'Unable to save warehouse location.', 'error'); } finally { saving.value = false; } };
const editWarehouseLocation = (row) => Object.assign(locationMaster, { id: row.id, branch_id: row.branch_id || '', warehouse_id: row.warehouse_id || '', zone: row.zone || '', aisle: row.aisle || '', rack: row.rack || '', shelf: row.shelf || '', bin: row.bin || '', status: row.status || 'active' });
const saveReason = async () => { saving.value = true; clearErrors(); try { await InventoryApi.saveAdjustmentReason({ ...reason }, reason.id); resetReasonForm(); showToast('Reason saved.'); await load(); } catch (e) { capture(e); showToast(e?.response?.data?.message || 'Unable to save reason.', 'error'); } finally { saving.value = false; } };
const toggleReasonStatus = async (row) => {
    const nextStatus = row.status === 'active' ? 'inactive' : 'active';
    if (nextStatus === 'inactive' && !window.confirm(`Deactivate ${row.reason_name}? Existing vouchers will keep this reason for audit.`)) return;
    saving.value = true; clearErrors();
    try {
        await InventoryApi.saveAdjustmentReason({ ...row, status: nextStatus }, row.id);
        showToast(nextStatus === 'active' ? 'Reason activated.' : 'Reason deactivated.');
        await load();
    } catch (e) { capture(e); showToast(e?.response?.data?.message || 'Unable to update reason.', 'error'); } finally { saving.value = false; }
};
const seedDefaultReasons = async () => {
    if (!window.confirm('Create common stock adjustment reasons now?')) return;
    saving.value = true; clearErrors();
    try {
        const response = await InventoryApi.seedAdjustmentReasons();
        showToast(response.message || 'Default reasons created.');
        await load();
    } catch (e) { capture(e); showToast(e?.response?.data?.message || 'Unable to create default reasons.', 'error'); } finally { saving.value = false; }
};
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
    if (source === adjustment) syncOutboundCondition(item);
    const params = { branch_id: source.branch_id || '', warehouse_id: source.warehouse_id, product_id: item.product_id, product_variant_id: item.product_variant_id || '', batch_id: item.batch_id || item.source_batch_id || '' };
    if (source === adjustment && !isConditionTransferLine(item)) params.stock_status = lineStockCondition(item);
    const value = await InventoryApi.inventoryValuation(params);
    item.current_stock = Number(value.quantity || value.available || 0);
    if (source === count) item.system_quantity = Number(value.quantity || value.available || 0);
    if (!Number(item.unit_cost || 0)) item.unit_cost = Number(value.average_cost || 0);
    if (source === adjustment) await refreshConditionQuantities(item);
};
const productFor = (id) => refs.value.products?.find((product) => Number(product.id) === Number(id)) || {};
const productUnit = (product) => product?.unit?.name || product?.unit_name || product?.unit || product?.unit_code || 'PCS';
const productBarcode = (product) => product?.primary_barcode || product?.barcode || product?.barcodes?.[0]?.barcode || '';
const selectedCountProduct = computed(() => productFor(countItem.product_id));
const countProductResults = computed(() => {
    const q = countProductSearch.value.trim().toLowerCase();
    if (q.length < 2) return [];
    return (refs.value.products || []).filter((p) => `${p.name || ''} ${p.sku || ''} ${productBarcode(p)}`.toLowerCase().includes(q)).slice(0, 30);
});
const activeCountLocations = computed(() => activeLocationsForWarehouse(count.warehouse_id));
const countReasonOptions = computed(() => {
    const defaults = ['Physical Count Shortage', 'Physical Count Gain', 'Damaged', 'Missing / Lost', 'Found Stock', 'Counting Error', 'Other'];
    const master = (refs.value.reasons || []).map((reason) => reason.reason_name).filter(Boolean);
    return [...new Set([...master, ...defaults])];
});
const blankCountItem = () => ({ product_id: '', product_variant_id: '', product_name: '', sku: '', barcode: '', unit: '', system_quantity: 0, counted_quantity: '', unit_cost: 0, warehouse_location: '', batch_id: '', serial_id: '', reason: '', review_status: 'accepted' });
const refreshCountLineStock = async (item) => {
    if (!item.product_id || !count.warehouse_id) {
        item.system_quantity = 0;
        return;
    }
    await refreshLineStock(item, { branch_id: count.branch_id, warehouse_id: count.warehouse_id });
    item.system_quantity = Number(item.system_quantity || item.current_stock || 0);
};
const selectCountProduct = async (product) => {
    Object.assign(countItem, { ...blankCountItem(), product_id: product.id, product_name: product.name || '', sku: product.sku || '', barcode: productBarcode(product), unit: productUnit(product), counted_quantity: '' });
    countProductSearch.value = product.name || '';
    await refreshCountLineStock(countItem);
};
const countDuplicateIndex = (row) => count.items.findIndex((item, index) => index !== editingCountIndex.value
    && Number(item.product_id) === Number(row.product_id)
    && Number(item.product_variant_id || 0) === Number(row.product_variant_id || 0)
    && String(item.warehouse_location || '') === String(row.warehouse_location || '')
    && Number(item.batch_id || 0) === Number(row.batch_id || 0));
const countLineError = (item) => {
    if (!count.warehouse_id) return 'Choose a warehouse first.';
    if (!item.product_id) return 'Select a product.';
    if (item.counted_quantity === '' || item.counted_quantity === null) return 'Enter physical quantity.';
    if (Number(item.counted_quantity) < 0) return 'Physical quantity cannot be negative.';
    if (activeCountLocations.value.length && !item.warehouse_location) return 'Location is required.';
    if (isBatchTracked(item) && !item.batch_id) return 'Batch is required.';
    if (countLineDifference(item) !== 0 && !item.reason) return 'Variance reason is required.';
    return '';
};
const canAddCountItem = computed(() => Boolean(countItem.product_id) && !countLineError(countItem));
const countRowSnapshot = () => {
    const product = selectedCountProduct.value || {};
    return {
        ...blankCountItem(),
        ...countItem,
        product_id: Number(countItem.product_id),
        product_variant_id: countItem.product_variant_id || null,
        product_name: countItem.product_name || product.name || '',
        sku: countItem.sku || product.sku || '',
        barcode: countItem.barcode || productBarcode(product),
        unit: countItem.unit || productUnit(product),
        system_quantity: Number(countItem.system_quantity || 0),
        counted_quantity: Number(countItem.counted_quantity),
        variance_quantity: countLineDifference(countItem),
        variance_value: Math.abs(countLineDifference(countItem)) * Number(countItem.unit_cost || 0),
        review_status: 'accepted',
    };
};
const openCountDrawer = (item = null, index = null) => {
    Object.assign(countItem, blankCountItem(), item || {});
    countProductSearch.value = item?.product_name || '';
    editingCountIndex.value = index;
    countDrawerOpen.value = true;
    refreshCountLineStock(countItem);
};
const closeCountDrawer = () => {
    countDrawerOpen.value = false;
    editingCountIndex.value = null;
    countProductSearch.value = '';
    Object.assign(countItem, blankCountItem());
};
const addCountItemFromDrawer = () => {
    const message = countLineError(countItem);
    if (message) { errors.value = { form: [message] }; showToast(message, 'error', 'Validation'); return; }
    const row = countRowSnapshot();
    if (countDuplicateIndex(row) !== -1) {
        const duplicateMessage = 'This product is already included in this stock count.';
        errors.value = { form: [duplicateMessage] };
        showToast(duplicateMessage, 'error', 'Validation');
        return;
    }
    if (editingCountIndex.value === null) count.items.push(row);
    else count.items.splice(editingCountIndex.value, 1, row);
    closeCountDrawer();
};
const resetCountForm = () => {
    Object.assign(count, { id: null, branch_id: count.branch_id, warehouse_id: count.warehouse_id, count_date: today, count_type: 'full', freeze_stock: false, status: 'draft', remarks: '', items: [] });
    closeCountDrawer();
};
const editCount = async (row) => {
    Object.assign(count, {
        id: row.id || null,
        branch_id: row.branch_id || '',
        warehouse_id: row.warehouse_id || '',
        count_date: String(row.count_date || today).slice(0, 10),
        count_type: row.count_type || 'full',
        freeze_stock: Boolean(row.freeze_stock),
        status: row.status || 'draft',
        remarks: row.remarks || '',
        items: (row.items || []).map((item) => ({
            ...blankCountItem(),
            product_id: item.product_id || '',
            product_variant_id: item.product_variant_id || '',
            product_name: item.product?.name || '',
            sku: item.product?.sku || '',
            barcode: productBarcode(item.product || {}),
            unit: productUnit(item.product || {}),
            system_quantity: Number(item.system_quantity || 0),
            counted_quantity: item.counted_quantity ?? '',
            unit_cost: Number(item.unit_cost || 0),
            warehouse_location: item.warehouse_location || '',
            batch_id: item.batch_id || '',
            serial_id: item.serial_id || '',
            reason: item.reviewer_notes || item.reason || '',
            review_status: 'accepted',
        })),
    });
    countView.value = 'new';
};
const countSummary = computed(() => {
    const lines = count.items.filter((item) => item.product_id);
    const matched = lines.filter((item) => countLineDifference(item) === 0).length;
    const shortages = lines.filter((item) => countLineDifference(item) < 0);
    const gains = lines.filter((item) => countLineDifference(item) > 0);
    const systemTotal = lines.reduce((sum, item) => sum + Number(item.system_quantity || 0), 0);
    const physicalTotal = lines.reduce((sum, item) => sum + Number(item.counted_quantity || 0), 0);
    return { products: lines.length, matched, shortageItems: shortages.length, gainItems: gains.length, systemTotal, physicalTotal, shortageQty: Math.abs(shortages.reduce((sum, item) => sum + countLineDifference(item), 0)), gainQty: gains.reduce((sum, item) => sum + countLineDifference(item), 0), net: physicalTotal - systemTotal };
});
const countHistoryRows = computed(() => counts.value.filter((row) => {
    const search = countFilters.search.trim().toLowerCase();
    if (search && !`${row.session_number || ''} ${row.remarks || ''} ${row.items?.[0]?.product?.name || ''}`.toLowerCase().includes(search)) return false;
    if (countFilters.branch_id && Number(row.branch_id) !== Number(countFilters.branch_id)) return false;
    if (countFilters.warehouse_id && Number(row.warehouse_id) !== Number(countFilters.warehouse_id)) return false;
    if (countFilters.status && row.status !== countFilters.status) return false;
    if (countFilters.count_type && row.count_type !== countFilters.count_type) return false;
    if (countFilters.date_from && String(row.count_date || '') < countFilters.date_from) return false;
    if (countFilters.date_to && String(row.count_date || '') > countFilters.date_to) return false;
    return true;
}));
const selectedTransferProduct = computed(() => productFor(transferItem.product_id));
const transferProductResults = computed(() => {
    const q = transferProductSearch.value.trim().toLowerCase();
    if (q.length < 2) return [];
    const products = refs.value.products || [];
    return products.filter((p) => `${p.name || ''} ${p.sku || ''} ${productBarcode(p)}`.toLowerCase().includes(q)).slice(0, 30);
});
const isBatchTracked = (item) => Boolean(productFor(item.product_id).batch_required);
const isSerialTracked = (item) => Boolean(productFor(item.product_id).serial_required);
const transferQty = (item) => Number(item.approved_quantity || item.requested_quantity || 0);
const sourceAfterTransfer = (item) => Number(item.current_stock || 0) - transferQty(item);
const destinationAfterTransfer = (item) => Number(item.destination_stock || 0) + transferQty(item);
const transferPhysicalBefore = (item) => Number(item.current_stock || 0) + Number(item.destination_stock || 0);
const transferPhysicalAfter = (item) => sourceAfterTransfer(item) + destinationAfterTransfer(item);
const locationLabel = (loc) => [loc.zone, loc.aisle, loc.rack, loc.shelf, loc.bin].filter(Boolean).join(' / ') || loc.name || `Location #${loc.id}`;
const activeLocationsForWarehouse = (warehouseId) => warehouseLocations.value.filter((loc) => Number(loc.warehouse_id) === Number(warehouseId) && (loc.status || 'active') === 'active');
const sourceLocationOptions = computed(() => activeLocationsForWarehouse(transfer.source_warehouse_id));
const destinationLocationOptions = computed(() => activeLocationsForWarehouse(transfer.destination_warehouse_id));
const hasSourceLocations = computed(() => sourceLocationOptions.value.length > 0);
const hasDestinationLocations = computed(() => destinationLocationOptions.value.length > 0);
const transferLineError = (item) => {
    if (!transferRouteValid.value) return 'Choose different source and destination warehouses.';
    if (!item.product_id) return 'Select a product.';
    if (Number(item.current_stock || 0) <= 0) return `Out of stock at ${warehouseName(transfer.source_warehouse_id)}.`;
    if (transferQty(item) <= 0) return 'Quantity must be greater than zero.';
    if (Number(item.current_stock || 0) < transferQty(item)) return `Only ${qty(item.current_stock)} units are available in the selected source warehouse.`;
    if (hasSourceLocations.value && !item.source_location) return 'From location is required.';
    if (hasDestinationLocations.value && !item.destination_location) return 'To location is required.';
    if (isBatchTracked(item) && !item.source_batch_id) return 'Source batch is required.';
    if (isSerialTracked(item) && !item.source_serial_id) return 'Source serial number is required.';
    return '';
};
const canAddTransferItem = computed(() => Boolean(transferItem.product_id) && !transferLineError(transferItem));
const transferRouteValid = computed(() => Boolean(transfer.source_branch_id && transfer.source_warehouse_id && transfer.destination_branch_id && transfer.destination_warehouse_id) && Number(transfer.source_warehouse_id) !== Number(transfer.destination_warehouse_id));
const canPostTransfer = computed(() => transferRouteValid.value && transfer.items.length > 0 && transfer.items.every((item) => !transferLineError(item)));
const transferSummary = computed(() => {
    const lines = transfer.items.filter((item) => item.product_id);
    const totalQty = lines.reduce((sum, item) => sum + transferQty(item), 0);
    return { products: lines.length, totalQty, sourceChange: -totalQty, destinationChange: totalQty, physicalChange: 0 };
});
const transferHistoryRows = computed(() => transfers.value.filter((row) => {
    const search = transferFilters.search.trim().toLowerCase();
    if (search && !`${row.voucher_number || ''} ${row.remarks || ''} ${row.items?.[0]?.product?.name || ''}`.toLowerCase().includes(search)) return false;
    if (transferFilters.source_branch_id && Number(row.source_branch_id) !== Number(transferFilters.source_branch_id)) return false;
    if (transferFilters.destination_branch_id && Number(row.destination_branch_id) !== Number(transferFilters.destination_branch_id)) return false;
    if (transferFilters.status && row.status !== transferFilters.status) return false;
    if (transferFilters.transfer_type && row.transfer_type !== transferFilters.transfer_type) return false;
    if (transferFilters.date_from && String(row.transfer_date || '') < transferFilters.date_from) return false;
    if (transferFilters.date_to && String(row.transfer_date || '') > transferFilters.date_to) return false;
    return true;
}));
const refreshTransferLineStocks = async (item) => {
    if (!item.product_id || !transfer.source_warehouse_id) {
        item.current_stock = 0;
        item.destination_stock = 0;
        return;
    }
    await refreshLineStock(item, { branch_id: transfer.source_branch_id, warehouse_id: transfer.source_warehouse_id });
    if (!item.product_id || !transfer.destination_warehouse_id) return;
    const value = await InventoryApi.inventoryValuation({ branch_id: transfer.destination_branch_id || '', warehouse_id: transfer.destination_warehouse_id, product_id: item.product_id, product_variant_id: item.product_variant_id || '', batch_id: item.destination_batch_id || item.source_batch_id || '' });
    item.destination_stock = Number(value.quantity || value.available || 0);
};
const refreshTransferStocks = async () => {
    await Promise.all(transfer.items.map((item) => refreshTransferLineStocks(item)));
};
const onTransferBranchChange = async (side) => {
    if (side === 'source') transfer.source_warehouse_id = '';
    if (side === 'destination') transfer.destination_warehouse_id = '';
    await refreshTransferStocks();
};
const onTransferWarehouseChange = async () => {
    await refreshTransferStocks();
};
const blankTransferItem = () => ({ product_id: '', product_variant_id: '', product_name: '', sku: '', barcode: '', unit: '', requested_quantity: 1, approved_quantity: '', unit_cost: 0, source_batch_id: '', destination_batch_id: '', source_serial_id: '', destination_serial_id: '', source_location: '', destination_location: '', current_stock: 0, destination_stock: 0, source_before: 0, source_after: 0, destination_before: 0, destination_after: 0 });
const openTransferDrawer = (item = null, index = null) => {
    Object.assign(transferItem, blankTransferItem(), item || {});
    transferProductSearch.value = '';
    editingTransferIndex.value = index;
    transferDrawerOpen.value = true;
    refreshTransferLineStocks(transferItem);
};
const closeTransferDrawer = () => {
    transferDrawerOpen.value = false;
    editingTransferIndex.value = null;
    transferProductSearch.value = '';
    Object.assign(transferItem, blankTransferItem());
};
const selectTransferProduct = async (product) => {
    Object.assign(transferItem, {
        product_id: product.id,
        product_name: product.name || '',
        sku: product.sku || '',
        barcode: productBarcode(product),
        unit: productUnit(product),
        source_batch_id: '',
        destination_batch_id: '',
        source_serial_id: '',
        destination_serial_id: '',
        current_stock: 0,
        destination_stock: 0,
    });
    transferProductSearch.value = product.name || '';
    await refreshTransferLineStocks(transferItem);
};
const transferDuplicateIndex = (row) => transfer.items.findIndex((item, index) => index !== editingTransferIndex.value
    && Number(item.product_id) === Number(row.product_id)
    && Number(item.product_variant_id || 0) === Number(row.product_variant_id || 0)
    && Number(item.source_warehouse_id || transfer.source_warehouse_id || 0) === Number(row.source_warehouse_id || transfer.source_warehouse_id || 0)
    && Number(item.destination_warehouse_id || transfer.destination_warehouse_id || 0) === Number(row.destination_warehouse_id || transfer.destination_warehouse_id || 0)
    && String(item.source_location || '') === String(row.source_location || '')
    && String(item.destination_location || '') === String(row.destination_location || '')
    && Number(item.source_batch_id || 0) === Number(row.source_batch_id || 0));
const transferRowSnapshot = () => {
    const product = selectedTransferProduct.value || {};
    const qtyValue = transferQty(transferItem);
    return {
        ...blankTransferItem(),
        ...transferItem,
        product_id: Number(transferItem.product_id),
        product_variant_id: transferItem.product_variant_id || null,
        product_name: transferItem.product_name || product.name || '',
        sku: transferItem.sku || product.sku || '',
        barcode: transferItem.barcode || productBarcode(product),
        unit: transferItem.unit || productUnit(product),
        source_branch_id: transfer.source_branch_id,
        source_warehouse_id: transfer.source_warehouse_id,
        destination_branch_id: transfer.destination_branch_id,
        destination_warehouse_id: transfer.destination_warehouse_id,
        requested_quantity: qtyValue,
        approved_quantity: qtyValue,
        available_qty: Number(transferItem.current_stock || 0),
        source_before: Number(transferItem.current_stock || 0),
        source_after: sourceAfterTransfer(transferItem),
        destination_before: Number(transferItem.destination_stock || 0),
        destination_after: destinationAfterTransfer(transferItem),
    };
};
const addTransferItemFromDrawer = () => {
    const message = transferLineError(transferItem);
    if (message) { errors.value = { form: [message] }; showToast(message, 'error', 'Validation'); return; }
    const row = transferRowSnapshot();
    if (transferDuplicateIndex(row) !== -1) {
        const duplicateMessage = 'This product is already added to the transfer.';
        errors.value = { form: [duplicateMessage] };
        showToast(duplicateMessage, 'error', 'Validation');
        return;
    }
    if (editingTransferIndex.value === null) transfer.items.push(row);
    else transfer.items.splice(editingTransferIndex.value, 1, row);
    closeTransferDrawer();
};
const resetTransferForm = () => {
    Object.assign(transfer, { id: null, transfer_date: today, transfer_type: 'immediate', expected_delivery_date: '', status: 'draft', remarks: '', items: [], source_branch_id: transfer.source_branch_id, source_warehouse_id: transfer.source_warehouse_id, destination_branch_id: transfer.destination_branch_id, destination_warehouse_id: transfer.destination_warehouse_id });
    closeTransferDrawer();
};
const editTransfer = async (row) => {
    Object.assign(transfer, {
        id: row.id || null,
        transfer_date: String(row.transfer_date || today).slice(0, 10),
        source_branch_id: row.source_branch_id || '',
        source_warehouse_id: row.source_warehouse_id || '',
        destination_branch_id: row.destination_branch_id || '',
        destination_warehouse_id: row.destination_warehouse_id || '',
        transfer_type: row.transfer_type || 'immediate',
        expected_delivery_date: row.expected_delivery_date || '',
        status: row.status || 'draft',
        remarks: row.remarks || '',
        items: (row.items || []).map((item) => ({
            product_id: item.product_id || '',
            product_variant_id: item.product_variant_id || '',
            product_name: item.product?.name || item.product_name || '',
            sku: item.product?.sku || item.sku || '',
            barcode: item.product?.primary_barcode || item.product?.barcode || item.barcode || '',
            unit: item.unit || productUnit(item.product || productFor(item.product_id)),
            requested_quantity: Number(item.requested_quantity || 1),
            approved_quantity: item.approved_quantity || '',
            unit_cost: Number(item.unit_cost || 0),
            source_batch_id: item.source_batch_id || '',
            destination_batch_id: item.destination_batch_id || '',
            source_serial_id: item.source_serial_id || '',
            destination_serial_id: item.destination_serial_id || '',
            source_location: item.source_location || '',
            destination_location: item.destination_location || '',
            current_stock: 0,
            destination_stock: 0,
            source_before: item.source_before || 0,
            source_after: item.source_after || 0,
            destination_before: item.destination_before || 0,
            destination_after: item.destination_after || 0,
        })),
    });
    await refreshTransferStocks();
};
const refreshConditionQuantities = async (item) => {
    if (!item.product_id || !adjustment.warehouse_id || !isConditionTransferLine(item)) return;
    const base = { branch_id: adjustment.branch_id || '', warehouse_id: adjustment.warehouse_id, product_id: item.product_id, product_variant_id: item.product_variant_id || '', batch_id: item.batch_id || '' };
    const [sourceValue, destinationValue, physicalValue, saleableValue] = await Promise.all([
        InventoryApi.inventoryValuation({ ...base, stock_status: item.source_condition_status || 'saleable' }),
        InventoryApi.inventoryValuation({ ...base, stock_status: item.destination_condition_status || 'saleable' }),
        InventoryApi.inventoryValuation(base),
        InventoryApi.inventoryValuation({ ...base, stock_status: 'saleable' }),
    ]);
    item.source_condition_quantity = Number(sourceValue.quantity || sourceValue.available || 0);
    item.destination_condition_quantity = Number(destinationValue.quantity || destinationValue.available || 0);
    item.physical_quantity = Number(physicalValue.quantity || 0);
    item.saleable_quantity = Number(saleableValue.quantity || saleableValue.available || 0);
};
const refreshAdjustmentStocks = async () => {
    await Promise.all(adjustment.items.map((item) => refreshLineStock(item)));
};
const onAdjustmentTypeChange = async () => {
    if (adjustment.adjustment_type === 'condition_transfer') {
        await Promise.all(adjustment.items.map((item) => {
            item.direction = 'transfer';
            return onLineDirectionChange(item);
        }));
        return;
    }

    if (adjustment.adjustment_type === 'increase') {
        adjustment.items.forEach((item) => { item.direction = 'in'; });
    }

    if (adjustment.adjustment_type === 'decrease') {
        adjustment.items.forEach((item) => { item.direction = 'out'; });
    }

    await refreshAdjustmentStocks();
};
const onLineDirectionChange = async (item) => {
    if (isConditionTransferLine(item)) {
        adjustment.adjustment_type = 'condition_transfer';
        adjustment.source = 'condition_transfer';
        item.source_condition_status = item.source_condition_status || 'damaged';
        item.destination_condition_status = item.destination_condition_status || 'saleable';
        item.condition_status = item.destination_condition_status;
        item.unit_cost = 0;
        await refreshConditionQuantities(item);
        return;
    }

    if (adjustment.adjustment_type === 'condition_transfer') {
        adjustment.adjustment_type = 'mixed';
        adjustment.source = 'manual';
    }
    syncOutboundCondition(item);
    await refreshLineStock(item);
};
const onLineConditionChange = async (item) => {
    syncOutboundCondition(item);
    await refreshLineStock(item);
};
watch(() => [adjustment.branch_id, adjustment.warehouse_id, adjustment.adjustment_type], refreshAdjustmentStocks);
onMounted(load);
</script>

<template>
    <Layout :page="page" :title="title">
        <template #topbar-title>
            <div class="bill-page-title">
                <span>INVENTORY</span>
                <h1>Stock Operations</h1>
                <p>Manage stock adjustments, physical counts, transfers, locations and inventory movements.</p>
            </div>
        </template>
        <div class="inventory-control">
            <AppToast v-if="toast" show :title="toast.title" :message="toast.message" :type="toast.type" />
            <div class="page-toolbar">
                <button class="primary" @click="showOperationSelector = true">+ New Stock Operation</button>
                <div class="barcode-capture"><input v-model="barcodeScan" placeholder="Scan barcode" @keyup.enter="scanBarcode" /><button @click="scanBarcode">Scan Barcode</button></div>
                <button @click="exportDashboard">Export</button>
                <button :disabled="loading" @click="load">{{ loading ? 'Refreshing...' : 'Refresh' }}</button>
            </div>
            <div class="tabs"><button v-for="t in tabs" :key="t.key" :class="{active: tab === t.key}" @click="tab = t.key">{{ t.label }}</button></div>
            <div v-if="errors.form" class="alert">{{ errors.form[0] }}</div>
            <TableLoadingState v-if="loading" title="Loading inventory vouchers..." description="Please wait while stock operation data is loaded." />

            <section v-if="!loading && tab === 'dashboard'" class="dashboard-stack">
                <div class="kpi-grid">
                    <button v-for="card in kpiCards" :key="card.key" type="button" class="kpi-card" :class="card.tone" @click="card.action && card.action()">
                        <span>{{ card.label }}</span><strong>{{ card.value }}</strong><small>{{ card.note }}</small>
                    </button>
                </div>
                <div class="dashboard-columns">
                    <section class="panel">
                        <div class="section-head"><div><h2>Inventory Alerts</h2><p>Critical stock exceptions that need attention.</p></div></div>
                        <div v-if="inventoryAlerts.length" class="alert-list">
                            <div v-for="alert in inventoryAlerts" :key="alert.label" class="alert-row"><span>{{ alert.label }}</span><strong>{{ alert.products }}</strong><button @click="alert.action">View</button></div>
                        </div>
                        <div v-else class="healthy-state">Inventory is healthy. No critical stock alerts.</div>
                    </section>
                    <section class="panel">
                        <div class="section-head"><div><h2>Quick Actions</h2><p>Start the common inventory workflows directly.</p></div></div>
                        <div class="quick-grid"><button v-for="action in quickActions" :key="action.key" @click="runQuickAction(action)">{{ action.label }}</button></div>
                    </section>
                </div>
                <section class="panel">
                    <div class="section-head"><div><h2>Recent Stock Activity</h2><p>Latest adjustments, transfers, counts and location movements.</p></div><button @click="tab = 'register'">Open History</button></div>
                    <div class="table-wrapper"><table><thead><tr><th>Date & Time</th><th>Voucher No.</th><th>Operation</th><th>Product</th><th>Warehouse</th><th>Movement</th><th>Qty</th><th>Physical</th><th>User</th><th>Status</th></tr></thead><tbody><tr v-for="row in recentActivity" :key="row.id" @click="tab = 'register'"><td>{{ formatIndianDateTime(row.date) }}</td><td>{{ row.number || '-' }}</td><td>{{ labelize(row.type) }}</td><td>{{ row.productName }}</td><td>{{ row.warehouse }}</td><td>{{ row.movement || '-' }}</td><td :class="['qty-change', row.action === 'transfer' || row.action === 'location' || row.action === 'count' ? 'neutral' : Number(row.quantity || 0) > 0 ? 'positive' : 'negative']">{{ activityQty(row) }}</td><td>{{ row.physicalChange === 0 ? '0' : signedQty(row.physicalChange) }}</td><td>{{ row.userName }}</td><td><span class="status-pill">{{ labelize(row.status) }}</span></td></tr><tr v-if="!recentActivity.length"><td colspan="10" class="empty">No stock movement history found.</td></tr></tbody></table></div>
                </section>
            </section>

            <section v-if="!loading && tab === 'voucher'" class="panel">
                <div class="section-head"><div><span>STOCK ADJUSTMENT WORKFLOW</span><h2>New {{ currentVoucherType.label }}</h2><p>Draft does not update stock. Posting creates immutable stock ledger entries.</p></div><span class="status-pill">{{ labelize(adjustment.status) }}</span></div>
                <div class="form-grid"><input value="Auto generated on save" disabled /><select v-model="adjustment.branch_id"><option value="">Branch</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select><select v-model="adjustment.warehouse_id"><option value="">Source Warehouse</option><option v-for="w in filteredWarehouses(adjustment.branch_id)" :key="w.id" :value="w.id">{{ w.name }}</option></select><input v-model="adjustment.adjustment_date" type="date" /><select v-model="adjustment.adjustment_reason_id" @change="applyReason"><option value="">Select Reason</option><option v-for="r in refs.reasons" :key="r.id" :value="r.id">{{ reasonDisplayName(r) }}</option></select><select v-model="adjustment.adjustment_type" @change="onAdjustmentTypeChange"><option value="increase">Stock In</option><option value="decrease">Stock Out</option><option value="condition_transfer">Condition Transfer</option><option value="mixed">Mixed</option></select><input :value="currentVoucherType.label" disabled /><textarea v-model="adjustment.remarks" placeholder="Remarks / reason note"></textarea></div>
                <div class="hint-grid">
                    <span>Voucher number is generated when the voucher is saved.</span>
                    <span>Branch and source warehouse decide where stock is affected.</span>
                    <span>Reason is required before posting and is used for audit reports.</span>
                    <span>Draft vouchers remain editable and do not update stock.</span>
                </div>
                <div class="line-head adjustment-head"><span>Product</span><span>Current / Source</span><span>Adjustment Type</span><span>Quantity</span><span>Result</span><span>Cost</span><span>Location</span><span>Condition</span><span>Remove</span></div>
                <div v-for="(item, i) in adjustment.items" :key="i" class="line-grid adjustment-row">
                    <label class="line-field product-field"><span>Product</span><select v-model="item.product_id" @change="refreshLineStock(item)"><option value="">Select Product</option><option v-for="p in refs.products" :key="p.id" :value="p.id">{{ p.name }} - {{ p.sku }}</option></select></label>
                    <label v-if="!isConditionTransferLine(item)" class="line-field"><span>Current Stock</span><input :value="item.product_id ? qty(item.current_stock) : ''" disabled placeholder="Current Stock" /></label>
                    <label v-else class="line-field"><span>Source Qty</span><input :value="item.product_id ? qty(lineSourceQty(item)) : ''" disabled placeholder="Source Qty" /></label>
                    <label class="line-field"><span>Adjustment Type</span><select v-model="item.direction" @change="onLineDirectionChange(item)"><option v-for="option in movementOptions" :key="option.value" :value="option.value">{{ adjustmentTypeLabel(option.value) }}</option></select></label>
                    <label class="line-field"><span>Quantity</span><input v-model.number="item.adjustment_quantity" type="number" step="0.001" placeholder="Qty" /></label>
                    <label v-if="!isConditionTransferLine(item)" class="line-field"><span>New Stock</span><input :value="item.product_id ? qty(lineNewStock(item)) : ''" disabled :class="lineNewStock(item) < 0 ? 'stock-negative' : ''" placeholder="New Stock" /></label>
                    <label v-else class="line-field result-field"><span>Result</span><input :value="conditionTransferResult(item)" disabled placeholder="Result" /></label>
                    <label v-if="!isConditionTransferLine(item)" class="line-field"><span>Unit Cost</span><input v-model.number="item.unit_cost" type="number" step="0.01" placeholder="Unit Cost" /></label>
                    <div v-else class="line-field transfer-physical-note"><span>Physical</span><strong>{{ item.product_id ? `${qty(item.physical_quantity)} -> ${qty(item.physical_quantity)}` : '-' }}</strong></div>
                    <label class="line-field"><span>Location</span><input v-model="item.warehouse_location" placeholder="Location" /></label>
                    <label v-if="!isConditionTransferLine(item)" class="line-field"><span>Condition</span><select v-model="item.condition_status" @change="onLineConditionChange(item)"><option v-for="option in conditionOptions" :key="option.value" :value="option.value">{{ option.label }}</option></select></label>
                    <div v-else class="condition-transfer-fields">
                        <label class="line-field"><span>From</span><select v-model="item.source_condition_status" @change="refreshConditionQuantities(item)"><option v-for="option in conditionOptions" :key="option.value" :value="option.value">{{ option.label }}</option></select></label>
                        <label class="line-field"><span>To</span><select v-model="item.destination_condition_status" @change="item.condition_status = item.destination_condition_status; refreshConditionQuantities(item)"><option v-for="option in conditionOptions" :key="option.value" :value="option.value">{{ option.label }}</option></select></label>
                    </div>
                    <button class="remove-line" @click="adjustment.items.splice(i,1)" :disabled="adjustment.items.length === 1">Remove</button>
                </div>
                <div v-if="adjustment.items.some(isConditionTransferLine)" class="condition-transfer-help">Condition transfers reclassify existing physical inventory and do not change physical quantity.</div>
                <section class="voucher-help-card">
                    <strong>Line guidance</strong>
                    <ul>
                        <li>Current stock is read-only and comes from the stock ledger.</li>
                        <li>IN increases stock; OUT decreases stock and cannot exceed available quantity.</li>
                        <li>Condition Transfer moves quantity between stock conditions in the same branch and warehouse.</li>
                        <li>Cost is used only for this voucher valuation and does not update Product Master price.</li>
                        <li>Location and condition help separate saleable, damaged, expired or lost stock.</li>
                    </ul>
                </section>
                <div class="posting-summary"><strong>{{ adjustmentSummary.products }} products affected</strong><span>Stock Increase: +{{ qty(adjustmentSummary.increases) }}</span><span>Stock Decrease: -{{ qty(adjustmentSummary.decreases) }}</span><span>Condition Transfer: {{ qty(adjustmentSummary.transfers) }}</span><span>Net Difference: {{ signedQty(adjustmentSummary.net) }}</span></div><div class="actions"><button @click="addRow(adjustment.items, { product_id: '', unit_id: '', adjustment_quantity: 1, direction: 'in', unit_cost: 0, warehouse_location: '', condition_status: 'saleable', source_condition_status: 'damaged', destination_condition_status: 'saleable', reason: '' })">Add Item</button><button :disabled="saving" @click="saveAdjustment('draft')">Save Draft</button><button class="primary" :disabled="saving" @click="saveAdjustment('posted')">Post Adjustment</button></div>
            </section>

            <section v-if="!loading && tab === 'register'" class="panel">
                <div class="section-head"><div><h2>Voucher Register</h2><p>Search, filter, view, post and cancel inventory stock operation vouchers.</p></div></div>
                <div class="form-grid filters"><input v-model="registerFilters.search" placeholder="Voucher no, product, reason, remarks" /><select v-model="registerFilters.voucher_type"><option value="">All Types</option><option value="manual">Stock Adjustment</option><option value="damage">Damage</option><option value="expired_stock">Expired</option><option value="production_consumption">Production Consumption</option><option value="production_output">Production Output</option></select><select v-model="registerFilters.status"><option value="">All Status</option><option>draft</option><option>posted</option><option>reversed</option><option>cancelled</option></select><select v-model="registerFilters.branch_id"><option value="">All Branches</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select><select v-model="registerFilters.warehouse_id"><option value="">All Warehouses</option><option v-for="w in refs.warehouses" :key="w.id" :value="w.id">{{ w.name }}</option></select><input v-model="registerFilters.date_from" type="date" /><input v-model="registerFilters.date_to" type="date" /><button @click="load">Apply</button></div>
                <div class="table-wrapper sticky-register-table"><table><thead><tr><th>Voucher No</th><th>Type</th><th>Date</th><th>Branch</th><th>Warehouse</th><th>Product</th><th>Movement</th><th>Quantity</th><th>Physical</th><th>User</th><th>Status</th><th>Actions</th></tr></thead><tbody><tr v-for="row in registerRows" :key="row.id"><td>{{ row.number }}</td><td>{{ labelize(row.type) }}</td><td>{{ formatIndianDateTime(row.date) }}</td><td>{{ row.branch }}</td><td>{{ row.warehouse }}</td><td>{{ row.productName }}</td><td>{{ row.movement || '-' }}</td><td>{{ activityQty(row) }}</td><td>{{ row.physicalChange === 0 ? '0' : signedQty(row.physicalChange || row.quantity) }}</td><td>{{ row.userName }}</td><td><span class="status-pill">{{ labelize(row.status) }}</span></td><td><button @click="tab = 'reports'; activeReport = 'movement_report'">Ledger</button><button v-if="permissions.approve && row.action === 'adjustment' && ['draft','submitted','approved'].includes(row.status)" @click="postAdjustment(row.raw)">Post</button><button v-if="permissions.approve && row.action === 'transfer' && ['approved','draft','submitted'].includes(row.status)" @click="dispatchTransfer(row.raw)">Dispatch</button><button v-if="permissions.approve && row.action === 'transfer' && ['dispatched','partially_received'].includes(row.status)" @click="receiveTransfer(row.raw)">Receive</button><button v-if="permissions.approve && row.action === 'count' && row.status !== 'posted'" @click="postVariance(row.raw)">Post Variance</button><button v-if="permissions.cancel && row.action === 'adjustment' && row.status === 'posted'" @click="reverseAdjustment(row.raw)">Cancel</button><button v-if="permissions.print" @click="printPage">Print</button></td></tr><tr v-if="!registerRows.length"><td colspan="12" class="empty">No inventory vouchers found.</td></tr></tbody></table></div>
            </section>

            <section v-if="tab === 'counts'" class="count-page">
                <div class="section-head"><div><span>NEW STOCK COUNT</span><h2>Stock Count</h2><p>Compare physical inventory with system stock and post approved differences.</p></div></div>
                <div class="tabs transfer-view-tabs"><button :class="{active: countView === 'new'}" @click="countView = 'new'">New Stock Count</button><button :class="{active: countView === 'history'}" @click="countView = 'history'">Count History</button></div>
                <template v-if="countView === 'new'">
                    <section class="panel count-card">
                        <div class="section-head"><div><h2>Count Details</h2><p>Select the warehouse and count method before adding products.</p></div><span class="status-pill">{{ countStatusLabel(count.status) }}</span></div>
                        <div class="form-grid count-details-grid"><label><span>Branch</span><select v-model="count.branch_id" @change="count.warehouse_id = ''; count.items = []"><option value="">Branch</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select></label><label><span>Warehouse</span><select v-model="count.warehouse_id" @change="count.items = []"><option value="">Warehouse</option><option v-for="w in filteredWarehouses(count.branch_id)" :key="w.id" :value="w.id">{{ w.name }}</option></select></label><label><span>Count Date</span><input v-model="count.count_date" type="date" /></label><label><span>Count Type</span><select v-model="count.count_type"><option v-for="option in countTypeOptions" :key="option.value" :value="option.value">{{ option.label }}</option></select></label><label class="count-freeze"><input v-model="count.freeze_stock" type="checkbox" /> <strong>Freeze stock movements during count</strong><small>Prevents inventory movements in the selected warehouse while this count is in progress.</small></label><label class="count-remarks"><span>Remarks</span><textarea v-model="count.remarks" placeholder="Remarks"></textarea></label></div>
                    </section>
                    <section class="panel count-card">
                        <div class="section-head"><div><h2>Count Items</h2><p>Products included in this physical count.</p></div><button v-if="count.items.length" class="primary" @click="openCountDrawer()">+ Add Product</button></div>
                        <div v-if="!count.items.length" class="transfer-empty-state"><strong>No products added yet</strong><span>Add products to begin the physical stock count.</span><button class="primary" @click="openCountDrawer()">+ Add Product</button></div>
                        <div v-else class="table-wrapper"><table><thead><tr><th>Product</th><th>System Qty</th><th>Physical Qty</th><th>Difference</th><th>Location</th><th>Batch / Serial</th><th>Variance Reason</th><th>Expected After</th><th>Action</th></tr></thead><tbody><tr v-for="(item, i) in count.items" :key="`count-row-${i}`"><td><strong>{{ item.product_name || productFor(item.product_id).name || '-' }}</strong><small>{{ item.sku || productFor(item.product_id).sku || '' }}</small></td><td>{{ qty(item.system_quantity) }}</td><td>{{ qty(item.counted_quantity) }}</td><td :class="['qty-change', countLineTone(item)]">{{ signedQty(countLineDifference(item)) }}</td><td>{{ item.warehouse_location || '-' }}</td><td>{{ item.batch_id || item.serial_id || '-' }}</td><td>{{ item.reason || '-' }}</td><td>{{ qty(countExpectedAfter(item)) }}</td><td><button @click="openCountDrawer(item, i)">Edit</button><button @click="count.items.splice(i, 1)">Remove</button></td></tr></tbody></table></div>
                    </section>
                    <section v-if="count.items.length" class="panel count-card">
                        <div class="section-head"><div><h2>Count Summary</h2><p>Only approved variance lines will create stock ledger movement.</p></div></div>
                        <div class="posting-summary count-summary"><strong>Products Counted: {{ countSummary.products }}</strong><span>Matched: {{ countSummary.matched }}</span><span>Shortage Items: {{ countSummary.shortageItems }}</span><span>Gain Items: {{ countSummary.gainItems }}</span><span>System Qty Total: {{ qty(countSummary.systemTotal) }}</span><span>Physical Qty Total: {{ qty(countSummary.physicalTotal) }}</span><span>Net Difference: {{ signedQty(countSummary.net) }}</span><span>Total Shortage Qty: {{ qty(countSummary.shortageQty) }}</span><span>Total Gain Qty: {{ qty(countSummary.gainQty) }}</span></div>
                    </section>
                    <div class="transfer-action-bar"><button @click="resetCountForm">Cancel</button><button :disabled="saving" @click="saveCount('draft')">Save Draft</button><button class="primary" :disabled="saving || validateCountClient('approved')" @click="saveCount('approved')">Approve & Post</button></div>
                </template>
                <section v-if="countView === 'history'" class="panel count-card">
                    <div class="section-head"><div><h2>Count History</h2><p>Draft, pending and posted stock count sessions.</p></div></div>
                    <div class="form-grid filters"><input v-model="countFilters.search" placeholder="Search count no or product" /><select v-model="countFilters.branch_id"><option value="">All Branches</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select><select v-model="countFilters.warehouse_id"><option value="">All Warehouses</option><option v-for="w in refs.warehouses" :key="w.id" :value="w.id">{{ w.name }}</option></select><select v-model="countFilters.status"><option value="">All Status</option><option>draft</option><option>counting</option><option>submitted</option><option>approved</option><option>posted</option></select><select v-model="countFilters.count_type"><option value="">All Types</option><option v-for="option in countTypeOptions" :key="option.value" :value="option.value">{{ option.label }}</option></select><input v-model="countFilters.date_from" type="date" /><input v-model="countFilters.date_to" type="date" /></div>
                    <div class="table-wrapper"><table><thead><tr><th>Count No.</th><th>Date</th><th>Branch</th><th>Warehouse</th><th>Type</th><th>Products</th><th>Matched</th><th>Variance Items</th><th>Net Difference</th><th>Status</th><th>Created By</th><th>Actions</th></tr></thead><tbody><tr v-for="session in countHistoryRows" :key="session.id"><td>{{ session.session_number }}</td><td>{{ formatIndianDateTime(session.count_date) }}</td><td>{{ session.branch?.name || '-' }}</td><td>{{ session.warehouse?.name || '-' }}</td><td>{{ optionLabel(countTypeOptions, session.count_type) }}</td><td>{{ session.items?.length || 0 }}</td><td>{{ (session.items || []).filter((item) => Number(item.variance_quantity || 0) === 0).length }}</td><td>{{ (session.items || []).filter((item) => Number(item.variance_quantity || 0) !== 0).length }}</td><td>{{ signedQty((session.items || []).reduce((sum, item) => sum + Number(item.variance_quantity || 0), 0)) }}</td><td><span class="status-pill">{{ countStatusLabel(session.status) }}</span></td><td>{{ userNameForMovement(session) }}</td><td><button @click="tab = 'register'">View</button><button v-if="session.status === 'draft'" @click="editCount(session)">Edit</button><button v-if="session.status !== 'posted'" @click="postVariance(session)">Approve</button><button @click="printPage">Print</button></td></tr><tr v-if="!countHistoryRows.length"><td colspan="12" class="empty">No stock counts found.</td></tr></tbody></table></div>
                </section>
            </section>

            <section v-if="tab === 'transfers'" class="transfer-page">
                <div class="section-head"><div><span>NEW STOCK TRANSFER</span><h2>New Stock Transfer</h2><p>Move inventory between warehouses without changing total physical inventory.</p></div></div>
                <div class="tabs transfer-view-tabs"><button :class="{active: transferView === 'new'}" @click="transferView = 'new'">New Transfer</button><button :class="{active: transferView === 'history'}" @click="transferView = 'history'">Transfer History</button></div>
                <template v-if="transferView === 'new'">
                <section class="panel transfer-card">
                <div class="section-head"><div><h2>Transfer Details</h2><p>Choose the source and destination route for this transfer.</p></div></div>
                <div class="transfer-route">
                    <div class="route-side"><strong>FROM</strong><label><span>Source Branch</span><select v-model="transfer.source_branch_id" @change="onTransferBranchChange('source')"><option value="">Source Branch</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select></label><label><span>Source Warehouse</span><select v-model="transfer.source_warehouse_id" @change="onTransferWarehouseChange"><option value="">Source Warehouse</option><option v-for="w in filteredWarehouses(transfer.source_branch_id)" :key="w.id" :value="w.id">{{ w.name }}</option></select></label></div>
                    <div class="route-arrow">-></div>
                    <div class="route-side"><strong>TO</strong><label><span>Destination Branch</span><select v-model="transfer.destination_branch_id" @change="onTransferBranchChange('destination')"><option value="">Destination Branch</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select></label><label><span>Destination Warehouse</span><select v-model="transfer.destination_warehouse_id" @change="onTransferWarehouseChange"><option value="">Destination Warehouse</option><option v-for="w in filteredWarehouses(transfer.destination_branch_id)" :key="w.id" :value="w.id">{{ w.name }}</option></select></label></div>
                </div>
                <div class="form-grid transfer-meta"><label><span>Transfer Date</span><input v-model="transfer.transfer_date" type="date" /></label><label><span>Transfer Type</span><select v-model="transfer.transfer_type"><option>immediate</option><option>dispatch_receive</option><option>inter_branch</option><option>inter_warehouse</option></select></label><label class="transfer-remarks"><span>Remarks</span><input v-model="transfer.remarks" placeholder="Remarks" /></label></div>
                </section>

                <section class="panel transfer-card">
                    <div class="section-head"><div><h2>Transfer Items</h2><p>Products selected for this transfer.</p></div><button v-if="transfer.items.some((line) => line.product_id)" class="primary" @click="openTransferDrawer()">+ Add Product</button></div>
                    <div v-if="!transfer.items.some((line) => line.product_id)" class="transfer-empty-state"><strong>No products added yet</strong><span>Add one or more products to this stock transfer.</span><button class="primary" @click="openTransferDrawer()">+ Add Product</button></div>
                    <div v-else class="table-wrapper"><table><thead><tr><th>Product</th><th>Available</th><th>Transfer Qty</th><th>From Location</th><th>To Location</th><th>Unit Cost</th><th>Batch / Serial</th><th>Source After</th><th>Destination After</th><th>Action</th></tr></thead><tbody><tr v-for="(item, i) in transfer.items.filter((line) => line.product_id)" :key="`transfer-summary-${i}`"><td><strong>{{ item.product_name || productFor(item.product_id).name || '-' }}</strong><small>{{ item.sku || productFor(item.product_id).sku || '' }}</small></td><td>{{ qty(item.available_qty ?? item.current_stock) }}</td><td>{{ qty(transferQty(item)) }}</td><td>{{ item.source_location || '-' }}</td><td>{{ item.destination_location || '-' }}</td><td>Rs. {{ money(item.unit_cost) }}</td><td>{{ item.source_batch_id || item.source_serial_id || '-' }}</td><td>{{ qty(item.source_after ?? sourceAfterTransfer(item)) }}</td><td>{{ qty(item.destination_after ?? destinationAfterTransfer(item)) }}</td><td><button @click="openTransferDrawer(item, transfer.items.indexOf(item))">Edit</button><button @click="transfer.items.splice(transfer.items.indexOf(item),1)">Remove</button></td></tr></tbody></table></div>
                </section>

                <section v-if="transfer.items.some((line) => line.product_id)" class="panel transfer-card">
                    <div class="section-head"><div><h2>Transfer Summary</h2><p>A transfer moves stock between warehouses. Total physical inventory does not change.</p></div></div>
                <div class="posting-summary transfer-summary"><strong>Products: {{ transferSummary.products }}</strong><span>Total Qty: {{ qty(transferSummary.totalQty) }}</span><span>Source Stock Change: {{ signedQty(transferSummary.sourceChange) }}</span><span>Destination Stock Change: {{ signedQty(transferSummary.destinationChange) }}</span><span>Net Physical Stock Change: {{ signedQty(transferSummary.physicalChange) }}</span></div>
                </section>

                <div class="transfer-action-bar"><button @click="resetTransferForm">Cancel</button><button :disabled="saving" @click="saveTransfer('draft')">Save Draft</button><button class="primary" :disabled="saving || !canPostTransfer" @click="saveTransfer(transfer.transfer_type === 'immediate' ? 'approved' : 'dispatched')">Post Transfer</button></div>
                </template>

                <section v-if="transferView === 'history'" class="panel transfer-history">
                    <div class="section-head"><div><h2>Transfer History</h2><p>Previous transfers and draft vouchers.</p></div></div>
                    <div class="form-grid filters"><input v-model="transferFilters.search" placeholder="Search transfer no or product" /><select v-model="transferFilters.source_branch_id"><option value="">Source Branch</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select><select v-model="transferFilters.destination_branch_id"><option value="">Destination Branch</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select><select v-model="transferFilters.status"><option value="">Status</option><option>draft</option><option>approved</option><option>dispatched</option><option>partially_received</option><option>received</option><option>cancelled</option></select><input v-model="transferFilters.date_from" type="date" /><input v-model="transferFilters.date_to" type="date" /><select v-model="transferFilters.transfer_type"><option value="">Transfer Type</option><option>immediate</option><option>dispatch_receive</option><option>inter_branch</option><option>inter_warehouse</option></select></div>
                    <div class="table-wrapper"><table><thead><tr><th>Transfer No.</th><th>Date</th><th>Source</th><th>Destination</th><th>Items</th><th>Qty</th><th>Status</th><th>Created By</th><th>Actions</th></tr></thead><tbody><tr v-for="t in transferHistoryRows" :key="t.id"><td>{{ t.voucher_number }}</td><td>{{ formatIndianDateTime(t.transfer_date) }}</td><td>{{ t.source_branch?.name || '-' }} / {{ t.source_warehouse?.name || '-' }}</td><td>{{ t.destination_branch?.name || '-' }} / {{ t.destination_warehouse?.name || '-' }}</td><td>{{ t.items?.length || 0 }}</td><td>{{ qty(t.items?.reduce((sum, item) => sum + Number(item.approved_quantity || item.requested_quantity || 0), 0) || 0) }}</td><td><span class="status-pill">{{ t.status }}</span></td><td>{{ userNameForMovement(t) }}</td><td><button @click="tab = 'register'">View</button><button v-if="t.status === 'draft'" @click="editTransfer(t)">Edit</button><button v-if="t.status === 'draft'" @click="dispatchTransfer(t)">Post</button><button v-if="['approved','submitted'].includes(t.status)" @click="dispatchTransfer(t)">Dispatch</button><button v-if="['dispatched','partially_received'].includes(t.status)" @click="receiveTransfer(t)">Receive</button></td></tr><tr v-if="!transferHistoryRows.length"><td colspan="9" class="empty">No transfers found.</td></tr></tbody></table></div>
                </section>
            </section>

            <section v-if="tab === 'locations'" class="panel">
                <div class="section-head"><div><h2>Warehouse Locations</h2><p>Manage reusable warehouse locations as Warehouse -> Rack -> Shelf -> Bin.</p></div></div>
                <div class="form-grid"><select v-model="locationMaster.branch_id"><option value="">Branch</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select><select v-model="locationMaster.warehouse_id"><option value="">Warehouse</option><option v-for="w in filteredWarehouses(locationMaster.branch_id)" :key="w.id" :value="w.id">{{ w.name }}</option></select><input v-model="locationMaster.zone" placeholder="Zone" /><input v-model="locationMaster.aisle" placeholder="Aisle" /><input v-model="locationMaster.rack" placeholder="Rack" /><input v-model="locationMaster.shelf" placeholder="Shelf" /><input v-model="locationMaster.bin" placeholder="Bin" /><select v-model="locationMaster.status"><option>active</option><option>inactive</option><option>blocked</option></select></div>
                <div class="hint-grid"><span>Warehouse is the storage building selected from master data.</span><span>Rack groups shelves inside a warehouse aisle or zone.</span><span>Shelf is the level inside a rack.</span><span>Bin is the smallest picking/storage location.</span></div>
                <div class="actions"><button :disabled="saving" @click="saveWarehouseLocation">{{ locationMaster.id ? 'Update Location' : 'Save Location' }}</button></div>
                <div class="table-wrapper"><table><thead><tr><th>Warehouse</th><th>Zone</th><th>Aisle</th><th>Rack</th><th>Shelf</th><th>Bin</th><th>Status</th><th>Actions</th></tr></thead><tbody><tr v-for="loc in warehouseLocations" :key="loc.id"><td>{{ loc.warehouse?.name || '-' }}</td><td>{{ loc.zone || '-' }}</td><td>{{ loc.aisle || '-' }}</td><td>{{ loc.rack }}</td><td>{{ loc.shelf }}</td><td>{{ loc.bin }}</td><td><span class="status-pill">{{ loc.status }}</span></td><td><button @click="editWarehouseLocation(loc)">Edit</button></td></tr><tr v-if="!warehouseLocations.length"><td colspan="8" class="empty">No warehouse locations found.</td></tr></tbody></table></div>
                <div class="section-head secondary"><div><h2>Location Movement History</h2><p>Posted movement vouchers stay immutable and remain available for audit.</p></div></div>
                <div class="table-wrapper"><table><thead><tr><th>Voucher</th><th>Warehouse</th><th>Date</th><th>Items</th><th>Status</th></tr></thead><tbody><tr v-for="m in movements" :key="m.id"><td>{{ m.voucher_number }}</td><td>{{ m.warehouse?.name }}</td><td>{{ formatIndianDateTime(m.movement_date) }}</td><td>{{ m.items?.length || 0 }}</td><td>{{ m.status }}</td></tr></tbody></table></div>
            </section>

            <section v-if="tab === 'reasons'" class="panel">
                <div class="section-head"><div><span>REASONS MASTER</span><h2>Stock Adjustment Reasons Master</h2><p>Define why inventory increases or decreases. Values are saved for audit and used in stock adjustment vouchers.</p></div><button v-if="!reasons.length" :disabled="saving" @click="seedDefaultReasons">Create Default Reasons</button></div>
                <div class="reason-form">
                    <label><span>Reason Code</span><input v-model="reason.reason_code" placeholder="DAMAGE" /></label>
                    <label><span>Reason Name</span><input v-model="reason.reason_name" placeholder="Damaged Stock" /></label>
                    <label><span>Movement Type</span><select v-model="reason.default_direction"><option v-for="option in movementOptions" :key="option.value" :value="option.value">{{ option.label }}</option></select><small>Determines whether this reason increases or decreases stock.</small></label>
                    <label><span>Stock Condition</span><select v-model="reason.default_condition_status"><option v-for="option in conditionOptions" :key="option.value" :value="option.value">{{ option.label }}</option></select><small>Identifies why the stock changed or the condition associated with the movement.</small></label>
                    <label><span>Status</span><select v-model="reason.status"><option v-for="option in statusOptions" :key="option.value" :value="option.value">{{ option.label }}</option></select></label>
                </div>
                <section class="voucher-help-card">
                    <strong>Damaged Stock example</strong>
                    <ul>
                        <li>Movement: Stock Out</li>
                        <li>Condition: Damaged</li>
                        <li>Damaged stock will be deducted from available inventory.</li>
                    </ul>
                </section>
                <div class="actions"><button @click="resetReasonForm">Clear</button><button class="primary" :disabled="saving" @click="saveReason">{{ reason.id ? 'Update Reason' : 'Save Reason' }}</button></div>
                <div class="table-wrapper"><table><thead><tr><th>Code</th><th>Reason Name</th><th>Movement</th><th>Condition</th><th>Status</th><th>Used In</th><th>Actions</th></tr></thead><tbody><tr v-for="r in reasons" :key="r.id"><td>{{ r.reason_code }}</td><td>{{ r.reason_name }}</td><td>{{ optionLabel(movementOptions, r.default_direction) }}</td><td>{{ optionLabel(conditionOptions, r.default_condition_status) }}</td><td><span class="status-pill">{{ optionLabel(statusOptions, r.status) }}</span></td><td>{{ r.vouchers_count || 0 }} vouchers</td><td><button @click="editReason(r)">Edit</button><button @click="toggleReasonStatus(r)">{{ r.status === 'active' ? 'Deactivate' : 'Activate' }}</button></td></tr><tr v-if="!reasons.length"><td colspan="7" class="empty">No stock adjustment reasons found. Create one manually or add the recommended defaults.</td></tr></tbody></table></div>
            </section>

            <section v-if="tab === 'reports'" class="panel">
                <div class="section-head"><div><h2>Inventory Reports</h2><p>Ledger-backed stock movement, valuation, branch, warehouse, adjustment, transfer, damage and expiry reports.</p></div><div class="actions inline"><button @click="exportRows('csv')">CSV</button><button @click="exportRows('excel')">Excel</button><button @click="exportRows('pdf')">PDF</button></div></div>
                <div class="tabs report-tabs"><button v-for="report in reportTabs" :key="report.key" :class="{active: activeReport === report.key}" @click="activeReport = report.key">{{ report.label }}</button></div>
                <div class="table-wrapper"><table><thead><tr><th>Date</th><th>Type</th><th>Product / Voucher</th><th>Branch</th><th>Warehouse</th><th>Movement</th><th>In</th><th>Out</th><th>Qty</th><th>Physical</th><th>Value</th><th>Status</th></tr></thead><tbody><tr v-for="(row, index) in currentReportRows" :key="row.id || index"><td>{{ formatIndianDateTime(row.transaction_date || row.adjustment_date || row.transfer_date || row.expiry_date) }}</td><td>{{ labelize(row.transaction_type || row.source || row.transfer_type || activeReport) }}</td><td>{{ row.product?.name || row.product_name || row.voucher_number || row.name || '-' }}</td><td>{{ row.branch?.name || row.branch_name || row.source_branch?.name || '-' }}</td><td>{{ row.warehouse?.name || row.warehouse_name || row.source_warehouse?.name || '-' }}</td><td>{{ row.movement || '-' }}</td><td>{{ qty(row.quantity_in) }}</td><td>{{ qty(row.quantity_out) }}</td><td>{{ qty(row.display_quantity || row.quantity_available || row.total_quantity_in || row.total_quantity_out) }}</td><td>{{ row.physical_change === 0 ? '0' : signedQty(row.physical_change || 0) }}</td><td>Rs. {{ money(row.stock_value || row.total_value_in || row.total_value_out) }}</td><td>{{ labelize(row.status || row.stock_status || '-') }}</td></tr><tr v-if="!currentReportRows.length"><td colspan="12" class="empty">No report data found.</td></tr></tbody></table></div>
            </section>

            <div v-if="countDrawerOpen" class="drawer-backdrop" @click.self="closeCountDrawer">
                <aside class="transfer-drawer">
                    <div class="section-head drawer-head"><div><span>COUNT ITEM</span><h2>{{ editingCountIndex === null ? 'Add Count Item' : 'Edit Count Item' }}</h2><p>Select a product and enter the physical quantity you counted.</p></div><button class="icon-close" title="Close" @click="closeCountDrawer">X</button></div>
                    <div class="drawer-fields">
                        <label class="product-search"><span>Search Product</span><input v-model="countProductSearch" :disabled="!count.warehouse_id" placeholder="Search by product name, SKU or barcode" /><small v-if="count.warehouse_id && countProductSearch.trim().length < 2 && !countItem.product_id" class="search-helper">Type at least 2 characters to search products.</small><div v-if="count.warehouse_id && countProductResults.length" class="product-results"><button v-for="p in countProductResults" :key="p.id" type="button" :class="{selected: Number(p.id) === Number(countItem.product_id)}" @click="selectCountProduct(p)"><strong>{{ p.name }}</strong><small>SKU: {{ p.sku || '-' }} · Barcode: {{ productBarcode(p) || '-' }} · Unit: {{ productUnit(p) }}</small></button></div></label>
                        <div v-if="countItem.product_id" class="selected-product"><strong>{{ countItem.product_name || selectedCountProduct.name }}</strong><span>SKU: {{ countItem.sku || selectedCountProduct.sku || '-' }}</span><span>Barcode: {{ countItem.barcode || productBarcode(selectedCountProduct) || '-' }}</span><span>Unit: {{ countItem.unit || productUnit(selectedCountProduct) }}</span></div>
                        <label><span>System Quantity</span><input :value="countItem.product_id ? `${qty(countItem.system_quantity)} ${countItem.unit || productUnit(selectedCountProduct)}` : ''" disabled /></label>
                        <label><span>Physical Quantity</span><input v-model.number="countItem.counted_quantity" type="number" min="0" step="0.001" :disabled="!countItem.product_id" placeholder="Enter counted quantity" /></label>
                        <label><span>Location</span><select v-if="activeCountLocations.length" v-model="countItem.warehouse_location" :disabled="!countItem.product_id"><option value="">Optional</option><option v-for="loc in activeCountLocations" :key="loc.id" :value="locationLabel(loc)">{{ locationLabel(loc) }}</option></select><input v-else value="Optional" disabled /></label>
                        <label v-if="isBatchTracked(countItem)"><span>Batch</span><input v-model="countItem.batch_id" :disabled="!countItem.product_id" placeholder="Select batch" @change="refreshCountLineStock(countItem)" /></label>
                        <label v-if="isSerialTracked(countItem)"><span>Serial Numbers</span><input v-model="countItem.serial_id" :disabled="!countItem.product_id" placeholder="Scan or select serial" /></label>
                        <label v-if="countItem.product_id && countLineDifference(countItem) !== 0"><span>Variance Reason</span><select v-model="countItem.reason"><option value="">Select reason</option><option v-for="reasonName in countReasonOptions" :key="reasonName" :value="reasonName">{{ reasonName }}</option></select></label>
                        <label><span>Unit Cost</span><input :value="money(countItem.unit_cost)" disabled /></label>
                    </div>
                    <div v-if="!countItem.product_id" class="drawer-empty-note">Select a product to view warehouse system stock.</div>
                    <div v-else-if="countItem.counted_quantity !== '' && countItem.counted_quantity !== null && !countLineError(countItem)" class="drawer-preview count-preview"><h3>Count Preview</h3><div><span>System Stock</span><b>{{ qty(countItem.system_quantity) }}</b></div><div><span>Physical Count</span><b>{{ qty(countItem.counted_quantity) }}</b></div><div><span>Difference</span><b :class="['qty-change', countLineTone(countItem)]">{{ signedQty(countLineDifference(countItem)) }}</b></div><div><span>Expected Stock After Approval</span><b>{{ qty(countExpectedAfter(countItem)) }}</b></div><div><span>Adjustment</span><strong>{{ countAdjustmentLabel(countItem) }}</strong></div></div>
                    <small v-if="countItem.product_id && countLineError(countItem)" class="line-error">{{ countLineError(countItem) }}</small>
                    <div class="drawer-footer"><button @click="closeCountDrawer">Cancel</button><button class="primary" :disabled="!canAddCountItem" @click="addCountItemFromDrawer">Add to Count</button></div>
                </aside>
            </div>

            <div v-if="transferDrawerOpen" class="drawer-backdrop" @click.self="closeTransferDrawer">
                <aside class="transfer-drawer">
                    <div class="section-head drawer-head"><div><span>TRANSFER ITEM</span><h2>{{ editingTransferIndex === null ? 'Add Transfer Item' : 'Edit Transfer Item' }}</h2><p>Select a product and review the stock movement before adding it.</p></div><button class="icon-close" title="Close" @click="closeTransferDrawer">X</button></div>
                    <div class="drawer-fields">
                        <label class="product-search"><span>Search Product</span><input v-model="transferProductSearch" :disabled="!transferRouteValid" placeholder="Search by product name, SKU or barcode" /><small v-if="transferRouteValid && transferProductSearch.trim().length < 2 && !transferItem.product_id" class="search-helper">Type at least 2 characters to search products.</small><div v-if="transferRouteValid && transferProductResults.length" class="product-results"><button v-for="p in transferProductResults" :key="p.id" type="button" :class="{selected: Number(p.id) === Number(transferItem.product_id)}" @click="selectTransferProduct(p)"><strong>{{ p.name }}</strong><small>SKU: {{ p.sku || '-' }} · Barcode: {{ productBarcode(p) || '-' }} · Unit: {{ productUnit(p) }}</small></button></div></label>
                        <div v-if="transferItem.product_id" class="selected-product"><strong>{{ transferItem.product_name || selectedTransferProduct.name }}</strong><span>SKU: {{ transferItem.sku || selectedTransferProduct.sku || '-' }}</span><span>Barcode: {{ transferItem.barcode || productBarcode(selectedTransferProduct) || '-' }}</span><span>Unit: {{ transferItem.unit || productUnit(selectedTransferProduct) }}</span></div>
                        <label><span>Available Stock</span><input :value="transferItem.product_id ? `${qty(transferItem.current_stock)} ${transferItem.unit || productUnit(selectedTransferProduct)}` : ''" disabled /></label>
                        <label><span>Transfer Quantity</span><input v-model.number="transferItem.requested_quantity" type="number" min="0.001" step="0.001" :disabled="!transferItem.product_id || Number(transferItem.current_stock || 0) <= 0" /></label>
                        <label><span>From Location</span><select v-if="hasSourceLocations" v-model="transferItem.source_location" :disabled="!transferItem.product_id"><option value="">Optional</option><option v-for="loc in sourceLocationOptions" :key="loc.id" :value="locationLabel(loc)">{{ locationLabel(loc) }}</option></select><input v-else value="Optional" disabled /></label>
                        <label><span>To Location</span><select v-if="hasDestinationLocations" v-model="transferItem.destination_location" :disabled="!transferItem.product_id"><option value="">Optional</option><option v-for="loc in destinationLocationOptions" :key="loc.id" :value="locationLabel(loc)">{{ locationLabel(loc) }}</option></select><input v-else value="Optional" disabled /></label>
                        <label v-if="isBatchTracked(transferItem)"><span>Source Batch</span><input v-model="transferItem.source_batch_id" :disabled="!transferItem.product_id" placeholder="Source batch" @change="refreshTransferLineStocks(transferItem)" /></label>
                        <label v-if="isBatchTracked(transferItem)"><span>Destination Batch</span><input v-model="transferItem.destination_batch_id" :disabled="!transferItem.product_id" placeholder="Destination batch" /></label>
                        <label v-if="isSerialTracked(transferItem)"><span>Serial</span><input v-model="transferItem.source_serial_id" :disabled="!transferItem.product_id" placeholder="Select serial" /></label>
                        <label><span>Unit Cost</span><input :value="money(transferItem.unit_cost)" disabled /></label>
                    </div>
                    <div v-if="!transferItem.product_id" class="drawer-empty-note">Select a product to view source stock availability.</div>
                    <div v-else-if="transferQty(transferItem) > 0 && !transferLineError(transferItem)" class="drawer-preview"><h3>Stock Movement Preview</h3><div><span>Source</span><strong>{{ warehouseName(transfer.source_warehouse_id) }}</strong><b>{{ qty(transferItem.current_stock) }} -> {{ qty(sourceAfterTransfer(transferItem)) }}</b><small>{{ signedQty(-transferQty(transferItem)) }}</small></div><div><span>Destination</span><strong>{{ warehouseName(transfer.destination_warehouse_id) }}</strong><b>{{ qty(transferItem.destination_stock) }} -> {{ qty(destinationAfterTransfer(transferItem)) }}</b><small>{{ signedQty(transferQty(transferItem)) }}</small></div><div><span>Total Physical Stock</span><strong>{{ qty(transferPhysicalBefore(transferItem)) }} -> {{ qty(transferPhysicalAfter(transferItem)) }}</strong><small>No physical stock change</small></div></div>
                    <small v-if="transferItem.product_id && transferLineError(transferItem)" class="line-error">{{ transferLineError(transferItem) }}</small>
                    <div class="drawer-footer"><button @click="closeTransferDrawer">Cancel</button><button class="primary" :disabled="!canAddTransferItem" @click="addTransferItemFromDrawer">Add to Transfer</button></div>
                </aside>
            </div>

            <div v-if="showOperationSelector" class="selector-backdrop" @click.self="showOperationSelector = false">
                <section class="operation-selector">
                    <div class="section-head"><div><span>NEW STOCK OPERATION</span><h2>Choose an inventory action</h2><p>Select the workflow that matches why stock is changing.</p></div><button @click="showOperationSelector = false">Close</button></div>
                    <div class="operation-grid">
                        <button v-for="operation in operationCards" :key="operation.key" class="operation-card" @click="selectOperation(operation)">
                            <strong>{{ operation.label }}</strong><span>{{ operation.detail }}</span><small v-for="bullet in operation.bullets" :key="bullet">{{ bullet }}</small>
                        </button>
                    </div>
                </section>
            </div>
        </div>
    </Layout>
</template>


<style scoped>
.inventory-control{padding:0 0 28px}.page-toolbar,.tabs,.actions,.barcode-capture{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.page-toolbar{justify-content:flex-end;margin:-6px 0 14px}.barcode-capture input{width:170px}.tabs{margin-bottom:14px}.tabs button.active{background:#173b77;color:#fff;border-color:#173b77}.panel{margin-bottom:18px;padding:18px;background:#fff;border:1px solid #dfe6ef;border-radius:8px}.section-head{align-items:flex-start;display:flex;justify-content:space-between;gap:12px;margin-bottom:14px}.section-head span{color:#2457d6;font-size:10px;font-weight:900;letter-spacing:1px}.section-head h2{color:#142139;font-size:18px;margin:0}.section-head p{color:#758197;font-size:12px;margin:4px 0 0}.status-pill{background:#edf2ff;border-radius:7px;color:#2457d6;display:inline-flex;font-size:10px;font-weight:800;padding:5px 8px;text-transform:capitalize}.dashboard-stack{display:grid;gap:16px}.kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.kpi-card{align-items:flex-start;display:grid;gap:6px;padding:15px;text-align:left;background:#fff;border:1px solid #e3e9f2;border-left:4px solid #2457d6;border-radius:8px}.kpi-card strong{color:#142139;font-size:21px}.kpi-card span,.cards span{color:#69758a;font-size:11px;font-weight:850;text-transform:uppercase}.kpi-card small{color:#758197}.kpi-card.warning{border-left-color:#d99000}.kpi-card.danger{border-left-color:#d23f49}.kpi-card.info{border-left-color:#0f8b8d}.dashboard-columns{display:grid;grid-template-columns:1.1fr .9fr;gap:16px}.alert-list{display:grid;gap:8px}.alert-row{align-items:center;display:grid;grid-template-columns:1fr auto auto;gap:10px;padding:10px 0;border-bottom:1px solid #edf1f5}.alert-row span{color:#344159;font-weight:800}.alert-row strong{color:#142139}.healthy-state{padding:18px;color:#168757;background:#eaf8f1;border:1px solid #cceedd;border-radius:8px;font-weight:800}.quick-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.quick-grid button{text-align:left}.form-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:10px}.reason-form{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin-bottom:10px}.reason-form label{display:grid;align-content:start;gap:6px;color:#344159;font-size:12px;font-weight:800}.reason-form label span{color:#69758a;font-size:10px;font-weight:900;text-transform:uppercase}.reason-form small{color:#758197;font-size:11px;font-weight:600;line-height:1.4}.hint-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:0 0 16px}.hint-grid span,.voucher-help-card{background:#f8fafc;border:1px dashed #d9e2ef;border-radius:8px;color:#6f7b90;font-size:11px;font-weight:650;line-height:1.45;padding:9px 11px}.voucher-help-card{margin:10px 0 12px}.voucher-help-card strong{color:#27344c;display:block;font-size:12px;margin-bottom:6px}.voucher-help-card ul{margin:0;padding-left:17px}.voucher-help-card li{margin:3px 0}.line-head,.line-grid{display:grid;gap:8px;align-items:center;margin-bottom:8px}.line-head{color:#69758a;font-size:10px;font-weight:800;text-transform:uppercase}.adjustment-row,.line-head{grid-template-columns:1.5fr .7fr .8fr .65fr .75fr .7fr 1fr 1fr .7fr}.count-row,.count-head{grid-template-columns:1.5fr .75fr .75fr .75fr .75fr .7fr .9fr .9fr .65fr}.transfer-row,.transfer-head{grid-template-columns:1.4fr .65fr .65fr .65fr .65fr .8fr .8fr .8fr .8fr .9fr .9fr .65fr}.location-row{grid-template-columns:1.8fr .8fr 1fr 1fr .7fr}.posting-summary{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:12px 0;padding:12px;background:#f8fafc;border:1px solid #e5eaf2;border-radius:8px;color:#344159;font-size:12px}.posting-summary strong{color:#142139}.actions{justify-content:flex-end;margin:12px 0}.actions.inline{margin:0}.secondary{border-top:1px solid #edf1f5;margin-top:20px;padding-top:16px}.report-tabs{margin-bottom:10px}input,select,textarea,button{min-height:38px;padding:8px 10px;color:#344159;background:#fff;border:1px solid #d8e0eb;border-radius:8px;font-size:12px}textarea{min-height:38px}button{font-weight:750;cursor:pointer}.primary{color:#fff;background:#2457d6;border-color:#2457d6}.alert{padding:10px 12px;margin-bottom:12px;border-radius:8px;background:#fff4f4;color:#b42318;border:1px solid #ffd5d5;font-size:12px}.empty{color:#8490a2;text-align:center}.table-wrapper{overflow-x:auto}table{width:100%;border-collapse:collapse;margin-top:12px}tr{cursor:pointer}th,td{padding:11px 10px;border-bottom:1px solid #edf1f5;text-align:left;white-space:nowrap;font-size:12px}th{color:#69758a;background:#f8fafc;font-size:10px;text-transform:uppercase}.stock-negative{color:#d23f49!important}.qty-change.positive{color:#168757;font-weight:900}.qty-change.negative{color:#d23f49;font-weight:900}.qty-change.neutral{color:#344159;font-weight:900}.selector-backdrop{position:fixed;z-index:1000;inset:0;display:grid;place-items:center;padding:20px;background:rgba(15,23,42,.42)}.operation-selector{width:min(980px,100%);max-height:calc(100vh - 40px);overflow:auto;padding:18px;background:#fff;border:1px solid #dfe6ef;border-radius:8px;box-shadow:0 24px 70px rgba(15,23,42,.22)}.operation-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.operation-card{display:grid;align-content:start;gap:8px;min-height:170px;padding:14px;text-align:left;border:1px solid #dfe6ef}.operation-card strong{color:#142139;font-size:15px}.operation-card span{color:#536174;line-height:1.45}.operation-card small{color:#69758a;background:#f8fafc;border-radius:6px;padding:5px 7px}@media(max-width:1000px){.kpi-grid,.dashboard-columns,.form-grid,.reason-form,.hint-grid,.line-grid,.line-head,.adjustment-row,.count-row,.transfer-row,.location-row,.operation-grid{grid-template-columns:1fr}.page-toolbar{justify-content:flex-start}.barcode-capture input{width:100%}.quick-grid{grid-template-columns:1fr}.section-head{flex-direction:column}}
.adjustment-row,.adjustment-head{grid-template-columns:minmax(220px,1.6fr) minmax(105px,.75fr) minmax(130px,.9fr) minmax(90px,.65fr) minmax(150px,.95fr) minmax(110px,.75fr) minmax(120px,.9fr) minmax(180px,1.1fr) minmax(90px,.65fr)}.line-field{display:grid;gap:5px;min-width:0}.line-field span{display:none;color:#69758a;font-size:10px;font-weight:900;text-transform:uppercase}.line-field input,.line-field select,.remove-line{width:100%;min-width:0}.product-field select{min-width:0}.condition-transfer-fields{display:grid;grid-template-columns:1fr 1fr;gap:6px}.condition-transfer-help{margin:8px 0 10px;padding:10px 12px;border:1px solid #bfdbfe;border-radius:8px;background:#eff6ff;color:#1d4ed8;font-size:12px;font-weight:750}.transfer-physical-note{align-content:center;min-height:38px;padding:7px 10px;border:1px dashed #d8e0eb;border-radius:8px;background:#f8fafc}.transfer-physical-note strong{color:#142139;font-size:12px}.result-field input{font-weight:850;color:#142139}@media(max-width:1000px){.adjustment-head{display:none}.adjustment-row{align-items:stretch;padding:12px;background:#fff;border:1px solid #e3e9f2;border-radius:8px;grid-template-columns:1fr}.line-field span{display:block}.remove-line{justify-self:start;width:auto}.condition-transfer-fields{grid-template-columns:1fr}}
.transfer-route{display:grid;grid-template-columns:minmax(0,1fr) 42px minmax(0,1fr);align-items:stretch;gap:12px;margin-bottom:12px}.route-side{display:grid;grid-template-columns:auto minmax(0,1fr) minmax(0,1fr);align-items:end;gap:10px;padding:12px;background:#f8fafc;border:1px solid #e3e9f2;border-radius:8px}.route-side strong{align-self:center;color:#2457d6;font-size:11px;font-weight:900;letter-spacing:.8px}.route-side label,.transfer-meta label,.transfer-main-grid label,.tracking-box label{display:grid;gap:5px;min-width:0;color:#344159;font-size:12px;font-weight:800}.route-side span,.transfer-meta span,.transfer-main-grid span,.tracking-box span,.transfer-preview span{color:#69758a;font-size:10px;font-weight:900;text-transform:uppercase}.route-arrow{display:grid;place-items:center;color:#2457d6;font-weight:900}.transfer-meta label{margin:0}.transfer-items-card{margin:14px 0;padding:14px;background:#fbfcfe;border:1px solid #e3e9f2;border-radius:8px}.transfer-item{display:grid;gap:12px;margin-bottom:12px;padding:14px;background:#fff;border:1px solid #dfe6ef;border-radius:8px}.transfer-main-grid{display:grid;grid-template-columns:minmax(260px,1.8fr) minmax(130px,.75fr) minmax(130px,.75fr) minmax(130px,.75fr);gap:10px}.location-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.tracking-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.tracking-box{display:grid;grid-template-columns:140px minmax(0,1fr) minmax(0,1fr);align-items:end;gap:10px;padding:11px;background:#f8fafc;border:1px dashed #d8e0eb;border-radius:8px}.tracking-box strong{align-self:center;color:#27344c;font-size:12px}.transfer-preview{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px}.transfer-preview div{display:grid;gap:4px;padding:10px;background:#f8fafc;border:1px solid #edf1f5;border-radius:8px}.transfer-preview strong{color:#142139;font-size:14px}.transfer-item-footer{display:flex;align-items:center;justify-content:space-between;gap:10px}.line-error{color:#b42318;font-weight:800}.transfer-summary{justify-content:flex-end}.transfer-row,.transfer-head{grid-template-columns:none}@media(max-width:1180px){.transfer-main-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.transfer-preview{grid-template-columns:repeat(3,minmax(0,1fr))}.route-side,.tracking-box{grid-template-columns:1fr}}@media(max-width:760px){.transfer-route,.tracking-grid,.location-grid,.transfer-main-grid,.transfer-preview{grid-template-columns:1fr}.route-arrow{display:none}.transfer-item-footer{align-items:flex-start;flex-direction:column}.transfer-summary{justify-content:flex-start}}
.transfer-page,.count-page{display:grid;gap:14px;min-width:0}.transfer-card,.count-card{margin-bottom:0;min-width:0}.transfer-view-tabs{margin-bottom:0}.transfer-meta{grid-template-columns:repeat(3,minmax(0,1fr))}.transfer-remarks{grid-column:auto}.count-details-grid{grid-template-columns:repeat(4,minmax(0,1fr))}.count-details-grid label{display:grid;gap:5px;min-width:0;color:#344159;font-size:12px;font-weight:800}.count-details-grid label span{color:#69758a;font-size:10px;font-weight:900;text-transform:uppercase}.count-freeze{grid-column:span 2;align-content:start;padding:10px 12px;background:#f8fafc;border:1px dashed #d8e0eb;border-radius:8px}.count-freeze input{min-height:auto}.count-freeze small{color:#758197;font-weight:650}.count-remarks{grid-column:span 2}.count-summary{justify-content:flex-start}.transfer-empty-state,.drawer-empty-note{display:grid;justify-items:center;gap:8px;padding:24px;background:#f8fafc;border:1px dashed #d8e0eb;border-radius:8px;color:#69758a;text-align:center}.transfer-empty-state strong{color:#142139;font-size:15px}.transfer-empty-state span,.drawer-empty-note{font-size:12px;font-weight:750}.transfer-action-bar{position:sticky;bottom:0;z-index:50;display:flex;justify-content:flex-end;gap:10px;margin:0 -18px;padding:12px 18px;background:rgba(255,255,255,.96);border-top:1px solid #dfe6ef;box-shadow:0 -10px 28px rgba(15,23,42,.08)}.transfer-history{margin-top:8px}.sticky-register-table{max-height:calc(100vh - 355px);min-height:320px;overflow:auto;border:1px solid #edf1f5;border-radius:8px}.sticky-register-table table{min-width:1480px;margin-top:0}.sticky-register-table th{position:sticky;top:0;z-index:4}.sticky-register-table th:first-child,.sticky-register-table td:first-child{position:sticky;left:0;z-index:3;min-width:145px;background:#fff;box-shadow:8px 0 14px rgba(15,23,42,.05)}.sticky-register-table th:first-child{z-index:5;background:#f8fafc}.sticky-register-table th:last-child,.sticky-register-table td:last-child{position:sticky;right:0;z-index:3;min-width:185px;background:#fff;box-shadow:-8px 0 14px rgba(15,23,42,.05)}.sticky-register-table th:last-child{z-index:5;background:#f8fafc}.sticky-register-table td:last-child{display:flex;gap:6px;align-items:center}.drawer-backdrop{position:fixed;z-index:1100;inset:0;display:flex;justify-content:flex-end;background:rgba(15,23,42,.32)}.transfer-drawer{display:flex;flex-direction:column;width:min(520px,100%);height:100%;padding:18px 18px 0;background:#fff;border-left:1px solid #dfe6ef;box-shadow:-18px 0 50px rgba(15,23,42,.18);overflow:hidden}.drawer-head{flex:0 0 auto}.icon-close{min-height:34px;width:38px;padding:0}.drawer-fields{display:grid;gap:10px;overflow:auto;padding-bottom:12px}.drawer-fields label{display:grid;gap:5px;color:#344159;font-size:12px;font-weight:800}.drawer-fields span,.drawer-preview span{color:#69758a;font-size:10px;font-weight:900;text-transform:uppercase}.product-search{position:relative}.product-results{display:grid;max-height:230px;overflow:auto;border:1px solid #e3e9f2;border-radius:8px;background:#fff}.product-results button{display:grid;gap:3px;min-height:auto;padding:10px;text-align:left;border:0;border-bottom:1px solid #edf1f5;border-radius:0}.product-results button.selected{background:#edf2ff}.product-results strong,.selected-product strong{color:#142139}.product-results small,.selected-product span,td small,.search-helper{display:block;color:#69758a;font-size:11px;font-weight:650}.selected-product{display:grid;gap:4px;padding:11px;background:#f8fafc;border:1px solid #e3e9f2;border-radius:8px}.drawer-preview{display:grid;gap:8px;margin:14px 0}.drawer-preview h3{margin:0;color:#142139;font-size:14px}.drawer-preview div{display:grid;grid-template-columns:1fr auto;gap:4px 8px;padding:11px;background:#f8fafc;border:1px solid #edf1f5;border-radius:8px}.drawer-preview span,.drawer-preview strong{grid-column:1}.drawer-preview b{grid-column:2;grid-row:1 / span 2;color:#142139;font-size:16px;align-self:center}.count-preview div{grid-template-columns:1fr auto}.drawer-preview small{color:#168757;font-weight:800}.drawer-empty-note{justify-items:start;margin:14px 0;text-align:left}.drawer-footer{position:sticky;bottom:0;display:flex;justify-content:flex-end;gap:10px;margin:0 -18px;padding:12px 18px;background:#fff;border-top:1px solid #dfe6ef;box-shadow:0 -10px 24px rgba(15,23,42,.08)}@media(max-width:1180px){.transfer-meta,.count-details-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:760px){.transfer-meta,.count-details-grid{grid-template-columns:1fr}.count-freeze,.count-remarks{grid-column:auto}.transfer-action-bar{justify-content:flex-start;flex-wrap:wrap}.transfer-drawer{width:100%}.drawer-preview div{grid-template-columns:1fr}.drawer-preview b{grid-column:auto;grid-row:auto}.sticky-register-table{max-height:calc(100vh - 300px)}}
</style>
