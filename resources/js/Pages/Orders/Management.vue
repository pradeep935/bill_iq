<script setup>
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { printDocument } from '../../Support/printDocument.js';
import { computed, onMounted, reactive, ref } from 'vue';
import Layout from '../Layout.vue';
import OrderApi from './OrderApi';
import SearchSelect from '../../Components/Common/SearchSelect.vue';

const props = defineProps({ page: { type: String, default: 'sales' }, title: { type: String, default: 'Order Management' }, initial_tab: { type: String, default: 'sales-orders' } });

const scope = computed(() => ['inventory-orders', 'inventory-inward'].includes(props.page) ? 'purchase' : 'sales');
const today = new Date().toISOString().slice(0, 10);
const tab = ref(new URLSearchParams(window.location.search).get('tab') || props.initial_tab);
const loading = ref(false);
const saving = ref(false);
const errors = ref({});
const refs = ref({ customers: [], suppliers: [], branches: [], warehouses: [], products: [] });
const dashboard = ref({});
const reports = ref({});
const quotations = ref([]);
const salesOrders = ref([]);
const challans = ref([]);
const requisitions = ref([]);
const purchaseOrders = ref([]);
const receipts = ref([]);

const quotation = reactive({ branch_id: '', quotation_date: today, valid_until: '', customer_id: '', discount_type: 'amount', discount_value: 0, shipping_amount: 0, status: 'draft', notes: '', terms_conditions: '', items: [{ product_id: '', quantity: 1, unit_price: 0, discount: 0, gst_rate: 0 }] });
const salesOrder = reactive({ branch_id: '', warehouse_id: '', quotation_id: '', customer_id: '', order_date: today, expected_delivery_date: '', order_status: 'draft', shipping: 0, remarks: '', items: [{ product_id: '', ordered_quantity: 1, unit_price: 0, discount_amount: 0, gst_rate: 0 }] });
const challan = reactive({ branch_id: '', warehouse_id: '', challan_date: today, customer_id: '', sales_order_id: '', status: 'draft', vehicle_number: '', transporter_name: '', dispatch_reference: '', tracking_number: '', shipping_cost: 0, remarks: '', items: [{ product_id: '', dispatch_quantity: 1, ordered_quantity: 0, unit_cost: 0, warehouse_location: '' }] });
const requisition = reactive({ branch_id: '', requisition_date: today, department: '', priority: 'normal', required_date: '', status: 'draft', remarks: '', items: [{ product_id: '', quantity: 1, approved_quantity: '', remarks: '' }] });
const purchaseOrder = reactive({ branch_id: '', warehouse_id: '', supplier_id: '', purchase_requisition_id: '', po_date: today, expected_delivery_date: '', status: 'draft', terms_conditions: '', remarks: '', items: [{ product_id: '', ordered_quantity: 1, purchase_rate: 0, gst_rate: 0, remarks: '' }] });
const receipt = reactive({ branch_id: '', warehouse_id: '', purchase_order_id: '', supplier_id: '', receipt_date: today, supplier_challan_number: '', qc_status: 'pending', status: 'draft', remarks: '', items: [{ product_id: '', ordered_quantity: 0, received_quantity: 1, rejected_quantity: 0, damaged_quantity: 0, unit_cost: 0, qc_status: 'pending', warehouse_location: '' }] });

const tabs = ['dashboard', 'quotations', 'sales-orders', 'challans', 'requisitions', 'purchase-orders', 'grn', 'reports'];
const visibleTabs = computed(() => scope.value === 'purchase' ? tabs.filter((t) => !['quotations', 'sales-orders', 'challans'].includes(t)) : tabs.filter((t) => !['requisitions', 'purchase-orders', 'grn'].includes(t)));
const money = (v) => Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const qty = (v) => Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
const nameOf = (rows, id, key = 'name') => rows.find((r) => Number(r.id) === Number(id))?.[key] || '';
const filteredWarehouses = (branchId) => !branchId ? refs.value.warehouses : refs.value.warehouses.filter((w) => Number(w.branch_id || 0) === Number(branchId));
const capture = (e) => { errors.value = e?.response?.data?.errors || { form: [e?.response?.data?.message || 'Unable to save.'] }; };
const clearErrors = () => { errors.value = {}; };
const addRow = (rows, row) => rows.push({ ...row });
const productChanged = (item, priceKey) => {
    const product = refs.value.products.find((p) => Number(p.id) === Number(item.product_id));
    if (!product) return;
    item.gst_rate = Number(product.gst_rate || 0);
    item.unit_id = product.unit_id || '';
    item[priceKey] = Number(product[priceKey === 'purchase_rate' ? 'purchase_price' : 'selling_price'] || 0);
};

