<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import Layout from '../Layout.vue';
import InventoryApi from './InventoryApi';
import AppToast from '../../Components/Common/AppToast.vue';
import TableLoadingState from '../../Components/Common/TableLoadingState.vue';
import InventoryModuleScaffold from './Shared/InventoryModuleScaffold.vue';
import InventoryTable from './Shared/InventoryTable.vue';
import InventoryModal from './Shared/InventoryModal.vue';

defineProps({ page: { type: String, default: 'inventory-manufacturing' }, title: { type: String, default: 'Manufacturing / BOM' } });

const loading = ref(false);
const initialLoaded = ref(false);
const saving = ref(false);
const tab = ref('bom');
const refs = ref({ products: [], branches: [], warehouses: [], units: [], boms: [] });
const dashboard = ref({ bom: {}, production: {} });
const boms = ref([]);
const orders = ref([]);
const reports = ref({});
const requirements = ref([]);
const activeReport = ref('bom_report');
const modal = ref(null);
const current = ref(null);
const toast = ref(null);
const errors = ref({});
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });
const orderPagination = ref({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });
const filters = ref({ search: '', product_id: '', status: '', branch_id: '', per_page: 15 });
const bomForm = ref({ bom_name: '', bom_code: '', finished_product_id: '', finished_product_variant_id: '', output_quantity: 1, unit_id: '', wastage_percentage: 0, status: 'draft', effective_from: '', effective_to: '', notes: '', items: [{ raw_material_product_id: '', quantity_required: 1, unit_id: '', wastage_percentage: 0, warehouse_id: '', batch_selection_method: 'fefo', remarks: '' }] });
const orderForm = ref({ bom_id: '', branch_id: '', source_warehouse_id: '', finished_goods_warehouse_id: '', planned_quantity: 1, start_date: '', expected_completion_date: '', status: 'draft', notes: '', additional_cost: 0, manufacturing_date: '', expiry_date: '' });
const completeForm = ref({ produced_quantity: 1, rejected_quantity: 0, additional_cost: 0, finished_batch_number: '', manufacturing_date: '', expiry_date: '', items: {} });
let timer = null;

const money = (v) => Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const qty = (v) => Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
const statusLabel = (v) => String(v || '-').replaceAll('_', ' ');
const showToast = (message, type = 'success') => { toast.value = { title: 'Manufacturing', message, type }; };
const cards = computed(() => tab.value === 'bom' ? [
    { label: 'Total BOMs', value: dashboard.value.bom?.total_boms || 0, tone: 'info' },
    { label: 'Active BOMs', value: dashboard.value.bom?.active_boms || 0, tone: 'good' },
    { label: 'Draft BOMs', value: dashboard.value.bom?.draft_boms || 0, tone: 'warn' },
    { label: 'Finished Products', value: dashboard.value.bom?.finished_products || 0, tone: 'money' },
    { label: 'Raw Materials Used', value: dashboard.value.bom?.raw_materials_used || 0, tone: 'info' },
] : [
    { label: 'Planned Orders', value: dashboard.value.production?.planned_orders || 0, tone: 'info' },
    { label: 'In Progress', value: dashboard.value.production?.in_progress || 0, tone: 'warn' },
    { label: 'Completed Today', value: dashboard.value.production?.completed_today || 0, tone: 'good' },
    { label: 'Material Shortage', value: dashboard.value.production?.material_shortage || 0, tone: 'bad' },
    { label: 'Produced Quantity', value: qty(dashboard.value.production?.produced_quantity), tone: 'money' },
    { label: 'Production Value', value: `Rs. ${money(dashboard.value.production?.production_value)}`, tone: 'money' },
]);
const currentRows = computed(() => tab.value === 'bom' ? boms.value : orders.value);
const currentPagination = computed(() => tab.value === 'bom' ? pagination.value : orderPagination.value);
const reportRows = computed(() => reports.value?.[activeReport.value] || []);
const filteredWarehouses = computed(() => !orderForm.value.branch_id ? refs.value.warehouses : refs.value.warehouses.filter((w) => Number(w.branch_id || 0) === Number(orderForm.value.branch_id)));
const selectedBom = computed(() => refs.value.boms.find((b) => Number(b.id) === Number(orderForm.value.bom_id)));

