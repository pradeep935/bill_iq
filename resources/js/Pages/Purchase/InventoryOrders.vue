<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import Layout from '../Layout.vue';
import PurchaseApi from './PurchaseApi';

const props = defineProps({ page: String, title: String });

const today = new Date().toISOString().slice(0, 10);
const loading = ref(false);
const saving = ref(false);
const orders = ref([]);
const refs = ref({ branches: [], warehouses: [], suppliers: [], products: [], approved_requisitions: [] });
const products = ref([]);
const productSearch = ref('');
const summary = ref({});
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });
const showForm = ref(false);
const detailOrder = ref(null);
const errors = ref({});
const filters = reactive({ search: '', product: '', branch_id: '', warehouse_id: '', supplier_id: '', source_type: '', status: '', date_from: '', date_to: '', expected_date: '', per_page: 25 });
const form = reactive({ id: null, source_type: 'manual', source_reference: '', purchase_requisition_id: '', branch_id: '', warehouse_id: '', supplier_id: '', order_date: today, expected_delivery_date: '', priority: 'normal', payment_terms: '', status: 'draft', remarks: '', items: [] });

const statusOptions = ['', 'draft', 'pending_approval', 'approved', 'ordered', 'partially_received', 'received', 'closed', 'cancelled'];
const sourceOptions = ['', 'reorder_suggestion', 'requisition', 'manual'];
const perPageOptions = [25, 50, 100];
const filteredWarehouses = computed(() => !form.branch_id ? refs.value.warehouses || [] : (refs.value.warehouses || []).filter((w) => Number(w.branch_id || 0) === Number(form.branch_id)));
const totals = computed(() => {
    const subtotal = form.items.reduce((sum, item) => sum + Number(item.ordered_quantity || 0) * Number(item.purchase_rate || 0), 0);
    const discount = form.items.reduce((sum, item) => sum + Number(item.discount_amount || 0), 0);
    const taxable = Math.max(0, subtotal - discount);
    const tax = form.items.reduce((sum, item) => sum + lineTax(item), 0);
    const rounded = Math.round(taxable + tax);
    return { subtotal, discount, taxable, tax, roundOff: rounded - taxable - tax, grandTotal: rounded };
});