const loadLists = async () => {
    const [q, so, dc, pr, po, grn] = await Promise.all([
        OrderApi.quotations().catch(() => ({ quotations: [] })),
        OrderApi.salesOrders().catch(() => ({ sales_orders: [] })),
        OrderApi.deliveryChallans().catch(() => ({ delivery_challans: [] })),
        OrderApi.requisitions().catch(() => ({ purchase_requisitions: [] })),
        OrderApi.purchaseOrders().catch(() => ({ purchase_orders: [] })),
        OrderApi.goodsReceipts().catch(() => ({ goods_receipts: [] })),
    ]);
    quotations.value = q.quotations || [];
    salesOrders.value = so.sales_orders || [];
    challans.value = dc.delivery_challans || [];
    requisitions.value = pr.purchase_requisitions || [];
    purchaseOrders.value = po.purchase_orders || [];
    receipts.value = grn.goods_receipts || [];
};

const load = async () => {
    loading.value = true;
    try {
        refs.value = await OrderApi.references(scope.value);
        if (!challan.warehouse_id && refs.value.warehouses?.length) { challan.warehouse_id = refs.value.warehouses[0].id; syncChallanBranch(); }
        dashboard.value = await OrderApi.dashboard(scope.value);
        reports.value = await OrderApi.reports(scope.value);
        await loadLists();
    } finally {
        loading.value = false;
    }
};

const run = async (callback) => {
    saving.value = true;
    clearErrors();
    try {
        await callback();
        await load();
    } catch (e) {
        capture(e);
    } finally {
        saving.value = false;
    }
};

const saveQuotation = (status) => run(() => OrderApi.saveQuotation({ ...quotation, status, items: quotation.items.map((i) => ({ ...i })) }));
const convertQuotation = (row) => run(() => OrderApi.convertQuotation(row.id));
const canShareQuotation = (row) => ['sent', 'viewed', 'accepted'].includes(row.status);
const shareQuotationWhatsApp = (row) => run(async () => {
    if (!canShareQuotation(row)) {
        errors.value = { form: ['Only sent, viewed or accepted quotations can be shared on WhatsApp.'] };
        return;
    }
    const shareWindow = window.open('about:blank', '_blank');
    try {
        const response = await OrderApi.shareQuotationWhatsApp(row.id);
        if (shareWindow) {
            shareWindow.opener = null;
            shareWindow.location.href = response.url;
        } else {
            window.location.href = response.url;
        }
    } catch (error) {
        if (shareWindow) shareWindow.close();
        throw error;
    }
});
const saveSalesOrder = (status) => run(() => OrderApi.saveSalesOrder({ ...salesOrder, order_status: status, items: salesOrder.items.map((i) => ({ ...i })) }));
const approveSalesOrder = (row) => run(() => OrderApi.approveSalesOrder(row.id));
const challanNote = ref('');
const emptyChallanItem = () => ({ product_id: '', dispatch_quantity: 1, ordered_quantity: 0, unit_cost: 0, warehouse_location: '' });
const syncChallanBranch = () => { challan.branch_id = refs.value.warehouses.find((w) => Number(w.id) === Number(challan.warehouse_id))?.branch_id || ''; };
const saveChallan = (status) => run(async () => {
    challanNote.value = '';
    await OrderApi.saveDeliveryChallan({ ...challan, status, items: challan.items.map((i) => ({ ...i })) });
    // Clear the form so the same goods are not sent twice by mistake.
    Object.assign(challan, { customer_id: '', remarks: '', vehicle_number: '', transporter_name: '', tracking_number: '', items: [emptyChallanItem()] });
    challanNote.value = status === 'draft' ? 'Draft saved.' : 'Saved. Stock has been reduced.';
});
const dispatchChallan = (row) => run(() => OrderApi.dispatchChallan(row.id));
const saveRequisition = (status) => run(() => OrderApi.saveRequisition({ ...requisition, status, items: requisition.items.map((i) => ({ ...i, approved_quantity: i.approved_quantity || i.quantity })) }));
const savePurchaseOrder = (status) => run(() => OrderApi.savePurchaseOrder({ ...purchaseOrder, status, items: purchaseOrder.items.map((i) => ({ ...i })) }));
const confirmPurchaseOrder = (row) => run(() => OrderApi.confirmPurchaseOrder(row.id, { confirmation_status: 'accepted' }));
const saveReceipt = (status) => run(() => OrderApi.saveGoodsReceipt({ ...receipt, status, items: receipt.items.map((i) => ({ ...i })) }));
const receiveGoods = (row) => run(() => OrderApi.receiveGoods(row.id));