const bomColumns = [{ key: 'bom_code', label: 'BOM Code' }, { key: 'bom_name', label: 'BOM Name' }, { key: 'finished_product', label: 'Finished Product' }, { key: 'version', label: 'Version' }, { key: 'output_quantity', label: 'Output Qty' }, { key: 'components', label: 'Components' }, { key: 'effective_date', label: 'Effective Date' }, { key: 'status', label: 'Status' }];
const orderColumns = [{ key: 'order_number', label: 'Order Number' }, { key: 'created_at', label: 'Date' }, { key: 'finished_product', label: 'Finished Product' }, { key: 'bom_version', label: 'BOM Version' }, { key: 'branch', label: 'Branch' }, { key: 'warehouse', label: 'Warehouse' }, { key: 'planned_quantity', label: 'Planned Qty' }, { key: 'produced_quantity', label: 'Produced Qty' }, { key: 'material_status', label: 'Material Status' }, { key: 'production_cost', label: 'Production Cost' }, { key: 'status', label: 'Status' }];
const displayRows = computed(() => currentRows.value.map((row) => {
    if (tab.value === 'bom') {
        return {
            ...row,
            finished_product: row.finished_product?.name || '-',
            output_quantity: qty(row.output_quantity),
            components: row.items?.length || 0,
            effective_date: `${row.effective_from || '-'} to ${row.effective_to || '-'}`,
        };
    }

    return {
        ...row,
        created_at: String(row.created_at || '').slice(0, 10),
        finished_product: row.finished_product?.name || '-',
        branch: row.branch?.name || '-',
        warehouse: row.finished_warehouse?.name || row.finishedWarehouse?.name || '-',
        planned_quantity: qty(row.planned_quantity),
        produced_quantity: qty(row.produced_quantity),
        material_status: row.items?.some((i) => i.availability_status === 'shortage') ? 'shortage' : 'available',
        production_cost: `Rs. ${money(row.production_cost)}`,
    };
}));

