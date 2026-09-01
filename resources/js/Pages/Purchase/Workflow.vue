<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import Layout from '../Layout.vue';
import OrderApi from '../Orders/OrderApi';
import InventoryApi from '../Inventory/InventoryApi';

const props = defineProps({ page: { type: String, default: 'inventory-orders' }, title: { type: String, default: 'Purchase Management' }, initial_tab: { type: String, default: 'overview' } });

const today = new Date().toISOString().slice(0, 10);
const tab = ref(props.initial_tab === 'dashboard' ? 'overview' : props.initial_tab);
const loading = ref(false);
const saving = ref(false);
const errors = ref({});
const refs = ref({ suppliers: [], branches: [], warehouses: [], products: [] });
const dashboard = ref({ recent_activity: [] });
const reports = ref({});
const requisitions = ref([]);
const purchaseOrders = ref([]);
const receipts = ref([]);
const warehouseLocations = ref([]);
const productSearch = ref('');
const productResults = ref([]);
const selectedPoId = ref('');
const filters = reactive({ search: '', status: '', supplier_id: '', branch_id: '', warehouse_id: '', date_from: '', date_to: '' });

const requisition = reactive({ branch_id: '', requisition_date: today, department: '', priority: 'normal', required_date: '', status: 'draft', remarks: '', items: [] });
const po = reactive({ branch_id: '', warehouse_id: '', supplier_id: '', purchase_requisition_id: '', po_date: today, expected_delivery_date: '', payment_terms: '', supplier_reference: '', currency: 'INR', status: 'draft', terms_conditions: '', remarks: '', items: [] });
const grn = reactive({ branch_id: '', warehouse_id: '', purchase_order_id: '', supplier_id: '', receipt_date: today, supplier_challan_number: '', supplier_invoice_number: '', vehicle_number: '', qc_status: 'pending', status: 'draft', remarks: '', items: [] });

const tabs = [
    { id: 'overview', label: 'Overview' },
    { id: 'requisitions', label: 'Requisitions' },
    { id: 'purchase-orders', label: 'Purchase Orders' },
    { id: 'grn', label: 'Goods Receipts' },
    { id: 'reports', label: 'Reports' },
];