const exportCsv = (rows, filename) => {
    const safeRows = rows || [];
    const headers = Object.keys(safeRows[0] || { empty: '' }).filter((h) => typeof safeRows[0]?.[h] !== 'object');
    const csv = [headers.join(','), ...safeRows.map((row) => headers.map((h) => `"${String(row[h] ?? '').replaceAll('"', '""')}"`).join(','))].join('\n');
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
    link.download = filename;
    link.click();
};

const inertia = usePage();
// A draft invoice for goods already sent; the invoice screen opens it for rates and posting.
const billChallan = async (row) => {
    try {
        const { data } = await axios.post(`/app/sales/delivery-challans/${row.id}/invoice`);
        window.location.href = `/app/sales/invoices?edit=${data.sale.id}`;
    } catch (error) {
        window.alert(Object.values(error.response?.data?.errors || {})[0]?.[0] || error.response?.data?.message || 'Could not make the bill.');
    }
};
const printDoc = (title, number, record) => printDocument(title, number, record, inertia.props.context?.business?.name);

onMounted(load);
</script>

<template>
    <Layout :page="page" :title="title">
        <template #topbar-title>
            <div class="bill-page-title"><span>ORDER MANAGEMENT</span><h1>{{ title }}</h1><p>Quotation to order to dispatch, and requisition to PO to goods receipt.</p></div>
        </template>
        <div class="orders-page">
            <div class="page-toolbar"><button :disabled="loading" @click="load">Refresh</button></div>
            <div class="tabs"><button v-for="t in visibleTabs" :key="t" :class="{ active: tab === t }" @click="tab = t">{{ String(t).replace(/-/g, ' ') }}</button></div>
            <div v-if="errors.form" class="alert">{{ errors.form[0] }}</div>
            <div v-if="Object.keys(errors).length && !errors.form" class="alert">{{ Object.values(errors)[0]?.[0] }}</div>

            <section v-if="tab === 'dashboard'" class="panel cards">
                <div><span>Quotations</span><strong>{{ dashboard.pending_quotations || 0 }}</strong></div><div><span>Quote Value</span><strong>Rs. {{ money(dashboard.quotation_value) }}</strong></div><div><span>Sales Orders</span><strong>{{ dashboard.pending_sales_orders || 0 }}</strong></div><div><span>Dispatch Pending</span><strong>{{ dashboard.pending_dispatch || 0 }}</strong></div><div><span>Purchase Orders</span><strong>{{ dashboard.pending_purchase_orders || 0 }}</strong></div><div><span>GRN Pending</span><strong>{{ dashboard.pending_goods_receipt || 0 }}</strong></div><div><span>Supplier Confirm</span><strong>{{ dashboard.pending_supplier_confirmation || 0 }}</strong></div><div><span>Back Orders</span><strong>{{ dashboard.pending_back_orders || 0 }}</strong></div>
            </section>

            <section v-if="tab === 'quotations'" class="panel">
                <div class="form-grid"><label class="bq-field"><span>Branch</span><select v-model="quotation.branch_id"><option value="">Branch</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select></label><label class="bq-field"><span>Customer</span><select v-model="quotation.customer_id"><option value="">Customer</option><option v-for="c in refs.customers" :key="c.id" :value="c.id">{{ c.customer_name }}</option></select></label><label class="bq-field"><span>Quotation date</span><input v-model="quotation.quotation_date" type="date" /></label><label class="bq-field"><span>Valid until</span><input v-model="quotation.valid_until" type="date" /></label><label class="bq-field"><span>Discount type</span><select v-model="quotation.discount_type"><option value="amount">Amount</option><option value="percentage">Percentage</option></select></label><label class="bq-field"><span>Discount</span><input v-model.number="quotation.discount_value" type="number" step="0.01" placeholder="Discount" /></label><label class="bq-field"><span>Shipping</span><input v-model.number="quotation.shipping_amount" type="number" step="0.01" placeholder="Shipping" /></label><label class="bq-field"><span>Notes</span><textarea v-model="quotation.notes" placeholder="Notes"></textarea></label></div>
                <div v-for="(item, i) in quotation.items" :key="i" class="line-grid"><label class="bq-field"><span>Product</span><select v-model="item.product_id" @change="productChanged(item, 'unit_price')"><option value="">Product</option><option v-for="p in refs.products" :key="p.id" :value="p.id">{{ p.name }} - {{ p.sku }}</option></select></label><label class="bq-field"><span>Quantity</span><input placeholder="Qty" title="Qty" v-model.number="item.quantity" type="number" step="0.001" /></label><label class="bq-field"><span>Rate</span><input placeholder="Rate" title="Rate" v-model.number="item.unit_price" type="number" step="0.01" /></label><label class="bq-field"><span>Discount</span><input v-model.number="item.discount" type="number" step="0.01" /></label><label class="bq-field"><span>GST %</span><input placeholder="GST %" title="GST %" v-model.number="item.gst_rate" type="number" step="0.01" /></label><button @click="quotation.items.splice(i,1)" :disabled="quotation.items.length === 1">Remove</button></div>
                <div class="actions"><button @click="addRow(quotation.items, { product_id: '', quantity: 1, unit_price: 0, discount: 0, gst_rate: 0 })">Add Item</button><button :disabled="saving" @click="saveQuotation('draft')">Save Draft</button><button :disabled="saving" @click="saveQuotation('sent')">Send</button></div>
                <div class="table-wrapper"><table><thead><tr><th>No.</th><th>Customer</th><th>Date</th><th>Total</th><th>Status</th><th></th></tr></thead><tbody><tr v-for="q in quotations" :key="q.id"><td>{{ q.quotation_number }}</td><td>{{ q.customer?.customer_name }}</td><td>{{ q.quotation_date }}</td><td>Rs. {{ money(q.grand_total) }}</td><td>{{ q.status }}</td><td><button @click="printDoc('Quotation', q.quotation_number, q)">Print</button><button :disabled="!canShareQuotation(q)" :title="canShareQuotation(q) ? 'Share quotation on WhatsApp' : 'Send quotation first'" @click="shareQuotationWhatsApp(q)">WhatsApp</button><button v-if="!q.converted_sales_order_id" @click="convertQuotation(q)">Convert</button></td></tr></tbody></table></div>
            </section>

            <section v-if="tab === 'sales-orders'" class="panel">
                <div class="form-grid"><label class="bq-field"><span>Branch</span><select v-model="salesOrder.branch_id"><option value="">Branch</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select></label><label class="bq-field"><span>Warehouse</span><select v-model="salesOrder.warehouse_id"><option value="">Warehouse</option><option v-for="w in filteredWarehouses(salesOrder.branch_id)" :key="w.id" :value="w.id">{{ w.name }}</option></select></label><label class="bq-field"><span>Customer</span><select v-model="salesOrder.customer_id"><option value="">Customer</option><option v-for="c in refs.customers" :key="c.id" :value="c.id">{{ c.customer_name }}</option></select></label><label class="bq-field"><span>Order date</span><input v-model="salesOrder.order_date" type="date" /></label><label class="bq-field"><span>Expected delivery date</span><input v-model="salesOrder.expected_delivery_date" type="date" /></label><label class="bq-field"><span>Shipping</span><input v-model.number="salesOrder.shipping" type="number" step="0.01" placeholder="Shipping" /></label><label class="bq-field"><span>Remarks</span><textarea v-model="salesOrder.remarks" placeholder="Remarks"></textarea></label></div>
                <div v-for="(item, i) in salesOrder.items" :key="i" class="line-grid"><label class="bq-field"><span>Product</span><select v-model="item.product_id" @change="productChanged(item, 'unit_price')"><option value="">Product</option><option v-for="p in refs.products" :key="p.id" :value="p.id">{{ p.name }} - {{ p.sku }}</option></select></label><label class="bq-field"><span>Quantity</span><input placeholder="Qty" title="Qty" v-model.number="item.ordered_quantity" type="number" step="0.001" /></label><label class="bq-field"><span>Rate</span><input placeholder="Rate" title="Rate" v-model.number="item.unit_price" type="number" step="0.01" /></label><label class="bq-field"><span>Discount</span><input placeholder="Discount" title="Discount" v-model.number="item.discount_amount" type="number" step="0.01" /></label><label class="bq-field"><span>GST %</span><input placeholder="GST %" title="GST %" v-model.number="item.gst_rate" type="number" step="0.01" /></label><button @click="salesOrder.items.splice(i,1)" :disabled="salesOrder.items.length === 1">Remove</button></div>
                <div class="actions"><button @click="addRow(salesOrder.items, { product_id: '', ordered_quantity: 1, unit_price: 0, discount_amount: 0, gst_rate: 0 })">Add Item</button><button :disabled="saving" @click="saveSalesOrder('draft')">Save Draft</button><button :disabled="saving" @click="saveSalesOrder('approved')">Approve & Reserve</button></div>
                <div class="table-wrapper"><table><thead><tr><th>No.</th><th>Customer</th><th>Warehouse</th><th>Total</th><th>Status</th><th>Reservation</th><th></th></tr></thead><tbody><tr v-for="o in salesOrders" :key="o.id"><td>{{ o.order_number }}</td><td>{{ o.customer?.customer_name }}</td><td>{{ o.warehouse?.name }}</td><td>Rs. {{ money(o.grand_total) }}</td><td>{{ o.order_status }}</td><td>{{ o.reservation_status }}</td><td><button @click="approveSalesOrder(o)">Reserve</button><button @click="printDoc('Sales Order', o.order_number, o)">Print</button></td></tr></tbody></table></div>
            </section>

            <section v-if="tab === 'challans'" class="panel">
                <p class="dc-help">Send goods out <strong>without a bill</strong> (sample, gift, self use). Choose the party and items, then click <strong>Save &amp; Reduce Stock</strong>.</p>
                <div class="dc-top">
                    <div class="dc-field"><span>1. Party / Customer <b class="req">*</b></span><SearchSelect v-model="challan.customer_id" class="dc-search" label="Customer" :options="refs.customers" option-value-key="id" option-label-key="customer_name" select-placeholder="Type party name" /></div>
                    <label class="dc-field"><span>Date <b class="req">*</b></span><input v-model="challan.challan_date" type="date" /></label>
                    <label class="dc-field"><span>Warehouse <b class="req">*</b></span><select v-model="challan.warehouse_id" @change="syncChallanBranch"><option value="">Select warehouse</option><option v-for="w in refs.warehouses" :key="w.id" :value="w.id">{{ w.name }}</option></select></label>
                    <label class="dc-field"><span>Reason / Remarks</span><input v-model="challan.remarks" list="dc-reasons" placeholder="e.g. Free sample, Gift, Self use" /><datalist id="dc-reasons"><option value="Free sample" /><option value="Gift" /><option value="Self use" /></datalist></label>
                </div>
                <div class="dc-line dc-head"><span>2. Item <b class="req">*</b></span><span>Quantity <b class="req">*</b></span><span></span></div>
                <div v-for="(item, i) in challan.items" :key="i" class="dc-line">
                    <SearchSelect v-model="item.product_id" class="dc-search" label="Item" :options="refs.products" option-value-key="id" option-label-key="name" select-placeholder="Type item name" />
                    <input v-model.number="item.dispatch_quantity" type="number" min="0.001" step="any" aria-label="Quantity" />
                    <button type="button" :disabled="challan.items.length === 1" @click="challan.items.splice(i,1)">Remove</button>
                </div>
                <button type="button" class="dc-add" @click="addRow(challan.items, emptyChallanItem())">+ Add another item</button>
                <details class="dc-more"><summary>Transport details (not required)</summary><div class="form-grid"><label class="bq-field"><span>Vehicle number</span><input v-model="challan.vehicle_number" placeholder="Vehicle number" /></label><label class="bq-field"><span>Transporter</span><input v-model="challan.transporter_name" placeholder="Transporter" /></label><label class="bq-field"><span>Tracking number</span><input v-model="challan.tracking_number" placeholder="Tracking number" /></label></div></details>
                <div v-if="challanNote" class="dc-note">{{ challanNote }}</div>
                <div class="actions"><button :disabled="saving" @click="saveChallan('draft')">Save Draft</button><button class="dc-primary" :disabled="saving" @click="saveChallan('dispatched')">{{ saving ? 'Saving...' : 'Save & Reduce Stock' }}</button></div>
                <div class="table-wrapper"><table><thead><tr><th>No.</th><th>Customer</th><th>Warehouse</th><th>Date</th><th>Status</th><th></th></tr></thead><tbody><tr v-for="d in challans" :key="d.id"><td>{{ d.challan_number }}</td><td>{{ d.customer?.customer_name }}</td><td>{{ d.warehouse?.name }}</td><td>{{ String(d.challan_date || '').slice(0, 10) }}</td><td>{{ d.status }}</td><td><button v-if="d.status === 'draft'" @click="dispatchChallan(d)">Reduce Stock</button><button @click="printDoc('Delivery Challan', d.challan_number, d)">Print</button><button v-if="['dispatched','delivered'].includes(d.status) && !d.billed_voucher_id" @click="billChallan(d)">Create Bill</button><span v-else-if="d.billed_voucher_id" class="bill-done">Billed</span></td></tr></tbody></table></div>
            </section>

            <section v-if="tab === 'requisitions'" class="panel">
                <div class="form-grid"><label class="bq-field"><span>Branch</span><select v-model="requisition.branch_id"><option value="">Branch</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select></label><label class="bq-field"><span>Requisition date</span><input v-model="requisition.requisition_date" type="date" /></label><label class="bq-field"><span>Department</span><input v-model="requisition.department" placeholder="Department" /></label><label class="bq-field"><span>Priority</span><select v-model="requisition.priority"><option value="low">Low</option><option value="normal">Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select></label><label class="bq-field"><span>Required date</span><input v-model="requisition.required_date" type="date" /></label><label class="bq-field"><span>Remarks</span><textarea v-model="requisition.remarks" placeholder="Remarks"></textarea></label></div>
                <div v-for="(item, i) in requisition.items" :key="i" class="line-grid"><label class="bq-field"><span>Product</span><select v-model="item.product_id"><option value="">Product</option><option v-for="p in refs.products" :key="p.id" :value="p.id">{{ p.name }}</option></select></label><label class="bq-field"><span>Quantity</span><input placeholder="Qty" title="Qty" v-model.number="item.quantity" type="number" step="0.001" /></label><label class="bq-field"><span>Approved</span><input v-model.number="item.approved_quantity" type="number" step="0.001" placeholder="Approved" /></label><label class="bq-field"><span>Line remarks</span><input v-model="item.remarks" placeholder="Line remarks" /></label><button @click="requisition.items.splice(i,1)" :disabled="requisition.items.length === 1">Remove</button></div>
                <div class="actions"><button @click="addRow(requisition.items, { product_id: '', quantity: 1, approved_quantity: '', remarks: '' })">Add Item</button><button :disabled="saving" @click="saveRequisition('draft')">Save Draft</button><button :disabled="saving" @click="saveRequisition('approved')">Approve</button></div>
                <div class="table-wrapper"><table><tbody><tr v-for="r in requisitions" :key="r.id"><td>{{ r.requisition_number }}</td><td>{{ r.department }}</td><td>{{ r.priority }}</td><td>{{ r.status }}</td></tr></tbody></table></div>
            </section>

            <section v-if="tab === 'purchase-orders'" class="panel">
                <div class="form-grid"><label class="bq-field"><span>Branch</span><select v-model="purchaseOrder.branch_id"><option value="">Branch</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select></label><label class="bq-field"><span>Warehouse</span><select v-model="purchaseOrder.warehouse_id"><option value="">Warehouse</option><option v-for="w in filteredWarehouses(purchaseOrder.branch_id)" :key="w.id" :value="w.id">{{ w.name }}</option></select></label><label class="bq-field"><span>Supplier</span><select v-model="purchaseOrder.supplier_id"><option value="">Supplier</option><option v-for="s in refs.suppliers" :key="s.id" :value="s.id">{{ s.name }}</option></select></label><label class="bq-field"><span>PO date</span><input v-model="purchaseOrder.po_date" type="date" /></label><label class="bq-field"><span>Expected delivery date</span><input v-model="purchaseOrder.expected_delivery_date" type="date" /></label><label class="bq-field"><span>Terms</span><textarea v-model="purchaseOrder.terms_conditions" placeholder="Terms"></textarea></label><label class="bq-field"><span>Remarks</span><textarea v-model="purchaseOrder.remarks" placeholder="Remarks"></textarea></label></div>
                <div v-for="(item, i) in purchaseOrder.items" :key="i" class="line-grid"><label class="bq-field"><span>Product</span><select v-model="item.product_id" @change="productChanged(item, 'purchase_rate')"><option value="">Product</option><option v-for="p in refs.products" :key="p.id" :value="p.id">{{ p.name }} - {{ p.sku }}</option></select></label><label class="bq-field"><span>Quantity</span><input placeholder="Qty" title="Qty" v-model.number="item.ordered_quantity" type="number" step="0.001" /></label><label class="bq-field"><span>Rate</span><input placeholder="Rate" title="Rate" v-model.number="item.purchase_rate" type="number" step="0.01" /></label><label class="bq-field"><span>GST %</span><input placeholder="GST %" title="GST %" v-model.number="item.gst_rate" type="number" step="0.01" /></label><label class="bq-field"><span>Remarks</span><input v-model="item.remarks" placeholder="Remarks" /></label><button @click="purchaseOrder.items.splice(i,1)" :disabled="purchaseOrder.items.length === 1">Remove</button></div>
                <div class="actions"><button @click="addRow(purchaseOrder.items, { product_id: '', ordered_quantity: 1, purchase_rate: 0, gst_rate: 0, remarks: '' })">Add Item</button><button :disabled="saving" @click="savePurchaseOrder('draft')">Save Draft</button><button :disabled="saving" @click="savePurchaseOrder('sent')">Send</button></div>
                <div class="table-wrapper"><table><thead><tr><th>No.</th><th>Supplier</th><th>Total</th><th>Status</th><th>Receipt</th><th></th></tr></thead><tbody><tr v-for="p in purchaseOrders" :key="p.id"><td>{{ p.po_number }}</td><td>{{ p.supplier?.name }}</td><td>Rs. {{ money(p.grand_total) }}</td><td>{{ p.status }}</td><td>{{ p.receipt_status }}</td><td><button @click="confirmPurchaseOrder(p)">Confirm</button><button @click="printDoc('Purchase Order', p.po_number, p)">Print</button></td></tr></tbody></table></div>
            </section>

            <section v-if="tab === 'grn'" class="panel">
                <div class="form-grid"><label class="bq-field"><span>Branch</span><select v-model="receipt.branch_id"><option value="">Branch</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select></label><label class="bq-field"><span>Warehouse</span><select v-model="receipt.warehouse_id"><option value="">Warehouse</option><option v-for="w in filteredWarehouses(receipt.branch_id)" :key="w.id" :value="w.id">{{ w.name }}</option></select></label><label class="bq-field"><span>Supplier</span><select v-model="receipt.supplier_id"><option value="">Supplier</option><option v-for="s in refs.suppliers" :key="s.id" :value="s.id">{{ s.name }}</option></select></label><label class="bq-field"><span>Receipt date</span><input v-model="receipt.receipt_date" type="date" /></label><label class="bq-field"><span>Supplier challan</span><input v-model="receipt.supplier_challan_number" placeholder="Supplier challan" /></label><label class="bq-field"><span>Quality check</span><select v-model="receipt.qc_status"><option value="pending">Pending</option><option value="passed">Passed</option><option value="failed">Failed</option><option value="partial">Partial</option></select></label><label class="bq-field"><span>Remarks</span><textarea v-model="receipt.remarks" placeholder="Remarks"></textarea></label></div>
                <div v-for="(item, i) in receipt.items" :key="i" class="line-grid"><label class="bq-field"><span>Product</span><select v-model="item.product_id"><option value="">Product</option><option v-for="p in refs.products" :key="p.id" :value="p.id">{{ p.name }}</option></select></label><label class="bq-field"><span>Received Qty</span><input placeholder="Received Qty" title="Received Qty" v-model.number="item.received_quantity" type="number" step="0.001" /></label><label class="bq-field"><span>Rejected quantity</span><input v-model.number="item.rejected_quantity" type="number" step="0.001" /></label><label class="bq-field"><span>Damaged quantity</span><input v-model.number="item.damaged_quantity" type="number" step="0.001" /></label><label class="bq-field"><span>Unit cost</span><input v-model.number="item.unit_cost" type="number" step="0.01" /></label><label class="bq-field"><span>Location</span><input v-model="item.warehouse_location" placeholder="Location" /></label><button @click="receipt.items.splice(i,1)" :disabled="receipt.items.length === 1">Remove</button></div>
                <div class="actions"><button @click="addRow(receipt.items, { product_id: '', ordered_quantity: 0, received_quantity: 1, rejected_quantity: 0, damaged_quantity: 0, unit_cost: 0, qc_status: 'pending', warehouse_location: '' })">Add Item</button><button :disabled="saving" @click="saveReceipt('draft')">Save Draft</button><button :disabled="saving" @click="saveReceipt('received')">Receive</button></div>
                <div class="table-wrapper"><table><thead><tr><th>No.</th><th>Supplier</th><th>Warehouse</th><th>Date</th><th>Status</th><th></th></tr></thead><tbody><tr v-for="g in receipts" :key="g.id"><td>{{ g.grn_number }}</td><td>{{ g.supplier?.name }}</td><td>{{ g.warehouse?.name }}</td><td>{{ g.receipt_date }}</td><td>{{ g.status }}</td><td><button @click="receiveGoods(g)">Receive</button><button @click="printDoc('Goods Receipt Note', g.grn_number, g)">Print</button></td></tr></tbody></table></div>
            </section>

            <section v-if="tab === 'reports'" class="panel">
                <div class="actions"><button @click="exportCsv(reports.quotation_report, 'quotation-report.csv')">Export Quotations</button><button @click="exportCsv(reports.sales_order_report, 'sales-order-report.csv')">Export Sales Orders</button><button @click="exportCsv(reports.purchase_order_report, 'purchase-order-report.csv')">Export Purchase Orders</button><button @click="exportCsv(reports.back_order_report, 'back-order-report.csv')">Export Back Orders</button></div>
                <div class="cards"><div><span>Quotation Conversion</span><strong>{{ reports.quotation_conversion_percent || 0 }}%</strong></div><div><span>Order Fulfillment</span><strong>{{ reports.order_fulfillment_percent || 0 }}%</strong></div></div>
                <div class="table-wrapper"><table><thead><tr><th>Back Order Source</th><th>Product</th><th>Pending</th><th>Status</th></tr></thead><tbody><tr v-for="b in reports.back_order_report" :key="b.id"><td>{{ b.source_type }}</td><td>{{ nameOf(refs.products, b.product_id) }}</td><td>{{ qty(b.pending_quantity) }}</td><td>{{ b.status }}</td></tr></tbody></table></div>
            </section>
        </div>
    </Layout>