const loadRefs = async () => { refs.value = await InventoryApi.manufacturingReferences(); };
const load = async (page = 1) => {
    loading.value = true;
    try {
        const [dash, bomList, orderList, reportData] = await Promise.all([InventoryApi.manufacturingDashboard(filters.value), InventoryApi.bomList({ ...filters.value, page: tab.value === 'bom' ? page : 1 }), InventoryApi.productionOrders({ ...filters.value, page: tab.value !== 'bom' ? page : 1 }), InventoryApi.manufacturingReports(filters.value)]);
        dashboard.value = dash || { bom: {}, production: {} };
        boms.value = bomList.items || [];
        orders.value = orderList.items || [];
        pagination.value = bomList.pagination || pagination.value;
        orderPagination.value = orderList.pagination || orderPagination.value;
        reports.value = reportData || {};
        refs.value.boms = (refs.value.boms?.length ? refs.value.boms : boms.value).filter((b) => b.status === 'active');
        initialLoaded.value = true;
    } finally { loading.value = false; }
};
const clearFilters = () => { filters.value = { search: '', product_id: '', status: '', branch_id: '', per_page: 15 }; };
const resetBom = () => { bomForm.value = { bom_name: '', bom_code: '', finished_product_id: '', finished_product_variant_id: '', output_quantity: 1, unit_id: '', wastage_percentage: 0, status: 'draft', effective_from: '', effective_to: '', notes: '', items: [{ raw_material_product_id: '', quantity_required: 1, unit_id: '', wastage_percentage: 0, warehouse_id: '', batch_selection_method: 'fefo', remarks: '' }] }; };
const resetOrder = () => { orderForm.value = { bom_id: '', branch_id: '', source_warehouse_id: '', finished_goods_warehouse_id: '', planned_quantity: 1, start_date: '', expected_completion_date: '', status: 'draft', notes: '', additional_cost: 0, manufacturing_date: '', expiry_date: '' }; };
const openBom = (row = null) => { errors.value = {}; current.value = row; resetBom(); if (row) bomForm.value = { ...bomForm.value, ...row, finished_product_id: row.finished_product_id, items: row.items?.map((i) => ({ ...i })) || bomForm.value.items }; modal.value = 'bom'; };
const openOrder = (row = null) => { errors.value = {}; current.value = row; resetOrder(); if (row) orderForm.value = { ...orderForm.value, ...row }; modal.value = 'order'; };
const saveBom = async () => { errors.value = {}; saving.value = true; try { await InventoryApi.saveBom(bomForm.value, current.value?.id || null); showToast('BOM saved.'); modal.value = null; await load(); await loadRefs(); } catch (e) { errors.value = e?.response?.data?.errors || {}; showToast(e?.response?.data?.message || 'BOM save failed.', 'error'); } finally { saving.value = false; } };
const saveOrder = async () => { errors.value = {}; saving.value = true; try { await InventoryApi.saveProductionOrder(orderForm.value, current.value?.id || null); showToast('Production order saved.'); modal.value = null; await load(); } catch (e) { errors.value = e?.response?.data?.errors || {}; showToast(e?.response?.data?.message || 'Order save failed.', 'error'); } finally { saving.value = false; } };
const duplicateBom = async (row) => { await InventoryApi.duplicateBom(row.id); showToast('New BOM version created.'); await load(); };
const activateBom = async (row, active) => { await InventoryApi.activateBom(row.id, active); showToast('BOM status updated.'); await load(); await loadRefs(); };
const createOrderFromBom = (row) => { resetOrder(); orderForm.value.bom_id = row.id; orderForm.value.planned_quantity = row.output_quantity; tab.value = 'orders'; modal.value = 'order'; };
const checkMaterials = async (row) => { const result = await InventoryApi.checkProductionMaterials(row.id); requirements.value = result.requirements || []; current.value = row; modal.value = 'materials'; showToast(result.has_shortage ? 'Material shortage found.' : 'Materials are available.', result.has_shortage ? 'error' : 'success'); };
const transition = async (row, status) => { try { await InventoryApi.transitionProductionOrder(row.id, status); showToast('Order status updated.'); await load(); } catch (e) { showToast(e?.response?.data?.message || 'Status update failed.', 'error'); } };
const openComplete = async (row) => { current.value = row; completeForm.value = { produced_quantity: Number(row.planned_quantity || 1), rejected_quantity: 0, additional_cost: Number(row.additional_cost || 0), finished_batch_number: `MFG-${row.order_number}`, manufacturing_date: new Date().toISOString().slice(0, 10), expiry_date: '', items: {} }; await checkMaterials(row); modal.value = 'complete'; };
const completeOrder = async () => { errors.value = {}; saving.value = true; try { await InventoryApi.completeProductionOrder(current.value.id, completeForm.value); showToast('Production completed and posted.'); modal.value = null; await load(); } catch (e) { errors.value = e?.response?.data?.errors || {}; showToast(e?.response?.data?.message || 'Completion failed.', 'error'); } finally { saving.value = false; } };
const printRow = (row) => window.open('', '_blank')?.document.write(`<html><body style="font-family:Arial;padding:24px"><h2>${row.bom_code || row.order_number}</h2><pre>${JSON.stringify(row, null, 2)}</pre></body></html>`);
const exportRows = (format) => {
    if (format === 'pdf') { window.print(); return; }
    const source = activeReport.value === 'bom_report' ? boms.value : reportRows.value;
    const data = source.map((r) => ({ number: r.bom_code || r.order_number || r.id, product: r.finished_product?.name || r.product?.name || r.raw_material?.name || '', quantity: r.output_quantity || r.planned_quantity || r.quantity_in || r.quantity, cost: r.production_cost || r.stock_value || r.total_cost, status: r.status || r.availability_status }));
    const csv = [Object.keys(data[0] || { report: activeReport.value }).join(','), ...data.map((r) => Object.values(r).map((v) => `"${String(v ?? '').replace(/"/g, '""')}"`).join(','))].join('\n');
    const link = document.createElement('a'); link.href = URL.createObjectURL(new Blob([csv], { type: format === 'excel' ? 'application/vnd.ms-excel' : 'text/csv;charset=utf-8;' })); link.download = `manufacturing-${activeReport.value}.${format === 'excel' ? 'xls' : 'csv'}`; link.click(); URL.revokeObjectURL(link.href);
};
const addBomLine = () => bomForm.value.items.push({ raw_material_product_id: '', quantity_required: 1, unit_id: '', wastage_percentage: 0, warehouse_id: '', batch_selection_method: 'fefo', remarks: '' });