const money = (value) => Number(value || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const qty = (value) => Number(value || 0).toLocaleString('en-IN', { maximumFractionDigits: 3 });
const labelize = (value) => String(value || '').replaceAll('_', ' ').replace(/\b\w/g, (c) => c.toUpperCase());
const supplierName = (supplier) => supplier?.supplier_name || supplier?.name || '-';
const productUnit = (product) => product?.unit?.name || product?.unit || product?.unit_name || 'PCS';
const lineTax = (item) => Math.max(0, (Number(item.ordered_quantity || 0) * Number(item.purchase_rate || 0) - Number(item.discount_amount || 0)) * Number(item.gst_rate || 0) / 100);
const lineTotal = (item) => Math.max(0, Number(item.ordered_quantity || 0) * Number(item.purchase_rate || 0) - Number(item.discount_amount || 0) + lineTax(item));
const sourceLabel = (order) => order.source_type === 'requisition' ? `Requisition ${order.source_reference || ''}` : labelize(order.source_type || 'manual');

const resetForm = () => Object.assign(form, { id: null, source_type: 'manual', source_reference: '', purchase_requisition_id: '', branch_id: '', warehouse_id: '', supplier_id: '', order_date: today, expected_delivery_date: '', priority: 'normal', payment_terms: '', status: 'draft', remarks: '', items: [] });

const load = async (page = 1) => {
    loading.value = true;
    try {
        const response = await PurchaseApi.inventoryOrders({ ...filters, page });
        orders.value = response.orders || [];
        summary.value = response.summary || {};
        pagination.value = response.pagination || pagination.value;
    } finally {
        loading.value = false;
    }
};

const loadReferences = async () => {
    refs.value = await PurchaseApi.inventoryOrderReferences();
};

const openCreate = () => {
    resetForm();
    showForm.value = true;
};

const editOrder = (order) => {
    Object.assign(form, {
        id: order.id,
        source_type: order.source_type || 'manual',
        source_reference: order.source_reference || '',
        purchase_requisition_id: '',
        branch_id: order.branch_id || '',
        warehouse_id: order.warehouse_id || '',
        supplier_id: order.supplier_id || '',
        order_date: order.order_date || today,
        expected_delivery_date: order.expected_delivery_date || '',
        priority: order.priority || 'normal',
        payment_terms: order.payment_terms || '',
        status: order.raw_status === 'confirmed' ? 'ordered' : order.raw_status,
        remarks: order.remarks || '',
        items: (order.items || []).map((item) => ({ ...item, product: item.product, ordered_quantity: item.ordered_quantity, discount_amount: 0, gst_rate: item.gst_rate || 0 })),
    });
    showForm.value = true;
};

const selectRequisition = () => {
    const req = refs.value.approved_requisitions?.find((row) => Number(row.id) === Number(form.purchase_requisition_id));
    if (!req) return;
    form.source_type = 'requisition';
    form.source_reference = req.requisition_number;
    form.branch_id = req.branch_id || '';
    form.remarks = `Created from requisition ${req.requisition_number}`;
    form.items = (req.items || []).map((item) => ({
        product_id: item.product_id,
        product: item.product,
        sku: item.product?.sku,
        unit_id: item.unit_id || item.product?.unit_id || '',
        current_stock: item.product?.current_stock || 0,
        incoming_quantity: 0,
        required_quantity: Number(item.approved_quantity || item.quantity || 0),
        ordered_quantity: Number(item.approved_quantity || item.quantity || 0),
        purchase_rate: Number(item.product?.purchase_price || item.product?.cost_price || 0),
        discount_amount: 0,
        gst_rate: Number(item.product?.gst_rate || 0),
        remarks: item.remarks || 'Created from approved requisition',
    }));
};

const searchProducts = async () => {
    if (productSearch.value.trim().length < 2) { products.value = []; return; }
    products.value = await PurchaseApi.searchInventoryOrderProducts(productSearch.value.trim());
};

const suggestedPurchaseQty = (product) => {
    const qty = Number(product.suggested_purchase_quantity || product.reorder_stock || product.maximum_stock || product.minimum_stock || 0);
    return qty > 0 ? qty : 1;
};

const addProduct = (product) => {
    const qty = suggestedPurchaseQty(product);
    form.items.push({
        product_id: product.id,
        product,
        sku: product.sku,
        unit_id: product.unit_id || '',
        current_stock: Number(product.current_stock || 0),
        incoming_quantity: 0,
        required_quantity: qty,
        ordered_quantity: qty,
        purchase_rate: Number(product.purchase_rate || product.purchase_price || product.cost_price || 0),
        discount_amount: 0,
        gst_rate: Number(product.gst_rate || 0),
        remarks: '',
    });
    productSearch.value = '';
    products.value = [];
};

const saveOrder = async (status = form.status) => {
    if (saving.value) return;
    saving.value = true;
    errors.value = {};
    try {
        const payload = { ...form, status, items: form.items.map((item) => ({ product_id: item.product_id, product_variant_id: item.product_variant_id || null, unit_id: item.unit_id || null, ordered_quantity: item.ordered_quantity, purchase_rate: item.purchase_rate, discount_amount: item.discount_amount || 0, gst_rate: item.gst_rate || 0, remarks: item.remarks || null })) };
        const response = await PurchaseApi.saveInventoryOrder(payload, form.id);
        showForm.value = false;
        alert(response.message || 'Inventory order saved.');
        await load(pagination.value.current_page || 1);
    } catch (error) {
        errors.value = error.response?.data?.errors || { form: [error.response?.data?.message || 'Unable to save inventory order.'] };
    } finally {
        saving.value = false;
    }
};

const markOrdered = async (order) => {
    await PurchaseApi.markInventoryOrderOrdered(order.id);
    await load(pagination.value.current_page || 1);
};

const cancelOrder = async (order) => {
    if (!window.confirm('Cancel this inventory order?')) return;
    await PurchaseApi.cancelInventoryOrder(order.id);
    await load(pagination.value.current_page || 1);
};

const receiveGoods = (order) => {
    window.location.href = `/app/purchase/grn?po_id=${order.id}`;
};

const viewReorderSuggestions = () => {
    window.location.href = '/app/purchase/reorder';
};

const clearFilters = async () => {
    Object.assign(filters, { search: '', product: '', branch_id: '', warehouse_id: '', supplier_id: '', source_type: '', status: '', date_from: '', date_to: '', expected_date: '', per_page: 25 });
    await load(1);
};

const exportCsv = () => {
    const headers = ['order_number', 'source_type', 'supplier_name', 'branch_name', 'warehouse_name', 'items_count', 'total_qty', 'received_qty', 'pending_qty', 'grand_total', 'expected_delivery_date', 'status'];
    const csvRows = orders.value.map((order) => ({ ...order, supplier_name: supplierName(order.supplier), branch_name: order.branch?.name || '-', warehouse_name: order.warehouse?.name || '-' }));
    const csv = [headers.join(','), ...csvRows.map((row) => headers.map((header) => `"${String(row[header] ?? '').replaceAll('"', '""')}"`).join(','))].join('\n');
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
    link.download = 'inventory-orders.csv';
    link.click();
};

const printPage = () => window.print();

watch(() => form.branch_id, () => {
    if (form.warehouse_id && !filteredWarehouses.value.some((warehouse) => Number(warehouse.id) === Number(form.warehouse_id))) {
        form.warehouse_id = '';
    }
});

onMounted(async () => {
    await Promise.all([loadReferences(), load()]);
});
</script>

<template>
    <Layout :page="page" :title="title">
        <template #topbar-title><div class="bill-page-title"><span>PURCHASE</span><h1>Inventory Orders</h1><p>Track and manage stock replenishment orders.</p></div></template>

        <div class="inventory-orders-page">
            <header class="page-head">
                <div><span>Purchase / Inventory Orders</span><h2>Inventory Orders</h2><p>Dedicated stock replenishment orders backed by purchase orders.</p></div>
                <div class="actions"><button @click="load(pagination.current_page)">Refresh</button><button @click="exportCsv">Export Excel</button><button @click="printPage">Print</button><button class="primary" @click="openCreate">+ Create Inventory Order</button></div>
            </header>

            <section class="kpis">
                <article><span>Total Orders</span><strong>{{ summary.total_orders || 0 }}</strong></article>
                <article><span>Draft</span><strong>{{ summary.draft || 0 }}</strong></article>
                <article><span>Pending / Ordered</span><strong>{{ summary.pending_ordered || 0 }}</strong></article>
                <article><span>Partially Received</span><strong>{{ summary.partially_received || 0 }}</strong></article>
                <article><span>Received</span><strong>{{ summary.received || 0 }}</strong></article>
                <article><span>Pending Quantity</span><strong>{{ qty(summary.pending_quantity) }}</strong></article>
                <article><span>Open Order Value</span><strong>Rs. {{ money(summary.open_order_value) }}</strong></article>
            </section>

            <section class="toolbar">
                <input v-model="filters.search" placeholder="Search Order # / Supplier" @keyup.enter="load(1)" />
                <input v-model="filters.product" placeholder="Search Product / SKU" @keyup.enter="load(1)" />
                <select v-model="filters.branch_id" @change="load(1)"><option value="">Branch</option><option v-for="branch in refs.branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option></select>
                <select v-model="filters.warehouse_id" @change="load(1)"><option value="">Warehouse</option><option v-for="warehouse in refs.warehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option></select>
                <select v-model="filters.supplier_id" @change="load(1)"><option value="">Supplier</option><option v-for="supplier in refs.suppliers" :key="supplier.id" :value="supplier.id">{{ supplierName(supplier) }}</option></select>
                <select v-model="filters.source_type" @change="load(1)"><option v-for="source in sourceOptions" :key="source" :value="source">{{ source ? labelize(source) : 'Source' }}</option></select>
                <select v-model="filters.status" @change="load(1)"><option v-for="status in statusOptions" :key="status" :value="status">{{ status ? labelize(status) : 'Status' }}</option></select>
                <input v-model="filters.date_from" type="date" @change="load(1)" />
                <input v-model="filters.expected_date" type="date" title="Expected Delivery Date" @change="load(1)" />
                <select v-model="filters.per_page" @change="load(1)"><option v-for="option in perPageOptions" :key="option" :value="option">{{ option }} rows</option></select>
                <button @click="clearFilters">Clear Filters</button>
            </section>

            <section class="panel">
                <div class="table-head"><span>Showing {{ pagination.from || 0 }}-{{ pagination.to || 0 }} of {{ pagination.total || 0 }} orders</span></div>
                <div class="table-scroll">
                    <table class="orders-table">
                        <thead><tr><th>Order #</th><th>Source</th><th>Supplier</th><th>Branch</th><th>Warehouse</th><th>Items</th><th>Total Qty</th><th>Received</th><th>Pending</th><th>Amount</th><th>Expected Date</th><th>Status</th><th>Action</th></tr></thead>
                        <tbody :class="{ loading }">
                            <tr v-for="order in orders" :key="order.id">
                                <td data-label="Order #"><button class="link-button" @click="detailOrder = order">{{ order.order_number }}</button></td>
                                <td data-label="Source">{{ sourceLabel(order) }}</td>
                                <td data-label="Supplier">{{ supplierName(order.supplier) }}</td>
                                <td data-label="Branch">{{ order.branch?.name || '-' }}</td>
                                <td data-label="Warehouse">{{ order.warehouse?.name || '-' }}</td>
                                <td data-label="Items">{{ order.items_count }}</td>
                                <td data-label="Total Qty">{{ qty(order.total_qty) }}</td>
                                <td data-label="Received">{{ qty(order.received_qty) }}</td>
                                <td data-label="Pending">{{ qty(order.pending_qty) }}</td>
                                <td data-label="Amount">Rs. {{ money(order.grand_total) }}</td>
                                <td data-label="Expected Date">{{ order.expected_delivery_date || '-' }}</td>
                                <td data-label="Status"><span class="badge" :class="order.status">{{ labelize(order.status) }}</span></td>
                                <td data-label="Action"><div class="row-actions"><button @click="detailOrder = order">View</button><button v-if="order.raw_status === 'draft'" @click="editOrder(order)">Edit</button><button v-if="['draft','approved','sent'].includes(order.raw_status)" @click="markOrdered(order)">Mark Ordered</button><button v-if="['confirmed','partially_received','partial_received'].includes(order.raw_status)" @click="receiveGoods(order)">Receive Goods</button><button @click="printPage">Print</button><button v-if="!['fully_received','received','closed','cancelled'].includes(order.raw_status)" class="danger" @click="cancelOrder(order)">Cancel</button></div></td>
                            </tr>
                            <tr v-if="!orders.length && !loading"><td colspan="13" class="empty"><strong>No inventory orders yet</strong><span>Create an order manually or generate one from Reorder Suggestions.</span><div><button class="primary" @click="openCreate">+ Create Inventory Order</button><button @click="viewReorderSuggestions">View Reorder Suggestions</button></div></td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="pagination"><button :disabled="pagination.current_page <= 1" @click="load(pagination.current_page - 1)">Previous</button><span>{{ pagination.current_page || 1 }} / {{ pagination.last_page || 1 }}</span><button :disabled="pagination.current_page >= pagination.last_page" @click="load(pagination.current_page + 1)">Next</button></div>
            </section>

            <div v-if="showForm" class="modal-backdrop" @click.self="showForm = false">
                <section class="modal">
                    <div class="drawer-head"><div><span>Inventory Order</span><h3>{{ form.id ? 'Edit Inventory Order' : 'Create Inventory Order' }}</h3></div><button @click="showForm = false">Close</button></div>
                    <div v-if="errors.form || Object.keys(errors).length" class="alert">{{ errors.form?.[0] || Object.values(errors)[0]?.[0] }}</div>
                    <div class="form-grid">
                        <label>Order Number<input value="Auto generated" disabled /></label>
                        <label>Source<select v-model="form.source_type"><option value="manual">Manual</option><option value="reorder_suggestion">Reorder Suggestion</option><option value="requisition">Purchase Requisition</option><option value="stock_planning">Stock Planning</option></select></label>
                        <label v-if="form.source_type === 'requisition'">Source Reference<select v-model="form.purchase_requisition_id" @change="selectRequisition"><option value="">Select Approved Requisition</option><option v-for="req in refs.approved_requisitions" :key="req.id" :value="req.id">{{ req.requisition_number }}</option></select></label>
                        <label v-else>Source Reference<input v-model="form.source_reference" placeholder="Manual / RS reference" /></label>
                        <label>Branch<select v-model="form.branch_id"><option value="">Select Branch</option><option v-for="branch in refs.branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option></select></label>
                        <label>Warehouse<select v-model="form.warehouse_id"><option value="">Select Warehouse</option><option v-for="warehouse in filteredWarehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option></select></label>
                        <label>Supplier<select v-model="form.supplier_id"><option value="">Select Supplier</option><option v-for="supplier in refs.suppliers" :key="supplier.id" :value="supplier.id">{{ supplierName(supplier) }}</option></select></label>
                        <label>Order Date<input v-model="form.order_date" type="date" /></label>
                        <label>Expected Delivery Date<input v-model="form.expected_delivery_date" type="date" /></label>
                        <label>Priority<select v-model="form.priority"><option value="normal">Normal</option><option value="urgent">Urgent</option><option value="critical">Critical</option></select></label>
                        <label>Payment Terms<input v-model="form.payment_terms" /></label>
                        <label class="wide">Remarks<textarea v-model="form.remarks"></textarea></label>
                    </div>
                    <div class="product-search"><input v-model="productSearch" placeholder="Search Product Name, SKU or Barcode" @input="searchProducts" /><div v-if="products.length" class="search-menu"><button v-for="product in products" :key="product.id" @click="addProduct(product)"><strong>{{ product.name }}</strong><span>SKU: {{ product.sku || '-' }} · Available: {{ qty(product.current_stock) }} {{ productUnit(product) }} · Last Purchase: Rs. {{ money(product.purchase_rate) }}</span></button></div></div>
                    <div class="table-scroll">
                        <table class="line-table"><thead><tr><th>Product</th><th>SKU</th><th>Current Stock</th><th>Incoming Qty</th><th>Required Qty</th><th>Order Qty</th><th>UOM</th><th>Purchase Rate</th><th>Discount</th><th>Tax %</th><th>Line Total</th><th>Remarks</th><th>Action</th></tr></thead><tbody><tr v-for="(item, index) in form.items" :key="index"><td data-label="Product">{{ item.product?.name || item.product_name || '-' }}</td><td data-label="SKU">{{ item.sku || item.product?.sku || '-' }}</td><td data-label="Current Stock">{{ qty(item.current_stock) }}</td><td data-label="Incoming Qty">{{ qty(item.incoming_quantity) }}</td><td data-label="Required Qty"><input v-model.number="item.required_quantity" type="number" step="0.001" /></td><td data-label="Order Qty"><input v-model.number="item.ordered_quantity" type="number" step="0.001" /></td><td data-label="UOM">{{ productUnit(item.product) }}</td><td data-label="Purchase Rate"><input v-model.number="item.purchase_rate" type="number" step="0.01" /></td><td data-label="Discount"><input v-model.number="item.discount_amount" type="number" step="0.01" /></td><td data-label="Tax %"><input v-model.number="item.gst_rate" type="number" step="0.01" /></td><td data-label="Line Total">Rs. {{ money(lineTotal(item)) }}</td><td data-label="Remarks"><input v-model="item.remarks" /></td><td data-label="Action"><button class="danger" @click="form.items.splice(index,1)">Remove</button></td></tr><tr v-if="!form.items.length"><td colspan="13" class="empty">Search and add products for this inventory order.</td></tr></tbody></table>
                    </div>
                    <div class="totals"><span>Subtotal <strong>Rs. {{ money(totals.subtotal) }}</strong></span><span>Discount <strong>Rs. {{ money(totals.discount) }}</strong></span><span>Taxable Amount <strong>Rs. {{ money(totals.taxable) }}</strong></span><span>GST / Tax <strong>Rs. {{ money(totals.tax) }}</strong></span><span>Round Off <strong>Rs. {{ money(totals.roundOff) }}</strong></span><span class="grand">Grand Total <strong>Rs. {{ money(totals.grandTotal) }}</strong></span></div>
                    <div class="modal-actions"><button @click="showForm = false">Cancel</button><button :disabled="saving" @click="saveOrder('draft')">Save Draft</button><button :disabled="saving" @click="saveOrder('approved')">Submit / Approve</button><button class="primary" :disabled="saving" @click="saveOrder('ordered')">Mark Ordered</button></div>
                </section>
            </div>

            <aside v-if="detailOrder" class="drawer-backdrop" @click.self="detailOrder = null">
                <section class="drawer">
                    <div class="drawer-head"><div><span>Inventory Order</span><h3>{{ detailOrder.order_number }}</h3></div><button @click="detailOrder = null">Close</button></div>
                    <span class="badge" :class="detailOrder.status">{{ labelize(detailOrder.status) }}</span>
                    <div class="detail-grid"><article><h4>Order Information</h4><p>Source: {{ sourceLabel(detailOrder) }}</p><p>Branch: {{ detailOrder.branch?.name || '-' }}</p><p>Warehouse: {{ detailOrder.warehouse?.name || '-' }}</p><p>Supplier: {{ supplierName(detailOrder.supplier) }}</p><p>Order Date: {{ detailOrder.order_date }}</p><p>Expected: {{ detailOrder.expected_delivery_date || '-' }}</p></article><article><h4>Totals</h4><p>Subtotal: Rs. {{ money(detailOrder.subtotal) }}</p><p>Tax: Rs. {{ money(detailOrder.tax_amount) }}</p><p>Grand Total: Rs. {{ money(detailOrder.grand_total) }}</p></article></div>
                    <h4>Products</h4><table><thead><tr><th>Product</th><th>Ordered</th><th>Received</th><th>Pending</th><th>Rate</th><th>Amount</th></tr></thead><tbody><tr v-for="item in detailOrder.items" :key="item.id"><td>{{ item.product?.name || '-' }}</td><td>{{ qty(item.ordered_quantity) }}</td><td>{{ qty(item.received_quantity) }}</td><td>{{ qty(item.pending_quantity) }}</td><td>Rs. {{ money(item.purchase_rate) }}</td><td>Rs. {{ money(item.line_total) }}</td></tr></tbody></table>
                    <h4>GRN History</h4><p class="muted">Goods receipts are available from Stock Inward / GRN for this order.</p>
                    <h4>Activity / Audit</h4><ul><li>Created as {{ sourceLabel(detailOrder) }}</li><li v-if="detailOrder.raw_status !== 'draft'">Sent / Ordered</li><li v-if="detailOrder.received_qty > 0">GRN Received</li><li v-if="['fully_received','received'].includes(detailOrder.raw_status)">Closed by receipt</li></ul>
                </section>
            </aside>
        </div>
    </Layout>
</template>

<style scoped>
.inventory-orders-page{padding:4px 0 28px;color:#243047}.page-head,.actions,.toolbar,.table-head,.pagination,.drawer-head,.modal-actions{display:flex;align-items:center;gap:10px}.page-head{justify-content:space-between;margin-bottom:14px}.page-head span,.drawer-head span{color:#2457d6;font-size:11px;font-weight:850}.page-head h2{margin:2px 0;color:#142139;font-weight:850}.page-head p{margin:0;color:#667085;font-size:13px}.actions,.toolbar,.modal-actions{flex-wrap:wrap}.kpis{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:12px;margin-bottom:14px}.kpis article{padding:13px;background:#fff;border:1px solid #e2e8f0;border-radius:8px}.kpis span{display:block;color:#667085;font-size:11px}.kpis strong{display:block;margin-top:6px;color:#101828;font-size:17px}.toolbar{padding:12px;margin-bottom:14px;background:#fff;border:1px solid #dfe6ef;border-radius:8px}.toolbar input{min-width:190px}.panel,.modal,.drawer{background:#fff;border:1px solid #dfe6ef;border-radius:8px}.panel{padding:16px}.table-head{justify-content:space-between;margin-bottom:10px;color:#667085;font-size:12px}.table-scroll{overflow-x:auto}table{width:100%;border-collapse:collapse}th{padding:10px;color:#64748b;background:#f8fafc;border-bottom:1px solid #e6edf5;text-align:left;font-size:10px;font-weight:850;text-transform:uppercase;white-space:nowrap}td{padding:10px;border-bottom:1px solid #edf1f5;font-size:12px;vertical-align:top;white-space:nowrap}input,select,textarea,button{min-height:36px;padding:8px 10px;color:#344159;background:#fff;border:1px solid #d8e0eb;border-radius:8px;font-size:12px}textarea{min-height:72px;resize:vertical}button{font-weight:800;cursor:pointer}.primary{color:#fff;background:#2457d6;border-color:#2457d6}.danger{color:#b42318;background:#fff4f4;border-color:#ffd5d5}.link-button{padding:0;min-height:0;color:#175cd3;background:transparent;border:0}.badge{display:inline-flex;padding:5px 8px;border-radius:7px;background:#f2f4f7;color:#344054;font-size:10px;font-weight:850}.badge.ordered,.badge.approved{color:#175cd3;background:#eff8ff}.badge.partially_received{color:#92400e;background:#fffbeb}.badge.received,.badge.closed{color:#067647;background:#ecfdf3}.badge.cancelled{color:#b42318;background:#fff4f4}.row-actions{display:flex;gap:6px}.empty{padding:30px!important;text-align:center;color:#667085}.empty strong,.empty span{display:block}.empty div{display:flex;justify-content:center;gap:8px;margin-top:10px}.pagination{justify-content:flex-end;margin-top:12px}.modal-backdrop,.drawer-backdrop{position:fixed;z-index:960;inset:0;background:rgba(15,23,42,.38)}.modal-backdrop{display:grid;place-items:center;padding:20px}.modal{width:min(1180px,100%);max-height:calc(100vh - 40px);overflow:auto;padding:18px}.drawer-backdrop{display:flex;justify-content:flex-end}.drawer{width:min(680px,100%);height:100vh;overflow:auto;padding:18px;border-radius:0}.form-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:14px 0}.form-grid label{display:grid;gap:6px;color:#526077;font-size:11px;font-weight:850}.wide{grid-column:span 2}.product-search{position:relative;margin:12px 0}.product-search input{width:100%}.search-menu{position:absolute;z-index:25;top:42px;left:0;right:0;display:grid;max-height:260px;overflow:auto;background:#fff;border:1px solid #d8e0eb;border-radius:8px;box-shadow:0 18px 42px rgba(16,24,40,.16)}.search-menu button{display:grid;justify-items:start;border:0;border-bottom:1px solid #eef2f7;border-radius:0}.search-menu span{color:#69758a;font-size:11px}.totals{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;margin-top:14px}.totals span{padding:10px;border:1px solid #edf1f5;border-radius:8px;color:#667085;font-size:11px}.totals strong{display:block;margin-top:4px;color:#142139}.totals .grand{background:#f8fafc}.alert{padding:10px;color:#b42318;background:#fff4f4;border:1px solid #ffd5d5;border-radius:8px}.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:14px 0}.detail-grid article{padding:12px;border:1px solid #e5ebf2;border-radius:8px}.detail-grid h4,.drawer h4{margin:0 0 8px;color:#142139}.detail-grid p,.muted{margin:6px 0;color:#667085}.drawer ul{padding-left:18px;color:#667085}@media(max-width:1180px){.kpis{grid-template-columns:repeat(3,minmax(0,1fr))}.form-grid,.totals{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:760px){.page-head,.table-head{align-items:stretch;flex-direction:column}.actions button,.toolbar input,.toolbar select,.toolbar button{width:100%;min-width:0}.kpis,.form-grid,.totals,.detail-grid{grid-template-columns:1fr}.wide{grid-column:auto}.orders-table thead,.line-table thead{display:none}.orders-table,.orders-table tbody,.orders-table tr,.orders-table td,.line-table,.line-table tbody,.line-table tr,.line-table td{display:block;width:100%}.orders-table tr,.line-table tr{margin-bottom:12px;padding:10px;border:1px solid #e4eaf2;border-radius:8px}.orders-table td,.line-table td{display:grid;grid-template-columns:135px minmax(0,1fr);gap:8px;border:0;white-space:normal}.orders-table td::before,.line-table td::before{content:attr(data-label);color:#667085;font-size:11px;font-weight:850}.row-actions{display:grid}.modal{padding:14px}.empty div{display:grid}}
</style>
