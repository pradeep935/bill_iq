<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import Layout from '../Layout.vue';
import InventoryApi from './InventoryApi';

const props = defineProps({ page: { type: String, default: 'inventory' }, title: { type: String, default: 'Inventory Control' }, initial_tab: { type: String, default: 'dashboard' } });

const tab = ref(props.initial_tab);
const saving = ref(false);
const loading = ref(false);
const errors = ref({});
const refs = ref({ branches: [], warehouses: [], reasons: [], statuses: [], products: [] });
const dashboard = ref({});
const reports = ref({ ledger: [], transfer_report: [], variance_report: [], batch_report: [] });
const adjustments = ref([]);
const counts = ref([]);
const transfers = ref([]);
const movements = ref([]);
const reasons = ref([]);
const tabs = ['dashboard', 'adjustments', 'counts', 'transfers', 'locations', 'reasons', 'reports'];

const today = new Date().toISOString().slice(0, 10);
const adjustment = reactive({ branch_id: '', warehouse_id: '', adjustment_date: today, adjustment_reason_id: '', adjustment_type: 'mixed', source: 'manual', status: 'draft', remarks: '', items: [{ product_id: '', unit_id: '', adjustment_quantity: 1, direction: 'in', unit_cost: 0, warehouse_location: '', condition_status: 'saleable', reason: '' }] });
const count = reactive({ branch_id: '', warehouse_id: '', count_date: today, count_type: 'full', freeze_stock: false, status: 'draft', remarks: '', items: [{ product_id: '', counted_quantity: 0, unit_cost: 0, warehouse_location: '', review_status: 'accepted' }] });
const transfer = reactive({ transfer_date: today, source_branch_id: '', source_warehouse_id: '', destination_branch_id: '', destination_warehouse_id: '', transfer_type: 'immediate', expected_delivery_date: '', status: 'draft', remarks: '', items: [{ product_id: '', requested_quantity: 1, approved_quantity: '', unit_cost: 0, source_location: '', destination_location: '' }] });
const location = reactive({ branch_id: '', warehouse_id: '', movement_date: today, status: 'draft', remarks: '', items: [{ product_id: '', quantity: 1, from_location: '', to_location: '' }] });
const reason = reactive({ id: null, reason_code: '', reason_name: '', default_direction: 'out', default_condition_status: 'saleable', accounting_account_id: '', approval_required: true, status: 'active' });

const filteredWarehouses = (branchId) => !branchId ? refs.value.warehouses : refs.value.warehouses.filter((w) => Number(w.branch_id || 0) === Number(branchId));
const money = (v) => Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const qty = (v) => Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
const capture = (e) => { errors.value = e?.response?.data?.errors || { form: [e?.response?.data?.message || 'Unable to save.'] }; };
const clearErrors = () => { errors.value = {}; };
const selectedReason = computed(() => refs.value.reasons.find((r) => Number(r.id) === Number(adjustment.adjustment_reason_id)));
const applyReason = () => { if (selectedReason.value) adjustment.items.forEach((i) => { i.direction = selectedReason.value.default_direction; i.condition_status = selectedReason.value.default_condition_status || 'saleable'; }); };

const load = async () => {
    loading.value = true;
    try {
        refs.value = await InventoryApi.controlReferences();
        dashboard.value = await InventoryApi.inventoryDashboard();
        adjustments.value = (await InventoryApi.stockAdjustments()).adjustments || [];
        counts.value = (await InventoryApi.stockCounts()).sessions || [];
        transfers.value = (await InventoryApi.stockTransfers()).transfers || [];
        movements.value = (await InventoryApi.locationTransfers()).movements || [];
        reasons.value = (await InventoryApi.adjustmentReasons()).reasons || [];
        reports.value = await InventoryApi.inventoryReports();
    } finally {
        loading.value = false;
    }
};