watch(filters, () => { clearTimeout(timer); timer = setTimeout(() => load(1), 350); }, { deep: true });
watch(tab, () => load(1));
onMounted(async () => { await loadRefs(); await load(); });
</script>

<template>
    <Layout :page="page" :title="title">
        <template #topbar-title><div class="bill-page-title"><span>INVENTORY CONTROL</span><h1>Manufacturing / BOM</h1><p>Manage BOM versions, production orders, material requirements, wastage and ledger-backed finished goods posting.</p></div></template>
        <AppToast v-if="toast" show :title="toast.title" :message="toast.message" :type="toast.type" />
        <TableLoadingState v-if="loading && !initialLoaded" title="Loading Manufacturing..." description="Preparing BOMs, production orders and material status." />
        <InventoryModuleScaffold v-else :title="tab === 'bom' ? 'BOM Register' : tab === 'orders' ? 'Production Orders' : 'Manufacturing Reports'" subtitle="Optional manufacturing workflow controlled by inventory permissions." :cards="cards" :loading="loading" :initial-loaded="initialLoaded" :pagination="currentPagination" @page="load">
            <template #toolbar><button @click="tab='bom'">BOM</button><button @click="tab='orders'">Production Orders</button><button @click="tab='materials'">Material Requirements</button><button @click="tab='history'">Production History</button><button @click="tab='wastage'">Wastage/Scrap</button><button @click="openBom()">New BOM</button><button @click="openOrder()">New Order</button><button @click="exportRows('csv')">CSV</button><button @click="exportRows('excel')">Excel</button><button @click="exportRows('pdf')">PDF</button></template>
            <template #filters><input v-model="filters.search" placeholder="Search code, order, product" /><select v-model="filters.product_id"><option value="">All Products</option><option v-for="p in refs.products" :key="p.id" :value="p.id">{{ p.name }}</option></select><select v-model="filters.status"><option value="">All Status</option><option v-for="s in refs.statuses" :key="s" :value="s">{{ statusLabel(s) }}</option></select><select v-model="filters.branch_id"><option value="">All Branches</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select><select v-model="filters.per_page"><option :value="15">15 / page</option><option :value="25">25 / page</option><option :value="50">50 / page</option></select><button @click="clearFilters">Clear</button></template>
            <InventoryTable v-if="tab === 'bom'" :columns="bomColumns" :rows="displayRows" empty-text="No BOMs found.">
                <template #cell-status="{ value }"><span class="status" :class="value">{{ statusLabel(value) }}</span></template>
                <template #actions="{ row }"><div class="row-actions"><button @click="current=row; modal='bomDetail'">View</button><button v-if="row.status === 'draft'" @click="openBom(row)">Edit Draft</button><button @click="duplicateBom(row)">New Version</button><button @click="activateBom(row, row.status !== 'active')">{{ row.status === 'active' ? 'Deactivate' : 'Activate' }}</button><button @click="createOrderFromBom(row)">Create Order</button><button @click="printRow(row)">Print</button><button @click="current=row; modal='bomDetail'">History</button></div></template>
            </InventoryTable>
            <InventoryTable v-else-if="tab === 'orders'" :columns="orderColumns" :rows="displayRows" empty-text="No production orders found.">
                <template #cell-status="{ value }"><span class="status" :class="value">{{ statusLabel(value) }}</span></template>
                <template #actions="{ row }"><div class="row-actions"><button @click="current=row; modal='orderDetail'">View</button><button v-if="row.status === 'draft'" @click="openOrder(row)">Edit Draft</button><button @click="checkMaterials(row)">Check Materials</button><button v-if="row.status === 'planned'" @click="transition(row,'material_reserved')">Reserve</button><button v-if="['planned','material_reserved'].includes(row.status)" @click="transition(row,'in_progress')">Start</button><button v-if="['planned','material_reserved','in_progress'].includes(row.status)" @click="openComplete(row)">Complete/Post</button><button v-if="row.status !== 'completed'" @click="transition(row,'cancelled')">Cancel</button><button @click="printRow(row)">Print</button></div></template>
            </InventoryTable>
            <InventoryTable v-else-if="tab === 'materials'" :columns="[{key:'raw_material',label:'Raw Material'},{key:'required_quantity',label:'Required Qty'},{key:'available_quantity',label:'Available Qty'},{key:'shortage_quantity',label:'Shortage Qty'},{key:'selected_batch',label:'Selected Batch'},{key:'unit_cost',label:'Unit Cost'},{key:'total_cost',label:'Total Cost'},{key:'availability_status',label:'Status'}]" :rows="requirements" empty-text="Use Check Materials on a production order." />
            <InventoryTable v-else :columns="[{key:'id',label:'ID'},{key:'status',label:'Status'},{key:'production_cost',label:'Cost'},{key:'created_at',label:'Date'}]" :rows="tab === 'wastage' ? (reports.wastage_scrap || []) : (reports.production_register || [])" empty-text="No production history found." />
            <section class="reports"><div class="tabs"><button v-for="r in ['bom_report','production_register','material_consumption','finished_goods','wastage_scrap','production_cost','material_shortage']" :key="r" :class="{active: activeReport === r}" @click="activeReport = r">{{ statusLabel(r) }}</button></div></section>
        </InventoryModuleScaffold>

        <InventoryModal v-if="modal === 'bom'" title="BOM" wide :errors="errors" @close="modal = null">
            <div class="form-grid"><input v-model="bomForm.bom_code" placeholder="BOM code auto if blank" /><input v-model="bomForm.bom_name" placeholder="BOM name" /><select v-model="bomForm.finished_product_id"><option value="">Finished product</option><option v-for="p in refs.products" :key="p.id" :value="p.id">{{ p.name }}</option></select><input v-model.number="bomForm.output_quantity" type="number" step="0.001" /><select v-model="bomForm.unit_id"><option value="">Unit</option><option v-for="u in refs.units" :key="u.id" :value="u.id">{{ u.code || u.name }}</option></select><input v-model.number="bomForm.wastage_percentage" type="number" step="0.001" /><input v-model="bomForm.effective_from" type="date" /><input v-model="bomForm.effective_to" type="date" /><textarea v-model="bomForm.notes" placeholder="Notes"></textarea></div>
            <h3>Components</h3><div class="line-grid head"><span>Raw Material</span><span>Qty</span><span>Unit</span><span>Wastage %</span><span>Warehouse</span><span>Batch</span><span></span></div>
            <div v-for="(item,index) in bomForm.items" :key="index" class="line-grid"><select v-model="item.raw_material_product_id"><option value="">Raw material</option><option v-for="p in refs.products" :key="p.id" :value="p.id">{{ p.name }}</option></select><input v-model.number="item.quantity_required" type="number" step="0.001" /><select v-model="item.unit_id"><option value="">Unit</option><option v-for="u in refs.units" :key="u.id" :value="u.id">{{ u.code || u.name }}</option></select><input v-model.number="item.wastage_percentage" type="number" step="0.001" /><select v-model="item.warehouse_id"><option value="">Default warehouse</option><option v-for="w in refs.warehouses" :key="w.id" :value="w.id">{{ w.name }}</option></select><select v-model="item.batch_selection_method"><option>fefo</option><option>fifo</option><option>manual</option></select><button :disabled="bomForm.items.length===1" @click="bomForm.items.splice(index,1)">Remove</button></div>
            <footer class="modal-actions"><button @click="addBomLine">Add Component</button><button @click="modal = null">Cancel</button><button class="primary" :disabled="saving" @click="saveBom">Save BOM</button></footer>
        </InventoryModal>

        <InventoryModal v-if="modal === 'order'" title="Production Order" :errors="errors" @close="modal = null">
            <div class="form-grid"><select v-model="orderForm.bom_id"><option value="">BOM</option><option v-for="b in refs.boms" :key="b.id" :value="b.id">{{ b.bom_code }} - {{ b.bom_name }}</option></select><select v-model="orderForm.branch_id"><option value="">Branch</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select><select v-model="orderForm.source_warehouse_id"><option value="">Source warehouse</option><option v-for="w in filteredWarehouses" :key="w.id" :value="w.id">{{ w.name }}</option></select><select v-model="orderForm.finished_goods_warehouse_id"><option value="">Finished goods warehouse</option><option v-for="w in filteredWarehouses" :key="w.id" :value="w.id">{{ w.name }}</option></select><input v-model.number="orderForm.planned_quantity" type="number" step="0.001" /><input v-model="orderForm.start_date" type="date" /><input v-model="orderForm.expected_completion_date" type="date" /><input v-model.number="orderForm.additional_cost" type="number" step="0.01" /><textarea v-model="orderForm.notes" placeholder="Notes"></textarea></div>
            <p class="hint" v-if="selectedBom">BOM output: {{ qty(selectedBom.output_quantity) }} | Version {{ selectedBom.version }}</p>
            <footer class="modal-actions"><button @click="modal = null">Cancel</button><button class="primary" :disabled="saving" @click="saveOrder">Save Order</button></footer>
        </InventoryModal>

        <InventoryModal v-if="modal === 'materials'" title="Material Requirements" :subtitle="current?.order_number" wide @close="modal = null">
            <InventoryTable :columns="[{key:'raw_material',label:'Raw Material'},{key:'required_quantity',label:'Required Qty'},{key:'available_quantity',label:'Available Qty'},{key:'shortage_quantity',label:'Shortage Qty'},{key:'selected_batch',label:'Selected Batch'},{key:'unit_cost',label:'Unit Cost'},{key:'total_cost',label:'Total Cost'},{key:'availability_status',label:'Status'}]" :rows="requirements" />
        </InventoryModal>

        <InventoryModal v-if="modal === 'complete'" title="Complete Production" :subtitle="current?.order_number" wide :errors="errors" @close="modal = null">
            <div class="form-grid"><input v-model.number="completeForm.produced_quantity" type="number" step="0.001" placeholder="Produced qty" /><input v-model.number="completeForm.rejected_quantity" type="number" step="0.001" placeholder="Rejected qty" /><input v-model.number="completeForm.additional_cost" type="number" step="0.01" placeholder="Additional cost" /><input v-model="completeForm.finished_batch_number" placeholder="Finished batch" /><input v-model="completeForm.manufacturing_date" type="date" /><input v-model="completeForm.expiry_date" type="date" /></div>
            <InventoryTable :columns="[{key:'raw_material',label:'Raw Material'},{key:'required_quantity',label:'Required Qty'},{key:'available_quantity',label:'Available Qty'},{key:'shortage_quantity',label:'Shortage Qty'},{key:'unit_cost',label:'Unit Cost'},{key:'availability_status',label:'Status'}]" :rows="requirements" />
            <footer class="modal-actions"><button @click="modal = null">Cancel</button><button class="primary" :disabled="saving" @click="completeOrder">Complete/Post</button></footer>
        </InventoryModal>
    </Layout>
