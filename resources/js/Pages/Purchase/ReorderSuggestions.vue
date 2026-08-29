<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import Layout from '../Layout.vue';
import PurchaseApi from './PurchaseApi';

const props = defineProps({ page: String, title: String });

const loading = ref(false);
const saving = ref(false);
const rows = ref([]);
const refs = ref({ branches: [], warehouses: [], suppliers: [], categories: [] });
const summary = ref({});
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });
const selected = ref({});
const detailRow = ref(null);
const previewOpen = ref(false);
const expectedDeliveryDate = ref('');
const lastResult = ref('');
const filters = reactive({ search: '', branch_id: '', warehouse_id: '', stock_status: '', supplier_id: '', category_id: '', per_page: 50 });

const perPageOptions = [25, 50, 100];
const statusOptions = [
    { value: '', label: 'All' },
    { value: 'out_of_stock', label: 'Out of Stock' },
    { value: 'critical', label: 'Critical' },
    { value: 'low_stock', label: 'Low Stock' },
    { value: 'reorder_required', label: 'Reorder Required' },
];

const selectedRows = computed(() => rows.value.filter((row) => selected.value[rowKey(row)]));
const allVisibleSelected = computed(() => rows.value.length > 0 && selectedRows.value.length === rows.value.length);
const previewGroups = computed(() => {
    const groups = {};
    selectedRows.value.forEach((row) => {
        const supplierId = Number(row.supplier_id || row.preferred_supplier_id || 0);
        const key = supplierId || `missing-${row.product_id}`;
        if (!groups[key]) groups[key] = { supplier_id: supplierId, supplier_name: supplierNameById(supplierId) || row.preferred_supplier, warehouse: row.warehouse, rows: [] };
        groups[key].rows.push(row);
    });
    return Object.values(groups);
});