const addRow = (list, row) => list.push({ ...row });
const saveAdjustment = async (status) => { saving.value = true; clearErrors(); try { adjustment.status = status; await InventoryApi.saveStockAdjustment({ ...adjustment, items: adjustment.items.map((i) => ({ ...i })) }); await load(); } catch (e) { capture(e); } finally { saving.value = false; } };
const postAdjustment = async (row) => { saving.value = true; try { await InventoryApi.postStockAdjustment(row.id); await load(); } finally { saving.value = false; } };
const saveCount = async (status) => { saving.value = true; clearErrors(); try { count.status = status; await InventoryApi.saveStockCount({ ...count, items: count.items.map((i) => ({ ...i })) }); await load(); } catch (e) { capture(e); } finally { saving.value = false; } };
const postVariance = async (row) => { saving.value = true; try { await InventoryApi.postCountVariance(row.id); await load(); } catch (e) { capture(e); } finally { saving.value = false; } };
const saveTransfer = async (status) => { saving.value = true; clearErrors(); try { transfer.status = status; await InventoryApi.saveStockTransfer({ ...transfer, items: transfer.items.map((i) => ({ ...i, approved_quantity: i.approved_quantity || i.requested_quantity })) }); await load(); } catch (e) { capture(e); } finally { saving.value = false; } };
const dispatchTransfer = async (row) => { saving.value = true; try { await InventoryApi.dispatchStockTransfer(row.id); await load(); } finally { saving.value = false; } };
const receiveTransfer = async (row) => { saving.value = true; try { await InventoryApi.receiveStockTransfer(row.id, { items: row.items?.map((i) => ({ id: i.id, received_quantity: i.dispatched_quantity || i.approved_quantity || i.requested_quantity, rejected_quantity: 0 })) || [] }); await load(); } finally { saving.value = false; } };
const saveLocation = async (status) => { saving.value = true; clearErrors(); try { location.status = status; await InventoryApi.saveLocationTransfer({ ...location, items: location.items.map((i) => ({ ...i })) }); await load(); } catch (e) { capture(e); } finally { saving.value = false; } };
const saveReason = async () => { saving.value = true; clearErrors(); try { await InventoryApi.saveAdjustmentReason({ ...reason }, reason.id); await load(); } catch (e) { capture(e); } finally { saving.value = false; } };
onMounted(load);
</script>