</template>

<style scoped>
.bill-done{padding:4px 10px;color:#15803d;background:#e7f8ef;border-radius:999px;font-size:11px;font-weight:800}
.req{color:#dc2626;font-weight:900}.dc-help{margin:0 0 14px;padding:10px 12px;color:#1d4ed8;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;font-size:12px}.dc-top{display:grid;grid-template-columns:2fr 1fr 1fr 1.4fr;gap:12px;margin-bottom:14px}.dc-field{display:grid;gap:6px;align-content:start}.dc-field>span,.dc-head span{color:#344159;font-size:12px;font-weight:800}.dc-search :deep(.search-select-label){display:none}.dc-line{display:grid;grid-template-columns:minmax(0,1fr) 140px 90px;gap:10px;align-items:center;margin-bottom:8px}.dc-add{margin-bottom:10px}.dc-more{margin:8px 0}.dc-more summary{cursor:pointer;color:#2457d6;font-size:12px;font-weight:800;margin-bottom:8px}.dc-note{margin:8px 0;padding:10px;color:#166534;background:#f0fdf4;border-radius:8px;font-size:12px}.dc-primary{color:#fff!important;background:#2457d6!important;border-color:#2457d6!important}@media(max-width:900px){.dc-top{grid-template-columns:1fr}.dc-line{grid-template-columns:minmax(0,1fr) 80px 80px}}
.orders-page{padding:4px 0 28px}.page-heading,.tabs,.actions{display:flex;align-items:center;gap:12px}.page-heading{justify-content:space-between;margin-bottom:18px}.page-heading span{color:#2457d6;font-size:10px;font-weight:800;letter-spacing:1.2px}.page-heading h1{margin:0;color:#142139}.page-heading p{margin:6px 0 0;color:#758197;font-size:13px}.tabs button{text-transform:capitalize}.tabs{flex-wrap:wrap;margin-bottom:14px}.tabs button.active{background:#173b77;color:#fff;border-color:#173b77}.panel{margin-bottom:18px;padding:18px;background:#fff;border:1px solid #dfe6ef;border-radius:8px}.cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.cards div{padding:14px;border:1px solid #edf1f5;border-radius:8px}.cards span{display:block;color:#69758a;font-size:11px}.cards strong{display:block;margin-top:6px;color:#142139;font-size:18px}.form-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:14px}.line-grid{display:grid;grid-template-columns:minmax(0,2fr) repeat(4,minmax(0,.8fr)) auto;gap:8px;align-items:center;margin-bottom:8px}.actions{justify-content:flex-end;flex-wrap:wrap;margin:12px 0}input,select,textarea,button{min-height:38px;padding:8px 10px;color:#344159;background:#fff;border:1px solid #d8e0eb;border-radius:8px;font-size:12px}textarea{min-height:38px}button{font-weight:750;cursor:pointer}.alert{padding:10px 12px;margin-bottom:12px;border-radius:8px;background:#fff4f4;color:#b42318;border:1px solid #ffd5d5;font-size:12px}.table-wrapper{overflow-x:auto}table{width:100%;border-collapse:collapse;margin-top:12px}th,td{padding:11px 10px;border-bottom:1px solid #edf1f5;text-align:left;white-space:nowrap;font-size:12px}th{color:#69758a;background:#f8fafc;font-size:10px;text-transform:uppercase}@media(max-width:1000px){.cards,.form-grid,.line-grid{grid-template-columns:1fr}.page-heading{align-items:stretch;flex-direction:column}}
/* Long dropdown texts must not stretch the page on a phone. */
.form-grid>*{min-width:0}.form-grid select,.form-grid input,.form-grid textarea{max-width:100%;min-width:0}
@media(max-width:700px){.form-grid{grid-template-columns:minmax(0,1fr)!important}}
.line-grid>*{min-width:0;max-width:100%}
@media(max-width:700px){.line-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important}.line-grid select{grid-column:1/-1}}
</style>