const rowKey = (row) => `${row.product_id}-${row.branch_id || 0}-${row.warehouse_id || 0}`;
const money = (value) => Number(value || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const qty = (value) => Number(value || 0).toLocaleString('en-IN', { maximumFractionDigits: 3 });
const supplierName = (supplier) => supplier?.supplier_name || supplier?.name || '-';
const supplierNameById = (id) => supplierName(refs.value.suppliers.find((supplier) => Number(supplier.id) === Number(id)));
const printPage = () => window.print();
const reviewProducts = () => { window.location.href = '/app/inventory/products'; };
const viewPurchaseWorkflow = () => { window.location.href = '/app/purchase/orders'; };
const viewProduct = (row) => { window.location.href = `/app/inventory/products?search=${encodeURIComponent(row.sku || row.product_name)}`; };
const selectedPayload = () => selectedRows.value.map((row) => ({
    product_id: row.product_id,
    unit_id: row.unit_id,
    branch_id: row.branch_id,
    warehouse_id: row.warehouse_id,
    supplier_id: row.supplier_id || row.preferred_supplier_id,
    quantity: Number(row.order_quantity || row.suggested_quantity || 0),
    purchase_rate: Number(row.purchase_rate || 0),
    gst_rate: Number(row.gst_rate || 0),
}));

const load = async (page = 1) => {
    loading.value = true;
    try {
        const response = await PurchaseApi.reorderSuggestions({ ...filters, page });
        rows.value = (response.suggestions || []).map((row) => ({
            ...row,
            order_quantity: Number(row.suggested_quantity || 0),
            supplier_id: row.preferred_supplier_id || '',
        }));
        summary.value = response.summary || {};
        pagination.value = response.pagination || pagination.value;
    } finally {
        loading.value = false;
    }
};

const loadReferences = async () => {
    refs.value = await PurchaseApi.reorderReferences();
};

const clearFilters = async () => {
    Object.assign(filters, { search: '', branch_id: '', warehouse_id: '', stock_status: '', supplier_id: '', category_id: '', per_page: 50 });
    await load(1);
};

const toggleAllVisible = () => {
    const next = !allVisibleSelected.value;
    const copy = { ...selected.value };
    rows.value.forEach((row) => { copy[rowKey(row)] = next; });
    selected.value = copy;
};

const exportRows = (exportRowsValue = rows.value, filename = 'reorder-suggestions.csv') => {
    const headers = ['product_name', 'sku', 'branch', 'warehouse', 'available_stock', 'reserved_stock', 'incoming_po_qty', 'reorder_level', 'target_stock', 'suggested_quantity', 'preferred_supplier', 'purchase_rate', 'estimated_value', 'status'];
    const csv = [headers.join(','), ...exportRowsValue.map((row) => headers.map((header) => `"${String(row[header] ?? '').replaceAll('"', '""')}"`).join(','))].join('\n');
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
    link.download = filename;
    link.click();
};

const createRequisition = async () => {
    if (!selectedRows.value.length) return;
    saving.value = true;
    try {
        const response = await PurchaseApi.createReorderRequisition(selectedPayload());
        lastResult.value = response.message || 'Requisition created successfully.';
        selected.value = {};
        await load(pagination.value.current_page || 1);
    } catch (error) {
        alert(error.response?.data?.message || Object.values(error.response?.data?.errors || {})?.[0]?.[0] || 'Unable to create requisition.');
    } finally {
        saving.value = false;
    }
};

const openPoPreview = () => {
    if (!selectedRows.value.length) return;
    if (selectedRows.value.some((row) => !row.warehouse_id)) {
        alert('Select a warehouse filter or use rows with a specific warehouse before creating a purchase order.');
        return;
    }
    previewOpen.value = true;
};

const createPurchaseOrders = async (status = 'draft') => {
    saving.value = true;
    try {
        const response = await PurchaseApi.createReorderPurchaseOrders({ status, expected_delivery_date: expectedDeliveryDate.value || null, items: selectedPayload() });
        lastResult.value = response.message || 'Purchase order created successfully.';
        selected.value = {};
        previewOpen.value = false;
        await load(pagination.value.current_page || 1);
    } catch (error) {
        alert(error.response?.data?.message || Object.values(error.response?.data?.errors || {})?.[0]?.[0] || 'Unable to create purchase order.');
    } finally {
        saving.value = false;
    }
};

onMounted(async () => {
    await Promise.all([loadReferences(), load()]);
});
</script>

<template>
    <Layout :page="page" :title="title">
        <template #topbar-title>
            <div class="bill-page-title"><span>PURCHASE</span><h1>Reorder Suggestions</h1><p>Review low-stock products and create purchase requirements.</p></div>
        </template>

        <div class="reorder-page">
            <header class="page-head">
                <div><span>Purchase / Reorder Suggestions</span><h2>Reorder Suggestions</h2><p>Stock Monitoring → Reorder Suggestion → Requisition / Purchase Order → GRN.</p></div>
                <div class="actions"><button @click="load(pagination.current_page)">Refresh</button><button @click="exportRows(rows, 'reorder-suggestions.csv')">Export Excel</button><button @click="printPage">Print</button><button class="primary" :disabled="!selectedRows.length" @click="openPoPreview">Create Purchase Order</button></div>
            </header>

            <section class="kpis">
                <article><span>Products to Reorder</span><strong>{{ summary.products_to_reorder || 0 }}</strong></article>
                <article><span>Out of Stock</span><strong>{{ summary.out_of_stock || 0 }}</strong></article>
                <article><span>Low Stock</span><strong>{{ summary.low_stock || 0 }}</strong></article>
                <article><span>Suggested Purchase Qty</span><strong>{{ qty(summary.suggested_purchase_qty) }}</strong></article>
                <article><span>Estimated Purchase Value</span><strong>Rs. {{ money(summary.estimated_purchase_value) }}</strong></article>
                <article class="warning"><span>Missing Reorder Settings</span><strong>{{ summary.missing_reorder_settings || 0 }}</strong><button @click="reviewProducts">Review Products</button></article>
            </section>

            <section class="toolbar">
                <input v-model="filters.search" placeholder="Search Product / SKU / Barcode" @keyup.enter="load(1)" />
                <select v-model="filters.branch_id" @change="load(1)"><option value="">Branch</option><option v-for="branch in refs.branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option></select>
                <select v-model="filters.warehouse_id" @change="load(1)"><option value="">Warehouse</option><option v-for="warehouse in refs.warehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option></select>
                <select v-model="filters.stock_status" @change="load(1)"><option v-for="status in statusOptions" :key="status.value" :value="status.value">{{ status.label }}</option></select>
                <select v-model="filters.supplier_id" @change="load(1)"><option value="">Supplier</option><option v-for="supplier in refs.suppliers" :key="supplier.id" :value="supplier.id">{{ supplierName(supplier) }}</option></select>
                <select v-model="filters.category_id" @change="load(1)"><option value="">Category</option><option v-for="category in refs.categories" :key="category.id" :value="category.id">{{ category.name }}</option></select>
                <select v-model="filters.per_page" @change="load(1)"><option v-for="option in perPageOptions" :key="option" :value="option">{{ option }} rows</option></select>
                <button @click="clearFilters">Clear Filters</button>
            </section>

            <div v-if="lastResult" class="success">{{ lastResult }} <button @click="viewPurchaseWorkflow">View Purchase Workflow</button></div>

            <section class="panel">
                <div class="table-head"><div>Showing {{ pagination.from || 0 }}-{{ pagination.to || 0 }} of {{ pagination.total || 0 }} products</div><button :disabled="!selectedRows.length" @click="exportRows(selectedRows, 'selected-reorder-suggestions.csv')">Export Selected</button></div>
                <div class="table-scroll">
                    <table class="reorder-table">
                        <thead><tr><th><input type="checkbox" :checked="allVisibleSelected" @change="toggleAllVisible" /></th><th>Product</th><th>SKU</th><th>Branch</th><th>Warehouse</th><th>Available</th><th>Reserved</th><th>Incoming PO Qty</th><th>Reorder Level</th><th>Target Stock</th><th>Suggested Qty</th><th>Purchase Rate</th><th>Est. Value</th><th>Preferred Supplier</th><th>Status</th><th>Action</th></tr></thead>
                        <tbody :class="{ loading }">
                            <tr v-for="row in rows" :key="rowKey(row)">
                                <td data-label="Select"><input v-model="selected[rowKey(row)]" type="checkbox" /></td>
                                <td data-label="Product"><button class="link-button" @click="detailRow = row"><strong>{{ row.product_name }}</strong><span>Category: {{ row.category || '-' }}</span></button></td>
                                <td data-label="SKU">{{ row.sku || '-' }}</td>
                                <td data-label="Branch">{{ row.branch }}</td>
                                <td data-label="Warehouse">{{ row.warehouse }}</td>
                                <td data-label="Available">{{ qty(row.available_stock) }} {{ row.unit }}</td>
                                <td data-label="Reserved">{{ qty(row.reserved_stock) }}</td>
                                <td data-label="Incoming PO Qty"><span v-if="row.incoming_po_qty > 0" class="mini-pill">{{ qty(row.incoming_po_qty) }} incoming</span><span v-else>0</span></td>
                                <td data-label="Reorder Level">{{ qty(row.reorder_level) }}</td>
                                <td data-label="Target Stock">{{ qty(row.target_stock) }}</td>
                                <td data-label="Suggested Qty"><input v-model.number="row.order_quantity" type="number" min="0.001" step="0.001" /></td>
                                <td data-label="Purchase Rate"><input v-model.number="row.purchase_rate" type="number" min="0" step="0.01" /></td>
                                <td data-label="Est. Value">Rs. {{ money(Number(row.order_quantity || 0) * Number(row.purchase_rate || 0)) }}</td>
                                <td data-label="Preferred Supplier"><select v-model="row.supplier_id"><option value="">Select Supplier</option><option v-for="supplier in refs.suppliers" :key="supplier.id" :value="supplier.id">{{ supplierName(supplier) }}</option></select></td>
                                <td data-label="Status"><span class="badge" :class="row.status_key">{{ row.status }}</span></td>
                                <td data-label="Action"><div class="row-menu"><button @click="selected = { ...selected, [rowKey(row)]: true }; createRequisition()">Create Requisition</button><button @click="selected = { ...selected, [rowKey(row)]: true }; openPoPreview()">Create PO</button><button @click="viewProduct(row)">View Product</button><button @click="detailRow = row">Stock History</button><button @click="detailRow = row">Purchase History</button></div></td>
                            </tr>
                            <tr v-if="!rows.length && !loading"><td colspan="16" class="empty"><strong>{{ filters.search || filters.branch_id || filters.warehouse_id || filters.stock_status || filters.supplier_id || filters.category_id ? 'No matching products' : 'Stock levels look healthy' }}</strong><span>{{ filters.search || filters.branch_id || filters.warehouse_id || filters.stock_status || filters.supplier_id || filters.category_id ? 'Try changing your search or filters.' : 'There are currently no products below their configured reorder levels.' }}</span><button @click="clearFilters">{{ filters.search || filters.branch_id || filters.warehouse_id || filters.stock_status || filters.supplier_id || filters.category_id ? 'Clear Filters' : 'Review Stock Settings' }}</button></td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="pagination"><button :disabled="pagination.current_page <= 1" @click="load(pagination.current_page - 1)">Previous</button><span>{{ pagination.current_page || 1 }} / {{ pagination.last_page || 1 }}</span><button :disabled="pagination.current_page >= pagination.last_page" @click="load(pagination.current_page + 1)">Next</button></div>
            </section>

            <div v-if="selectedRows.length" class="bulk-bar"><strong>{{ selectedRows.length }} products selected</strong><div><button :disabled="saving" @click="createRequisition">Create Requisition</button><button class="primary" :disabled="saving" @click="openPoPreview">Create Purchase Order</button><button @click="exportRows(selectedRows, 'selected-reorder-suggestions.csv')">Export Selected</button></div></div>

            <aside v-if="detailRow" class="drawer-backdrop" @click.self="detailRow = null">
                <section class="drawer">
                    <div class="drawer-head"><div><span>Recommendation Details</span><h3>{{ detailRow.product_name }}</h3></div><button @click="detailRow = null">Close</button></div>
                    <div class="detail-grid"><article><h4>Current Stock</h4><p>Available: {{ qty(detailRow.available_stock) }} {{ detailRow.unit }}</p><p>Reserved: {{ qty(detailRow.reserved_stock) }} {{ detailRow.unit }}</p><p>Incoming: {{ qty(detailRow.incoming_po_qty) }} {{ detailRow.unit }}</p><p>Projected: {{ qty(detailRow.projected_stock) }} {{ detailRow.unit }}</p></article><article><h4>Stock Settings</h4><p>Reorder Level: {{ qty(detailRow.reorder_level) }}</p><p>Minimum: {{ qty(detailRow.minimum_stock) }}</p><p>Target: {{ qty(detailRow.target_stock) }}</p></article><article><h4>Purchase Information</h4><p>Last Purchase Rate: Rs. {{ money(detailRow.purchase_rate) }}</p><p>Last Supplier: {{ detailRow.preferred_supplier }}</p><p>Last Purchase: {{ detailRow.last_purchase_date || '-' }}</p></article></div>
                </section>
            </aside>

            <div v-if="previewOpen" class="modal-backdrop" @click.self="previewOpen = false">
                <section class="modal">
                    <div class="drawer-head"><div><span>Purchase Order Preview</span><h3>Create Purchase Order</h3></div><button @click="previewOpen = false">Cancel</button></div>
                    <label class="expected">Expected Delivery Date<input v-model="expectedDeliveryDate" type="date" /></label>
                    <div v-for="group in previewGroups" :key="group.supplier_id || group.supplier_name" class="preview-group">
                        <h4>{{ group.supplier_name }}</h4><p>Warehouse: {{ group.warehouse }}</p>
                        <table><thead><tr><th>Product</th><th>Suggested</th><th>Order Qty</th><th>Purchase Rate</th><th>Amount</th></tr></thead><tbody><tr v-for="row in group.rows" :key="rowKey(row)"><td>{{ row.product_name }}</td><td>{{ qty(row.suggested_quantity) }}</td><td><input v-model.number="row.order_quantity" type="number" min="0.001" step="0.001" /></td><td><input v-model.number="row.purchase_rate" type="number" min="0" step="0.01" /></td><td>Rs. {{ money(Number(row.order_quantity || 0) * Number(row.purchase_rate || 0)) }}</td></tr></tbody></table>
                    </div>
                    <div class="modal-actions"><button @click="previewOpen = false">Cancel</button><button :disabled="saving" @click="createPurchaseOrders('draft')">Save Draft PO</button><button class="primary" :disabled="saving" @click="createPurchaseOrders('confirmed')">Create PO</button></div>
                </section>
            </div>
        </div>
    </Layout>
</template>

<style scoped>
.reorder-page{padding:4px 0 72px;color:#243047}.page-head,.actions,.toolbar,.table-head,.pagination,.bulk-bar,.modal-actions,.drawer-head{display:flex;align-items:center;gap:10px}.page-head{justify-content:space-between;margin-bottom:14px}.page-head span,.drawer-head span{color:#2457d6;font-size:11px;font-weight:850}.page-head h2{margin:2px 0;color:#142139;font-weight:850}.page-head p{margin:0;color:#667085;font-size:13px}.actions{flex-wrap:wrap;justify-content:flex-end}.kpis{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px;margin-bottom:14px}.kpis article{padding:13px;background:#fff;border:1px solid #e2e8f0;border-radius:8px}.kpis span{display:block;color:#667085;font-size:11px}.kpis strong{display:block;margin-top:6px;color:#101828;font-size:18px}.kpis .warning{background:#fffbeb}.kpis .warning button{margin-top:8px}.toolbar{flex-wrap:wrap;padding:12px;margin-bottom:14px;background:#fff;border:1px solid #dfe6ef;border-radius:8px}.toolbar input{min-width:260px;flex:1}.panel{padding:16px;background:#fff;border:1px solid #dfe6ef;border-radius:8px}.table-head{justify-content:space-between;margin-bottom:10px;color:#667085;font-size:12px}.table-scroll{overflow-x:auto}table{width:100%;border-collapse:collapse}th{padding:10px;color:#64748b;background:#f8fafc;border-bottom:1px solid #e6edf5;text-align:left;font-size:10px;font-weight:850;text-transform:uppercase;white-space:nowrap}td{padding:10px;border-bottom:1px solid #edf1f5;font-size:12px;vertical-align:top;white-space:nowrap}input,select,button{min-height:36px;padding:8px 10px;color:#344159;background:#fff;border:1px solid #d8e0eb;border-radius:8px;font-size:12px}button{font-weight:800;cursor:pointer}.primary{color:#fff;background:#2457d6;border-color:#2457d6}.link-button{display:grid;justify-items:start;min-height:0;padding:0;border:0;background:transparent}.link-button strong{color:#18243a}.link-button span{color:#667085;font-size:11px}.badge,.mini-pill{display:inline-flex;padding:5px 8px;border-radius:7px;font-size:10px;font-weight:850}.badge.out_of_stock{color:#b42318;background:#fff4f4}.badge.critical{color:#92400e;background:#fffbeb}.badge.low_stock{color:#175cd3;background:#eff8ff}.badge.reorder_required{color:#344054;background:#f2f4f7}.mini-pill{color:#175cd3;background:#eff8ff}.row-menu{display:flex;gap:6px}.row-menu button{white-space:nowrap}.empty{padding:30px!important;text-align:center;color:#667085}.empty strong,.empty span{display:block}.success{display:flex;justify-content:space-between;gap:10px;margin-bottom:12px;padding:10px 12px;color:#067647;background:#ecfdf3;border:1px solid #abefc6;border-radius:8px;font-size:12px}.pagination{justify-content:flex-end;margin-top:12px}.bulk-bar{position:fixed;z-index:920;left:calc(50% - 380px);right:24px;bottom:18px;justify-content:space-between;padding:12px 14px;background:#111827;color:#fff;border-radius:8px;box-shadow:0 20px 50px rgba(15,23,42,.25)}.bulk-bar div{display:flex;gap:8px;flex-wrap:wrap}.drawer-backdrop,.modal-backdrop{position:fixed;z-index:960;inset:0;background:rgba(15,23,42,.38)}.drawer-backdrop{display:flex;justify-content:flex-end}.drawer{width:min(520px,100%);height:100vh;overflow:auto;padding:18px;background:#fff}.drawer-head{justify-content:space-between;margin-bottom:14px}.drawer-head h3{margin:2px 0 0;color:#142139}.detail-grid{display:grid;gap:12px}.detail-grid article,.preview-group{padding:14px;border:1px solid #e4eaf2;border-radius:8px}.detail-grid h4,.preview-group h4{margin:0 0 8px;color:#142139}.detail-grid p,.preview-group p{margin:6px 0;color:#667085}.modal-backdrop{display:grid;place-items:center;padding:20px}.modal{width:min(980px,100%);max-height:calc(100vh - 40px);overflow:auto;padding:18px;background:#fff;border-radius:8px}.expected{display:grid;gap:6px;max-width:260px;margin-bottom:12px;color:#526077;font-size:11px;font-weight:850}.modal-actions{justify-content:flex-end;margin-top:14px}@media(max-width:1200px){.kpis{grid-template-columns:repeat(3,minmax(0,1fr))}.bulk-bar{left:18px}}@media(max-width:760px){.page-head,.table-head,.success{align-items:stretch;flex-direction:column}.actions button,.toolbar input,.toolbar select,.toolbar button{width:100%;min-width:0}.kpis{grid-template-columns:1fr}.reorder-table thead{display:none}.reorder-table,.reorder-table tbody,.reorder-table tr,.reorder-table td{display:block;width:100%}.reorder-table tr{margin-bottom:12px;padding:10px;border:1px solid #e4eaf2;border-radius:8px}.reorder-table td{display:grid;grid-template-columns:135px minmax(0,1fr);gap:8px;border:0;white-space:normal}.reorder-table td::before{content:attr(data-label);color:#667085;font-size:11px;font-weight:850}.row-menu{display:grid}.bulk-bar{display:grid;left:12px;right:12px}.bulk-bar div{display:grid}.modal table{min-width:680px}}
</style>