</template>

<style scoped>
input,select,textarea,button{background:#fff;border:1px solid #d8e0eb;border-radius:8px;color:#344159;font-size:12px;font-weight:750;min-height:38px;padding:8px 10px}.row-actions{display:flex;flex-wrap:wrap;gap:6px}.row-actions button{min-height:30px;padding:5px 8px}.status{background:#edf2ff;border-radius:7px;color:#2457d6;display:inline-flex;font-size:10px;font-weight:800;padding:5px 8px;text-transform:capitalize}.status.active,.status.completed{background:#f0fdf4;color:#166534}.status.draft,.status.planned{background:#fff7ed;color:#c2410c}.status.cancelled,.status.shortage{background:#fff1f2;color:#be123c}.form-grid{display:grid;gap:10px;grid-template-columns:repeat(2,minmax(0,1fr));margin-bottom:14px}.form-grid textarea{grid-column:1/-1}.line-grid{align-items:center;display:grid;gap:8px;grid-template-columns:1.4fr .6fr .7fr .7fr 1fr .7fr .7fr;margin-bottom:8px}.head{color:#69758a;font-size:10px;font-weight:800;text-transform:uppercase}.modal-actions{border-top:1px solid #edf1f5;display:flex;gap:10px;justify-content:flex-end;margin-top:18px;padding-top:14px}.primary{background:#2563eb;color:#fff}.hint{background:#f8fafc;border:1px dashed #d9e2ef;border-radius:8px;color:#6f7b90;font-size:12px;font-weight:700;padding:9px}.tabs{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}.tabs .active{background:#173b77;color:#fff}@media(max-width:900px){.form-grid,.line-grid{grid-template-columns:1fr}}
</style>