const money = (value) => Number(value || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const qty = (value) => Number(value || 0).toLocaleString('en-IN', { maximumFractionDigits: 3 });
const supplierName = (supplier) => supplier?.supplier_name || supplier?.name || '-';
const productName = (item) => item.product?.name || item.product_name || '-';
const productSku = (item) => item.product?.sku || item.sku || '-';
const productUnit = (product) => product?.unit?.name || product?.unit || product?.unit_name || 'PCS';
const filteredWarehouses = computed(() => !po.branch_id ? refs.value.warehouses || [] : (refs.value.warehouses || []).filter((w) => Number(w.branch_id || 0) === Number(po.branch_id)));
const grnWarehouses = computed(() => !grn.branch_id ? refs.value.warehouses || [] : (refs.value.warehouses || []).filter((w) => Number(w.branch_id || 0) === Number(grn.branch_id)));
const locationLabel = (loc) => [loc.zone, loc.aisle, loc.rack, loc.shelf, loc.bin].filter(Boolean).join(' / ') || loc.name || `Location #${loc.id}`;
const activeGrnLocations = computed(() => warehouseLocations.value.filter((loc) => Number(loc.warehouse_id) === Number(grn.warehouse_id) && (loc.status || 'active') === 'active'));
const syncGrnLocation = (item) => {
    const loc = warehouseLocations.value.find((row) => Number(row.id) === Number(item.warehouse_location_id));
    item.warehouse_location = loc ? locationLabel(loc) : '';
};
const approvedRequisitions = computed(() => requisitions.value.filter((r) => ['approved'].includes(r.status)));
const receivablePurchaseOrders = computed(() => purchaseOrders.value.filter((order) => ['ordered', 'confirmed', 'partially_received', 'partial_received'].includes(order.status) && (order.items || []).some((item) => pendingQty(item) > 0)));
const poTotals = computed(() => {
    const subtotal = po.items.reduce((sum, item) => sum + (Number(item.ordered_quantity || 0) * Number(item.purchase_rate || 0)), 0);
    const discount = po.items.reduce((sum, item) => sum + lineDiscount(item), 0);
    const taxable = Math.max(0, subtotal - discount);
    const tax = po.items.reduce((sum, item) => sum + lineTax(item), 0);
    const grandBeforeRound = taxable + tax;
    const rounded = Math.round(grandBeforeRound);
    return { subtotal, discount, taxable, tax, roundOff: rounded - grandBeforeRound, grandTotal: rounded };
});

const pendingQty = (item) => Math.max(0, Number(item.ordered_quantity || 0) - Number(item.received_quantity || 0));
const acceptedQty = (item) => Math.max(0, Number(item.received_quantity || 0) - Number(item.rejected_quantity || 0) - Number(item.damaged_quantity || 0));
const lineDiscount = (item) => Number(item.discount_amount || item.discount || 0);
const lineTax = (item) => Math.max(0, (Number(item.ordered_quantity || 0) * Number(item.purchase_rate || 0) - lineDiscount(item)) * Number(item.gst_rate || item.tax_snapshot?.gst_rate || 0) / 100);
const lineTotal = (item) => Math.max(0, Number(item.ordered_quantity || 0) * Number(item.purchase_rate || 0) - lineDiscount(item) + lineTax(item));
const statusLabel = (status) => String(status || 'draft').replaceAll('_', ' ').replace(/\b\w/g, (c) => c.toUpperCase());

const resetRequisition = () => Object.assign(requisition, { branch_id: '', requisition_date: today, department: '', priority: 'normal', required_date: '', status: 'draft', remarks: '', items: [] });
const resetPo = () => Object.assign(po, { branch_id: '', warehouse_id: '', supplier_id: '', purchase_requisition_id: '', po_date: today, expected_delivery_date: '', payment_terms: '', supplier_reference: '', currency: 'INR', status: 'draft', terms_conditions: '', remarks: '', items: [] });
const resetGrn = () => { selectedPoId.value = ''; Object.assign(grn, { branch_id: '', warehouse_id: '', purchase_order_id: '', supplier_id: '', receipt_date: today, supplier_challan_number: '', supplier_invoice_number: '', vehicle_number: '', qc_status: 'pending', status: 'draft', remarks: '', items: [] }); };

const load = async () => {
    loading.value = true;
    try {
        const [referenceData, dashboardData, reportData, reqData, poData, grnData, locationData] = await Promise.all([
            OrderApi.references('purchase'),
            OrderApi.dashboard('purchase'),
            OrderApi.reports('purchase'),
            OrderApi.requisitions({ per_page: 100 }),
            OrderApi.purchaseOrders({ per_page: 100 }),
            OrderApi.goodsReceipts({ per_page: 100 }),
            InventoryApi.warehouseLocations({ active_only: 1, per_page: 500 }),
        ]);
        refs.value = referenceData;
        dashboard.value = dashboardData;
        reports.value = reportData;
        requisitions.value = reqData.purchase_requisitions || [];
        purchaseOrders.value = poData.purchase_orders || [];
        receipts.value = grnData.goods_receipts || [];
        warehouseLocations.value = locationData.locations || [];
    } finally {
        loading.value = false;
    }
};

const capture = (error) => {
    errors.value = error?.response?.data?.errors || { form: [error?.response?.data?.message || 'Unable to save. Please check the highlighted fields.'] };
};

const run = async (callback, message = 'Saved successfully.') => {
    if (saving.value) return;
    saving.value = true;
    errors.value = {};
    try {
        await callback();
        await load();
        alert(message);
    } catch (error) {
        capture(error);
    } finally {
        saving.value = false;
    }
};

const searchProducts = async () => {
    if (productSearch.value.trim().length < 2) { productResults.value = []; return; }
    productResults.value = await OrderApi.products(productSearch.value.trim(), 'purchase');
};

const suggestedPurchaseQty = (product) => {
    const qty = Number(product.suggested_purchase_quantity || product.reorder_stock || product.maximum_stock || product.minimum_stock || 0);
    return qty > 0 ? qty : 1;
};

const addReqProduct = (product) => {
    requisition.items.push({ product_id: product.id, product, unit_id: product.unit_id || '', quantity: suggestedPurchaseQty(product), approved_quantity: '', remarks: '' });
    productSearch.value = '';
    productResults.value = [];
};

const addPoProduct = (product) => {
    po.items.push({ product_id: product.id, product, product_variant_id: '', unit_id: product.unit_id || '', ordered_quantity: suggestedPurchaseQty(product), purchase_rate: Number(product.purchase_price || product.cost_price || 0), discount_amount: 0, gst_rate: Number(product.gst_rate || 0), remarks: '' });
    productSearch.value = '';
    productResults.value = [];
};

const saveRequisition = (status) => run(() => OrderApi.saveRequisition({ ...requisition, status, items: requisition.items.map((item) => ({ ...item, product: undefined, approved_quantity: item.approved_quantity || item.quantity })) }), status === 'draft' ? 'Requisition draft saved.' : 'Requisition submitted for approval.');
const savePo = (status) => run(() => OrderApi.savePurchaseOrder({ ...po, status, items: po.items.map((item) => ({ ...item, product: undefined, line_total: lineTotal(item), tax_amount: lineTax(item) })) }), status === 'draft' ? 'Purchase order draft saved.' : 'Purchase order saved.');
const confirmPo = (order) => run(() => OrderApi.confirmPurchaseOrder(order.id, { confirmation_status: 'accepted' }), 'Purchase order confirmed.');
const saveGrn = (status) => run(() => OrderApi.saveGoodsReceipt({ ...grn, status, items: grn.items.map((item) => ({ ...item, product: undefined, received_quantity: Number(item.received_quantity || 0), rejected_quantity: Number(item.rejected_quantity || 0), damaged_quantity: Number(item.damaged_quantity || 0) })) }), status === 'draft' ? 'GRN draft saved.' : 'Goods received and inventory updated.');
const receiveExisting = (receipt) => run(() => OrderApi.receiveGoods(receipt.id), 'Goods received and inventory updated.');

const createPoFromRequisition = (row) => {
    resetPo();
    po.branch_id = row.branch_id || '';
    po.purchase_requisition_id = row.id;
    po.items = (row.items || []).map((item) => ({ product_id: item.product_id, product: item.product, unit_id: item.unit_id || item.product?.unit_id || '', ordered_quantity: Number(item.approved_quantity || item.quantity || 1), purchase_rate: Number(item.product?.purchase_price || item.product?.cost_price || 0), discount_amount: 0, gst_rate: Number(item.product?.gst_rate || 0), remarks: item.remarks || '' }));
    tab.value = 'purchase-orders';
};

const loadPoIntoGrn = () => {
    const order = purchaseOrders.value.find((item) => Number(item.id) === Number(selectedPoId.value));
    if (!order) return resetGrn();
    Object.assign(grn, {
        branch_id: order.branch_id || '',
        warehouse_id: order.warehouse_id || '',
        purchase_order_id: order.id,
        supplier_id: order.supplier_id,
        receipt_date: today,
        status: 'draft',
        qc_status: 'pending',
        remarks: '',
        items: (order.items || []).filter((item) => pendingQty(item) > 0).map((item) => ({
            purchase_order_item_id: item.id,
            product_id: item.product_id,
            product_variant_id: item.product_variant_id || '',
            product: item.product,
            ordered_quantity: Number(item.ordered_quantity || 0),
            previous_received_quantity: Number(item.received_quantity || 0),
            pending_quantity: pendingQty(item),
            received_quantity: pendingQty(item),
            rejected_quantity: 0,
            damaged_quantity: 0,
            unit_cost: Number(item.purchase_rate || 0),
            batch_number: '',
            manufacturing_date: '',
            expiry_date: '',
            warehouse_location: '',
            warehouse_location_id: '',
            qc_status: 'pending',
            remarks: '',
        })),
    });
};

const exportCsv = (rows, filename) => {
    const safeRows = rows || [];
    const headers = Object.keys(safeRows[0] || { empty: '' }).filter((header) => typeof safeRows[0]?.[header] !== 'object');
    const csv = [headers.join(','), ...safeRows.map((row) => headers.map((header) => `"${String(row[header] ?? '').replaceAll('"', '""')}"`).join(','))].join('\n');
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
    link.download = filename;
    link.click();
};

const printDoc = (title, number) => {
    const win = window.open('', '_blank');
    win.document.write(`<title>${title}</title><body style="font-family:Arial;padding:24px"><h2>${title}</h2><p>${number || 'Draft'}</p></body>`);
    win.document.close();
    win.print();
};

const printPage = () => window.print();

watch(selectedPoId, loadPoIntoGrn);
onMounted(async () => {
    await load();
    const poId = new URLSearchParams(window.location.search).get('po_id');
    if (poId) {
        tab.value = 'grn';
        selectedPoId.value = poId;
        loadPoIntoGrn();
    }
});
</script>

<template>
    <Layout :page="page" :title="title">
        <template #topbar-title>
            <div class="bill-page-title"><span>Purchase / Stock Inward / GRN</span><h1>Purchase Management</h1><p>Requisition to purchase order to goods receipt and bill creation.</p></div>
        </template>

        <div class="purchase-workflow">
            <header class="page-head">
                <div><span class="crumb">Purchase / Stock Inward / GRN</span><h2>{{ tabs.find((item) => item.id === tab)?.label }}</h2></div>
                <div class="head-actions"><button @click="tab = 'requisitions'">+ New Requisition</button><button @click="tab = 'purchase-orders'">+ New Purchase Order</button><button class="primary" @click="tab = 'grn'">+ Receive Goods / GRN</button></div>
            </header>

            <nav class="tabs" aria-label="Purchase workflow tabs"><button v-for="item in tabs" :key="item.id" :class="{ active: tab === item.id }" @click="tab = item.id">{{ item.label }}</button></nav>
            <div v-if="errors.form || Object.keys(errors).length" class="alert">{{ errors.form?.[0] || Object.values(errors)[0]?.[0] }}</div>

            <section v-if="tab === 'overview'" class="stack">
                <div class="kpis">
                    <article><span>Purchase Requisitions</span><strong>{{ dashboard.purchase_requisitions || 0 }}</strong></article>
                    <article><span>Pending Approval</span><strong>{{ dashboard.requisitions_pending_approval || 0 }}</strong></article>
                    <article><span>Open Purchase Orders</span><strong>{{ dashboard.open_purchase_orders || 0 }}</strong></article>
                    <article><span>PO Value</span><strong>Rs. {{ money(dashboard.po_value) }}</strong></article>
                    <article><span>GRN Pending</span><strong>{{ dashboard.grn_pending || 0 }}</strong></article>
                    <article><span>Partially Received</span><strong>{{ dashboard.partially_received_orders || 0 }}</strong></article>
                    <article><span>Goods Received Today</span><strong>{{ dashboard.goods_received_today || 0 }}</strong></article>
                    <article><span>Purchase Value This Month</span><strong>Rs. {{ money(dashboard.purchase_value_this_month) }}</strong></article>
                    <article><span>Supplier Pending Deliveries</span><strong>{{ dashboard.supplier_pending_deliveries || 0 }}</strong></article>
                    <article><span>Backordered Purchase Items</span><strong>{{ dashboard.backordered_purchase_items || 0 }}</strong></article>
                </div>
                <section class="panel">
                    <div class="section-title"><h3>Recent Activity</h3><button @click="load">Refresh</button></div>
                    <div class="table-scroll"><table><thead><tr><th>Type</th><th>Reference</th><th>Supplier</th><th>Date</th><th>Amount</th><th>Status</th></tr></thead><tbody><tr v-for="row in dashboard.recent_activity" :key="`${row.type}-${row.reference}`"><td>{{ row.type }}</td><td>{{ row.reference }}</td><td>{{ row.supplier }}</td><td>{{ row.date }}</td><td>{{ row.amount === null ? '-' : `Rs. ${money(row.amount)}` }}</td><td><span class="badge" :class="row.status">{{ statusLabel(row.status) }}</span></td></tr><tr v-if="!dashboard.recent_activity?.length"><td colspan="6" class="empty">No purchase activity yet. Create your first purchase order to start tracking supplier deliveries.</td></tr></tbody></table></div>
                </section>
            </section>

            <section v-if="tab === 'requisitions'" class="panel">
                <div class="section-title"><h3>Purchase Requisition</h3><span class="badge" :class="requisition.status">{{ statusLabel(requisition.status) }}</span></div>
                <div class="form-grid">
                    <label>Requisition Number<input value="Auto generated" disabled /></label><label>Branch<select v-model="requisition.branch_id"><option value="">Select Branch</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select></label><label>Department<input v-model="requisition.department" /></label><label>Request Date<input v-model="requisition.requisition_date" type="date" /></label><label>Required By Date<input v-model="requisition.required_date" type="date" /></label><label>Priority<select v-model="requisition.priority"><option value="normal">Normal</option><option value="urgent">Urgent</option><option value="critical">Critical</option></select></label><label>Requested By<input value="Current user" disabled /></label><label class="wide">Remarks<textarea v-model="requisition.remarks"></textarea></label>
                </div>
                <div class="product-search"><input v-model="productSearch" placeholder="Search by Product Name, SKU or Barcode" @input="searchProducts" /><div v-if="productResults.length" class="search-menu"><button v-for="product in productResults" :key="product.id" @click="addReqProduct(product)"><strong>{{ product.name }}</strong><span>SKU: {{ product.sku || '-' }} · Stock: {{ qty(product.current_stock) }} {{ productUnit(product) }} · Last Purchase: Rs. {{ money(product.purchase_price || product.cost_price) }}</span></button></div></div>
                <div class="table-scroll"><table class="line-table"><thead><tr><th>Product</th><th>SKU</th><th>Current Stock</th><th>Required Qty</th><th>UOM</th><th>Remarks</th><th>Action</th></tr></thead><tbody><tr v-for="(item, index) in requisition.items" :key="index"><td data-label="Product">{{ productName(item) }}</td><td data-label="SKU">{{ productSku(item) }}</td><td data-label="Current Stock">{{ qty(item.product?.current_stock) }}</td><td data-label="Required Qty"><input v-model.number="item.quantity" type="number" step="0.001" /></td><td data-label="UOM">{{ productUnit(item.product) }}</td><td data-label="Remarks"><input v-model="item.remarks" /></td><td data-label="Action"><button class="danger" @click="requisition.items.splice(index,1)">Remove</button></td></tr><tr v-if="!requisition.items.length"><td colspan="7" class="empty">No requisition products yet. Search and add stock requirements.</td></tr></tbody></table></div>
                <div class="sticky-actions"><button @click="resetRequisition">Clear</button><button :disabled="saving" @click="saveRequisition('draft')">Save Draft</button><button class="primary" :disabled="saving" @click="saveRequisition('submitted')">Submit for Approval</button></div>
                <div class="list-table"><table><thead><tr><th>Requisition</th><th>Branch</th><th>Department</th><th>Required By</th><th>Priority</th><th>Status</th><th>Action</th></tr></thead><tbody><tr v-for="row in requisitions" :key="row.id"><td>{{ row.requisition_number }}</td><td>{{ row.branch?.name || '-' }}</td><td>{{ row.department || '-' }}</td><td>{{ row.required_date || '-' }}</td><td>{{ statusLabel(row.priority) }}</td><td><span class="badge" :class="row.status">{{ statusLabel(row.status) }}</span></td><td><button v-if="row.status === 'approved'" @click="createPoFromRequisition(row)">Create Purchase Order</button></td></tr></tbody></table></div>
            </section>

            <section v-if="tab === 'purchase-orders'" class="panel">
                <div class="section-title"><h3>Purchase Order</h3><span class="badge" :class="po.status">{{ statusLabel(po.status) }}</span></div>
                <div class="form-grid">
                    <label>PO Number<input value="Auto generated" disabled /></label><label>Branch<select v-model="po.branch_id"><option value="">Select Branch</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select></label><label>Warehouse<select v-model="po.warehouse_id"><option value="">Select Warehouse</option><option v-for="w in filteredWarehouses" :key="w.id" :value="w.id">{{ w.name }}</option></select></label><label>Supplier<select v-model="po.supplier_id"><option value="">Search Supplier</option><option v-for="s in refs.suppliers" :key="s.id" :value="s.id">{{ supplierName(s) }}</option></select></label><label>PO Date<input v-model="po.po_date" type="date" /></label><label>Expected Delivery Date<input v-model="po.expected_delivery_date" type="date" /></label><label>Payment Terms<input v-model="po.payment_terms" placeholder="Net 30, COD" /></label><label>Supplier Reference<input v-model="po.supplier_reference" /></label><label>Requisition Reference<select v-model="po.purchase_requisition_id"><option value="">Direct PO</option><option v-for="r in approvedRequisitions" :key="r.id" :value="r.id">{{ r.requisition_number }}</option></select></label><label>Currency<input v-model="po.currency" /></label><label class="wide">Remarks<textarea v-model="po.remarks"></textarea></label>
                </div>
                <div class="product-search"><input v-model="productSearch" placeholder="Search by Product Name, SKU or Barcode" @input="searchProducts" /><div v-if="productResults.length" class="search-menu"><button v-for="product in productResults" :key="product.id" @click="addPoProduct(product)"><strong>{{ product.name }}</strong><span>SKU: {{ product.sku || '-' }} · Stock: {{ qty(product.current_stock) }} {{ productUnit(product) }} · Last Purchase: Rs. {{ money(product.purchase_price || product.cost_price) }}</span></button></div></div>
                <div class="table-scroll"><table class="line-table"><thead><tr><th>Product</th><th>SKU</th><th>Ordered Qty</th><th>UOM</th><th>Purchase Rate</th><th>Discount</th><th>Tax %</th><th>Tax Amount</th><th>Line Total</th><th>Remarks</th><th>Action</th></tr></thead><tbody><tr v-for="(item, index) in po.items" :key="index"><td data-label="Product">{{ productName(item) }}</td><td data-label="SKU">{{ productSku(item) }}</td><td data-label="Ordered Qty"><input v-model.number="item.ordered_quantity" type="number" step="0.001" /></td><td data-label="UOM">{{ productUnit(item.product) }}</td><td data-label="Purchase Rate"><input v-model.number="item.purchase_rate" type="number" step="0.01" /></td><td data-label="Discount"><input v-model.number="item.discount_amount" type="number" step="0.01" /></td><td data-label="Tax %"><input v-model.number="item.gst_rate" type="number" step="0.01" /></td><td data-label="Tax Amount">Rs. {{ money(lineTax(item)) }}</td><td data-label="Line Total">Rs. {{ money(lineTotal(item)) }}</td><td data-label="Remarks"><input v-model="item.remarks" /></td><td data-label="Action"><button class="danger" @click="po.items.splice(index,1)">Remove</button></td></tr><tr v-if="!po.items.length"><td colspan="11" class="empty">No purchase order items yet. Add products to build the PO.</td></tr></tbody></table></div>
                <div class="totals"><span>Subtotal <strong>Rs. {{ money(poTotals.subtotal) }}</strong></span><span>Discount <strong>Rs. {{ money(poTotals.discount) }}</strong></span><span>Taxable Amount <strong>Rs. {{ money(poTotals.taxable) }}</strong></span><span>GST / Tax <strong>Rs. {{ money(poTotals.tax) }}</strong></span><span>Round Off <strong>Rs. {{ money(poTotals.roundOff) }}</strong></span><span class="grand">Grand Total <strong>Rs. {{ money(poTotals.grandTotal) }}</strong></span></div>
                <div class="sticky-actions"><button @click="resetPo">Clear</button><button :disabled="saving" @click="savePo('draft')">Save Draft</button><button class="primary" :disabled="saving" @click="savePo('confirmed')">Send / Confirm PO</button><button @click="printDoc('Purchase Order', 'Draft')">Print PO</button><button>Download PDF</button><button class="danger">Cancel PO</button></div>
                <div class="list-table"><table><thead><tr><th>PO Number</th><th>Supplier</th><th>Warehouse</th><th>PO Date</th><th>Expected</th><th>Total</th><th>Received</th><th>Status</th><th>Action</th></tr></thead><tbody><tr v-for="order in purchaseOrders" :key="order.id"><td>{{ order.po_number }}</td><td>{{ supplierName(order.supplier) }}</td><td>{{ order.warehouse?.name || '-' }}</td><td>{{ order.po_date }}</td><td>{{ order.expected_delivery_date || '-' }}</td><td>Rs. {{ money(order.grand_total) }}</td><td>{{ qty((order.items || []).reduce((sum, item) => sum + Number(item.received_quantity || 0), 0)) }}</td><td><span class="badge" :class="order.status">{{ statusLabel(order.status) }}</span></td><td><button v-if="order.status !== 'confirmed'" @click="confirmPo(order)">Confirm</button><button v-if="['confirmed','partially_received','partial_received'].includes(order.status)" @click="selectedPoId = order.id; tab = 'grn'">Receive Goods</button><button @click="printDoc('Purchase Order', order.po_number)">Print</button></td></tr><tr v-if="!purchaseOrders.length"><td colspan="9" class="empty">No purchase orders yet. Create your first purchase order to start tracking supplier deliveries.</td></tr></tbody></table></div>
            </section>

            <section v-if="tab === 'grn'" class="panel">
                <div class="section-title"><h3>Goods Receipt Note</h3><span class="badge" :class="grn.status">{{ statusLabel(grn.status) }}</span></div>
                <div class="form-grid">
                    <label>Select Purchase Order<select v-model="selectedPoId"><option value="">Select Purchase Order</option><option v-for="order in receivablePurchaseOrders" :key="order.id" :value="order.id">{{ order.po_number }} - {{ supplierName(order.supplier) }}</option></select></label><label>GRN Number<input value="Auto generated" disabled /></label><label>Supplier<select v-model="grn.supplier_id" disabled><option value="">Loaded from PO</option><option v-for="s in refs.suppliers" :key="s.id" :value="s.id">{{ supplierName(s) }}</option></select></label><label>Branch<select v-model="grn.branch_id" disabled><option value="">Loaded from PO</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select></label><label>Warehouse<select v-model="grn.warehouse_id" disabled><option value="">Loaded from PO</option><option v-for="w in grnWarehouses" :key="w.id" :value="w.id">{{ w.name }}</option></select></label><label>Receipt Date<input v-model="grn.receipt_date" type="date" /></label><label>Supplier Challan Number<input v-model="grn.supplier_challan_number" /></label><label>Supplier Invoice Number<input v-model="grn.supplier_invoice_number" /></label><label>Vehicle Number<input v-model="grn.vehicle_number" /></label><label>Received By<input value="Current user" disabled /></label><label class="wide">Remarks<textarea v-model="grn.remarks"></textarea></label>
                </div>
                <div class="table-scroll"><table class="line-table grn-lines"><thead><tr><th>Product</th><th>SKU</th><th>Ordered</th><th>Previous Received</th><th>Pending</th><th>Received Now</th><th>Rejected/Damaged</th><th>Accepted</th><th>Batch No.</th><th>MFG Date</th><th>Expiry Date</th><th>Storage Location</th><th>Remarks</th></tr></thead><tbody><tr v-for="(item, index) in grn.items" :key="index"><td data-label="Product">{{ productName(item) }}</td><td data-label="SKU">{{ productSku(item) }}</td><td data-label="Ordered">{{ qty(item.ordered_quantity) }}</td><td data-label="Previous Received">{{ qty(item.previous_received_quantity) }}</td><td data-label="Pending">{{ qty(item.pending_quantity) }}</td><td data-label="Received Now"><input v-model.number="item.received_quantity" type="number" :max="item.pending_quantity" step="0.001" /></td><td data-label="Rejected/Damaged"><input v-model.number="item.rejected_quantity" type="number" step="0.001" /><input v-model.number="item.damaged_quantity" type="number" step="0.001" /></td><td data-label="Accepted">{{ qty(acceptedQty(item)) }}</td><td data-label="Batch No."><input v-model="item.batch_number" /></td><td data-label="MFG Date"><input v-model="item.manufacturing_date" type="date" /></td><td data-label="Expiry Date"><input v-model="item.expiry_date" type="date" /></td><td data-label="Storage Location"><select v-if="activeGrnLocations.length" v-model="item.warehouse_location_id" @change="syncGrnLocation(item)"><option value="">Select Location</option><option v-for="loc in activeGrnLocations" :key="loc.id" :value="loc.id">{{ locationLabel(loc) }}</option></select><input v-else value="No active locations" disabled /></td><td data-label="Remarks"><input v-model="item.remarks" /></td></tr><tr v-if="!grn.items.length"><td colspan="13" class="empty">Select a confirmed purchase order to load pending products for GRN.</td></tr></tbody></table></div>
                <div class="sticky-actions"><button @click="resetGrn">Clear</button><button :disabled="saving || !grn.items.length" @click="saveGrn('draft')">Save Draft</button><button class="primary" :disabled="saving || !grn.items.length" @click="saveGrn('received')">Confirm / Receive Goods</button><button :disabled="!grn.items.length">Create Purchase Bill</button></div>
                <div class="list-table"><table><thead><tr><th>GRN Number</th><th>PO</th><th>Supplier</th><th>Warehouse</th><th>Date</th><th>Status</th><th>Action</th></tr></thead><tbody><tr v-for="receipt in receipts" :key="receipt.id"><td>{{ receipt.grn_number }}</td><td>{{ receipt.order?.po_number || '-' }}</td><td>{{ supplierName(receipt.supplier) }}</td><td>{{ receipt.warehouse?.name || '-' }}</td><td>{{ receipt.receipt_date }}</td><td><span class="badge" :class="receipt.status">{{ statusLabel(receipt.status) }}</span></td><td><button v-if="receipt.status === 'draft'" @click="receiveExisting(receipt)">Confirm Receipt</button><button @click="printDoc('Goods Receipt Note', receipt.grn_number)">Print</button><button v-if="receipt.status === 'received'">Create Purchase Bill</button></td></tr><tr v-if="!receipts.length"><td colspan="7" class="empty">No goods receipts yet. Select a confirmed PO and receive goods when supplier stock arrives.</td></tr></tbody></table></div>
            </section>

            <section v-if="tab === 'reports'" class="panel">
                <div class="section-title"><h3>Purchase Reports</h3><div class="report-actions"><button @click="exportCsv(reports.purchase_order_report, 'purchase-order-report.csv')">Export CSV</button><button @click="printPage">Print</button><button>Export Excel</button><button>PDF</button></div></div>
                <div class="form-grid filters"><label>Date From<input v-model="filters.date_from" type="date" /></label><label>Date To<input v-model="filters.date_to" type="date" /></label><label>Branch<select v-model="filters.branch_id"><option value="">All Branches</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select></label><label>Warehouse<select v-model="filters.warehouse_id"><option value="">All Warehouses</option><option v-for="w in refs.warehouses" :key="w.id" :value="w.id">{{ w.name }}</option></select></label><label>Supplier<select v-model="filters.supplier_id"><option value="">All Suppliers</option><option v-for="s in refs.suppliers" :key="s.id" :value="s.id">{{ supplierName(s) }}</option></select></label><label>PO Status<select v-model="filters.status"><option value="">All Statuses</option><option value="confirmed">Confirmed</option><option value="partially_received">Partially Received</option><option value="fully_received">Fully Received</option></select></label></div>
                <div class="report-grid"><article v-for="name in ['Purchase Requisition Report','Purchase Order Report','Pending PO Report','GRN Report','Pending GRN Report','Partial Receipt Report','Supplier Purchase Report','Supplier Pending Delivery Report','Purchase Item Report','Warehouse Receipt Report','Purchase Tax Report']" :key="name"><h4>{{ name }}</h4><p>Use filters above, then export, print, or review the latest records.</p></article></div>
                <div class="table-scroll"><table><thead><tr><th>PO</th><th>Supplier</th><th>Warehouse</th><th>Total</th><th>Status</th></tr></thead><tbody><tr v-for="order in reports.purchase_order_report || []" :key="order.id"><td>{{ order.po_number }}</td><td>{{ supplierName(order.supplier) }}</td><td>{{ order.warehouse?.name || '-' }}</td><td>Rs. {{ money(order.grand_total) }}</td><td><span class="badge" :class="order.status">{{ statusLabel(order.status) }}</span></td></tr></tbody></table></div>
            </section>
        </div>
    </Layout>
</template>

<style scoped>
.purchase-workflow{padding:4px 0 28px;color:#233047}.page-head,.section-title,.head-actions,.tabs,.sticky-actions,.report-actions{display:flex;align-items:center;gap:10px}.page-head{justify-content:space-between;margin-bottom:14px}.crumb{color:#2457d6;font-size:11px;font-weight:800}.page-head h2,.section-title h3{margin:3px 0 0;color:#13213a;font-weight:850}.head-actions,.sticky-actions,.report-actions{flex-wrap:wrap}.head-actions button,.sticky-actions button,.report-actions button{white-space:nowrap}.tabs{flex-wrap:wrap;margin-bottom:14px}.tabs button.active{color:#fff;background:#173b77;border-color:#173b77}.stack{display:grid;gap:14px}.panel{padding:18px;margin-bottom:18px;background:#fff;border:1px solid #dfe6ef;border-radius:8px}.kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px}.kpis article,.report-grid article{padding:14px;background:#fff;border:1px solid #e2e8f0;border-radius:8px}.kpis span,.report-grid p{display:block;color:#667085;font-size:11px}.kpis strong{display:block;margin-top:7px;color:#101828;font-size:18px}.form-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:14px 0}.form-grid label{display:grid;gap:6px;color:#526077;font-size:11px;font-weight:800}.wide{grid-column:span 2}.product-search{position:relative;margin:12px 0}.product-search input{width:100%}.search-menu{position:absolute;z-index:25;top:44px;left:0;right:0;display:grid;max-height:260px;overflow:auto;background:#fff;border:1px solid #d8e0eb;border-radius:8px;box-shadow:0 18px 42px rgba(16,24,40,.16)}.search-menu button{display:grid;justify-items:start;border:0;border-bottom:1px solid #eef2f7;border-radius:0}.search-menu span{color:#69758a;font-size:11px}.table-scroll,.list-table{overflow-x:auto}.list-table{margin-top:18px}table{width:100%;border-collapse:collapse}th{padding:11px 10px;color:#64748b;background:#f8fafc;border-bottom:1px solid #e6edf5;text-align:left;font-size:10px;font-weight:850;text-transform:uppercase;white-space:nowrap}td{padding:11px 10px;border-bottom:1px solid #edf1f5;font-size:12px;white-space:nowrap}input,select,textarea,button{min-height:38px;padding:8px 10px;color:#344159;background:#fff;border:1px solid #d8e0eb;border-radius:8px;font-size:12px}textarea{min-height:74px;resize:vertical}button{font-weight:800;cursor:pointer}.primary{color:#fff;background:#2457d6;border-color:#2457d6}.danger{color:#b42318;background:#fff4f4;border-color:#ffd5d5}.badge{display:inline-flex;padding:5px 8px;border-radius:7px;color:#344054;background:#f2f4f7;font-size:10px;font-weight:850}.badge.submitted,.badge.pending_approval{color:#92400e;background:#fffbeb}.badge.approved,.badge.confirmed,.badge.received,.badge.fully_received{color:#067647;background:#ecfdf3}.badge.partially_received,.badge.partial_received{color:#175cd3;background:#eff8ff}.badge.rejected,.badge.cancelled{color:#b42318;background:#fff4f4}.empty{padding:28px!important;color:#7a869a;text-align:center}.alert{padding:11px 12px;margin-bottom:12px;color:#b42318;background:#fff4f4;border:1px solid #ffd5d5;border-radius:8px;font-size:12px}.sticky-actions{position:sticky;bottom:0;justify-content:flex-end;margin:16px -18px -18px;padding:12px 18px;background:#fff;border-top:1px solid #edf1f5}.totals{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;margin-top:14px}.totals span{padding:10px;border:1px solid #edf1f5;border-radius:8px;color:#667085;font-size:11px}.totals strong{display:block;margin-top:4px;color:#142139;font-size:14px}.totals .grand{background:#f8fafc}.report-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:14px 0}.report-grid h4{margin:0 0 6px;color:#172033;font-size:13px}.filters{margin-top:8px}@media(max-width:1180px){.kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.form-grid,.report-grid,.totals{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:760px){.page-head,.section-title{align-items:stretch;flex-direction:column}.head-actions button,.sticky-actions button{width:100%}.kpis,.form-grid,.report-grid,.totals{grid-template-columns:1fr}.wide{grid-column:auto}.line-table thead{display:none}.line-table,.line-table tbody,.line-table tr,.line-table td{display:block;width:100%}.line-table tr{margin-bottom:12px;padding:10px;border:1px solid #e5ebf2;border-radius:8px}.line-table td{display:grid;grid-template-columns:130px minmax(0,1fr);gap:8px;border:0;white-space:normal}.line-table td::before{content:attr(data-label);color:#667085;font-size:11px;font-weight:850}.line-table td input{width:100%;min-width:0}.sticky-actions{position:static;margin:16px 0 0;padding:12px 0 0}.table-scroll{overflow-x:visible}}
</style>