<template>
    <Layout :page="page" :title="title">
        <div class="inventory-control">
            <div class="page-heading"><div><span>INVENTORY</span><h1>Stock Operations</h1><p>Adjustment, count, transfer, location movement and valuation from immutable stock ledgers.</p></div><button :disabled="loading" @click="load">Refresh</button></div>
            <div class="tabs"><button v-for="t in tabs" :key="t" :class="{active: tab === t}" @click="tab = t">{{ t }}</button></div>
            <div v-if="errors.form" class="alert">{{ errors.form[0] }}</div>

            <section v-if="tab === 'dashboard'" class="panel cards">
                <div><span>Stock Value</span><strong>Rs. {{ money(dashboard.total_stock_value) }}</strong></div><div><span>Saleable Qty</span><strong>{{ qty(dashboard.total_saleable_quantity) }}</strong></div><div><span>Low Stock</span><strong>{{ dashboard.low_stock_items || 0 }}</strong></div><div><span>Out of Stock</span><strong>{{ dashboard.out_of_stock_items || 0 }}</strong></div><div><span>Near Expiry</span><strong>{{ dashboard.near_expiry_items || 0 }}</strong></div><div><span>Expired</span><strong>{{ dashboard.expired_items || 0 }}</strong></div><div><span>In Transit</span><strong>{{ qty(dashboard.stock_in_transit) }}</strong></div><div><span>Pending Counts</span><strong>{{ dashboard.pending_stock_counts || 0 }}</strong></div>
            </section>

            <section v-if="tab === 'adjustments'" class="panel">
                <div class="form-grid"><select v-model="adjustment.branch_id"><option value="">Branch</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select><select v-model="adjustment.warehouse_id"><option value="">Warehouse</option><option v-for="w in filteredWarehouses(adjustment.branch_id)" :key="w.id" :value="w.id">{{ w.name }}</option></select><input v-model="adjustment.adjustment_date" type="date" /><select v-model="adjustment.adjustment_reason_id" @change="applyReason"><option value="">Reason</option><option v-for="r in refs.reasons" :key="r.id" :value="r.id">{{ r.reason_name }}</option></select><select v-model="adjustment.source"><option>manual</option><option>physical_count</option><option>damage</option><option>expiry</option><option>loss</option><option>theft</option><option>quality_rejection</option><option>opening_correction</option><option>system_correction</option></select><textarea v-model="adjustment.remarks" placeholder="Remarks"></textarea></div>
                <div v-for="(item, i) in adjustment.items" :key="i" class="line-grid adjustment-row"><select v-model="item.product_id"><option value="">Product</option><option v-for="p in refs.products" :key="p.id" :value="p.id">{{ p.name }} - {{ p.sku }}</option></select><select v-model="item.direction"><option>in</option><option>out</option></select><input v-model.number="item.adjustment_quantity" type="number" step="0.001" /><input v-model.number="item.unit_cost" type="number" step="0.01" /><input v-model="item.warehouse_location" placeholder="Location" /><select v-model="item.condition_status"><option>saleable</option><option>damaged</option><option>expired</option><option>defective</option><option>quarantined</option><option>lost</option></select><button @click="adjustment.items.splice(i,1)" :disabled="adjustment.items.length === 1">Remove</button></div>
                <div class="actions"><button @click="addRow(adjustment.items, { product_id: '', unit_id: '', adjustment_quantity: 1, direction: 'in', unit_cost: 0, warehouse_location: '', condition_status: 'saleable', reason: '' })">Add Item</button><button :disabled="saving" @click="saveAdjustment('draft')">Save Draft</button><button :disabled="saving" @click="saveAdjustment('posted')">Post</button></div>
                <div class="table-wrapper"><table><thead><tr><th>No.</th><th>Date</th><th>Warehouse</th><th>In</th><th>Out</th><th>Status</th><th></th></tr></thead><tbody><tr v-for="a in adjustments" :key="a.id"><td>{{ a.voucher_number }}</td><td>{{ a.adjustment_date }}</td><td>{{ a.warehouse?.name }}</td><td>{{ qty(a.total_quantity_in) }}</td><td>{{ qty(a.total_quantity_out) }}</td><td>{{ a.status }}</td><td><button v-if="['draft','submitted','approved'].includes(a.status)" @click="postAdjustment(a)">Post</button></td></tr></tbody></table></div>
            </section>

            <section v-if="tab === 'counts'" class="panel">
                <div class="form-grid"><select v-model="count.branch_id"><option value="">Branch</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select><select v-model="count.warehouse_id"><option value="">Warehouse</option><option v-for="w in filteredWarehouses(count.branch_id)" :key="w.id" :value="w.id">{{ w.name }}</option></select><input v-model="count.count_date" type="date" /><select v-model="count.count_type"><option>full</option><option>cycle_count</option><option>category</option><option>brand</option><option>location</option><option>selected_products</option></select><label><input v-model="count.freeze_stock" type="checkbox" /> Freeze</label><textarea v-model="count.remarks" placeholder="Remarks"></textarea></div>
                <div v-for="(item, i) in count.items" :key="i" class="line-grid count-row"><select v-model="item.product_id"><option value="">Product</option><option v-for="p in refs.products" :key="p.id" :value="p.id">{{ p.name }}</option></select><input v-model.number="item.counted_quantity" type="number" step="0.001" placeholder="Counted" /><input v-model.number="item.unit_cost" type="number" step="0.01" placeholder="Cost" /><input v-model="item.warehouse_location" placeholder="Location" /><select v-model="item.review_status"><option>pending</option><option>accepted</option><option>rejected</option><option>recount_required</option></select><button @click="count.items.splice(i,1)" :disabled="count.items.length === 1">Remove</button></div>
                <div class="actions"><button @click="addRow(count.items, { product_id: '', counted_quantity: 0, unit_cost: 0, warehouse_location: '', review_status: 'accepted' })">Add Line</button><button :disabled="saving" @click="saveCount('draft')">Save</button><button :disabled="saving" @click="saveCount('approved')">Approve</button></div>
                <div class="table-wrapper"><table><tbody><tr v-for="c in counts" :key="c.id"><td>{{ c.session_number }}</td><td>{{ c.count_date }}</td><td>{{ c.warehouse?.name }}</td><td>{{ c.status }}</td><td><button @click="postVariance(c)">Post Variance</button></td></tr></tbody></table></div>
            </section>

            <section v-if="tab === 'transfers'" class="panel">
                <div class="form-grid"><select v-model="transfer.source_branch_id"><option value="">Source Branch</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select><select v-model="transfer.source_warehouse_id"><option value="">Source Warehouse</option><option v-for="w in filteredWarehouses(transfer.source_branch_id)" :key="w.id" :value="w.id">{{ w.name }}</option></select><select v-model="transfer.destination_branch_id"><option value="">Destination Branch</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select><select v-model="transfer.destination_warehouse_id"><option value="">Destination Warehouse</option><option v-for="w in filteredWarehouses(transfer.destination_branch_id)" :key="w.id" :value="w.id">{{ w.name }}</option></select><input v-model="transfer.transfer_date" type="date" /><select v-model="transfer.transfer_type"><option>immediate</option><option>dispatch_receive</option><option>inter_branch</option><option>inter_warehouse</option></select></div>
                <div v-for="(item, i) in transfer.items" :key="i" class="line-grid transfer-row"><select v-model="item.product_id"><option value="">Product</option><option v-for="p in refs.products" :key="p.id" :value="p.id">{{ p.name }}</option></select><input v-model.number="item.requested_quantity" type="number" step="0.001" /><input v-model.number="item.approved_quantity" type="number" step="0.001" placeholder="Approved" /><input v-model.number="item.unit_cost" type="number" step="0.01" /><input v-model="item.source_location" placeholder="From location" /><input v-model="item.destination_location" placeholder="To location" /><button @click="transfer.items.splice(i,1)" :disabled="transfer.items.length === 1">Remove</button></div>
                <div class="actions"><button @click="addRow(transfer.items, { product_id: '', requested_quantity: 1, approved_quantity: '', unit_cost: 0, source_location: '', destination_location: '' })">Add Item</button><button :disabled="saving" @click="saveTransfer('draft')">Save Draft</button><button :disabled="saving" @click="saveTransfer(transfer.transfer_type === 'immediate' ? 'approved' : 'dispatched')">Post</button></div>
                <div class="table-wrapper"><table><thead><tr><th>No.</th><th>Source</th><th>Destination</th><th>Type</th><th>Status</th><th></th></tr></thead><tbody><tr v-for="t in transfers" :key="t.id"><td>{{ t.voucher_number }}</td><td>{{ t.source_warehouse?.name }}</td><td>{{ t.destination_warehouse?.name }}</td><td>{{ t.transfer_type }}</td><td>{{ t.status }}</td><td><button v-if="['approved','draft','submitted'].includes(t.status)" @click="dispatchTransfer(t)">Dispatch</button><button v-if="['dispatched','partially_received'].includes(t.status)" @click="receiveTransfer(t)">Receive</button></td></tr></tbody></table></div>
            </section>

            <section v-if="tab === 'locations'" class="panel"><div class="form-grid"><select v-model="location.branch_id"><option value="">Branch</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select><select v-model="location.warehouse_id"><option value="">Warehouse</option><option v-for="w in filteredWarehouses(location.branch_id)" :key="w.id" :value="w.id">{{ w.name }}</option></select><input v-model="location.movement_date" type="date" /><textarea v-model="location.remarks" placeholder="Remarks"></textarea></div><div v-for="(item, i) in location.items" :key="i" class="line-grid location-row"><select v-model="item.product_id"><option value="">Product</option><option v-for="p in refs.products" :key="p.id" :value="p.id">{{ p.name }}</option></select><input v-model.number="item.quantity" type="number" step="0.001" /><input v-model="item.from_location" placeholder="From" /><input v-model="item.to_location" placeholder="To" /><button @click="location.items.splice(i,1)" :disabled="location.items.length === 1">Remove</button></div><div class="actions"><button @click="addRow(location.items, { product_id: '', quantity: 1, from_location: '', to_location: '' })">Add Line</button><button :disabled="saving" @click="saveLocation('posted')">Post Movement</button></div><div class="table-wrapper"><table><tbody><tr v-for="m in movements" :key="m.id"><td>{{ m.voucher_number }}</td><td>{{ m.warehouse?.name }}</td><td>{{ m.movement_date }}</td><td>{{ m.status }}</td></tr></tbody></table></div></section>

            <section v-if="tab === 'reasons'" class="panel"><div class="form-grid"><input v-model="reason.reason_code" placeholder="Code" /><input v-model="reason.reason_name" placeholder="Name" /><select v-model="reason.default_direction"><option>in</option><option>out</option></select><select v-model="reason.default_condition_status"><option>saleable</option><option>damaged</option><option>expired</option><option>defective</option><option>quarantined</option><option>lost</option></select><select v-model="reason.status"><option>active</option><option>inactive</option></select><button :disabled="saving" @click="saveReason">Save Reason</button></div><div class="table-wrapper"><table><tbody><tr v-for="r in reasons" :key="r.id"><td>{{ r.reason_code }}</td><td>{{ r.reason_name }}</td><td>{{ r.default_direction }}</td><td>{{ r.default_condition_status }}</td><td>{{ r.status }}</td></tr></tbody></table></div></section>

            <section v-if="tab === 'reports'" class="panel"><h3>Latest Stock Ledger</h3><div class="table-wrapper"><table><thead><tr><th>Date</th><th>Type</th><th>Product</th><th>Warehouse</th><th>In</th><th>Out</th><th>Cost</th></tr></thead><tbody><tr v-for="l in reports.ledger" :key="l.id"><td>{{ l.transaction_date }}</td><td>{{ l.transaction_type }}</td><td>{{ l.product?.name }}</td><td>{{ l.warehouse?.name }}</td><td>{{ qty(l.quantity_in) }}</td><td>{{ qty(l.quantity_out) }}</td><td>Rs. {{ money(l.unit_cost) }}</td></tr></tbody></table></div></section>
        </div>
    </Layout>
</template>

<style scoped>
.inventory-control{padding:4px 0 28px}.page-heading,.tabs,.actions{display:flex;align-items:center;gap:12px}.page-heading{justify-content:space-between;margin-bottom:18px}.page-heading span{color:#2457d6;font-size:10px;font-weight:800;letter-spacing:1.2px}.page-heading h1{margin:0;color:#142139}.page-heading p{margin:6px 0 0;color:#758197;font-size:13px}.tabs{flex-wrap:wrap;margin-bottom:14px}.tabs button.active{background:#173b77;color:#fff;border-color:#173b77}.panel{margin-bottom:18px;padding:18px;background:#fff;border:1px solid #dfe6ef;border-radius:8px}.cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.cards div{padding:14px;border:1px solid #edf1f5;border-radius:8px}.cards span{display:block;color:#69758a;font-size:11px}.cards strong{display:block;margin-top:6px;color:#142139;font-size:18px}.form-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:14px}.line-grid{display:grid;gap:8px;align-items:center;margin-bottom:8px}.adjustment-row{grid-template-columns:1.6fr .6fr .8fr .8fr 1fr 1fr .7fr}.count-row{grid-template-columns:1.8fr .9fr .8fr 1fr 1fr .7fr}.transfer-row{grid-template-columns:1.5fr .8fr .8fr .8fr 1fr 1fr .7fr}.location-row{grid-template-columns:1.8fr .8fr 1fr 1fr .7fr}.actions{justify-content:flex-end;flex-wrap:wrap;margin:12px 0}input,select,textarea,button{min-height:38px;padding:8px 10px;color:#344159;background:#fff;border:1px solid #d8e0eb;border-radius:8px;font-size:12px}textarea{min-height:38px}button{font-weight:750;cursor:pointer}.alert{padding:10px 12px;margin-bottom:12px;border-radius:8px;background:#fff4f4;color:#b42318;border:1px solid #ffd5d5;font-size:12px}.table-wrapper{overflow-x:auto}table{width:100%;border-collapse:collapse;margin-top:12px}th,td{padding:11px 10px;border-bottom:1px solid #edf1f5;text-align:left;white-space:nowrap;font-size:12px}th{color:#69758a;background:#f8fafc;font-size:10px;text-transform:uppercase}@media(max-width:1000px){.cards,.form-grid,.line-grid,.adjustment-row,.count-row,.transfer-row,.location-row{grid-template-columns:1fr}.page-heading{align-items:stretch;flex-direction:column}}
</style>
