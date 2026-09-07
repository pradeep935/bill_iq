<script setup>
import { computed, onMounted, onBeforeUnmount, ref, watch } from 'vue';
import axios from 'axios';
import Layout from '../Layout.vue';
import InventoryApi from './InventoryApi';
import AppToast from '../../Components/Common/AppToast.vue';
import SearchSelect from '../../Components/Common/SearchSelect.vue';
import TableLoadingState from '../../Components/Common/TableLoadingState.vue';
import RowActionMenu from '../../Components/Common/RowActionMenu.vue';
import InventoryModal from './Shared/InventoryModal.vue';
import { formatInventoryQty, formatInventoryDateTime } from './Shared/formatters';

defineProps({ page: { type: String, default: 'inventory-batches' }, title: { type: String, default: 'Batch & Expiry' } });
const tabs = ['Overview', 'Batches', 'Batch Movements', 'Expiry', 'Quarantine', 'Reports'];
const tab = ref('Overview');
const report = ref('batch_stock');
const reports = { batch_stock:'Batch Stock Report', expiry_report:'Expiry Report', expire_today_report:'Expire Today', near_expiry_report:'Near Expiry Report', expired_report:'Expired Report', blocked_report:'Blocked Report', quarantine_report:'Quarantine Report', fefo_priority:'FEFO Priority', batch_movement:'Batch Movement', batch_valuation:'Batch Valuation' };
const operationNames = { opening:'Opening Batch Stock', stock_in:'Batch Stock In', adjust:'Batch Adjustment', transfer:'Batch Transfer', reclassify:'Reclassify Stock', quarantine:'Quarantine Batch', release_quarantine:'Release Quarantine', block:'Block Batch', unblock:'Unblock Batch', writeoff:'Write-off Batch' };
const refs = ref({ products:[], branches:[], warehouses:[], permissions:{}, conditions:[], statuses:[] });
const permissions = computed(() => refs.value.permissions || {});
const permissionFor = key => ({ opening:'create', stock_in:'create', view:'view', movements:'view_ledger' }[key] || key);
const allowed = key => !!permissions.value[permissionFor(key)];
const operations = computed(() => Object.entries(operationNames).filter(([key]) => allowed(key)));
const loading = ref(false), saving = ref(false), exporting = ref(false), detailLoading = ref(false);
const rows = ref([]), dashboard = ref({}), toast = ref(null), details = ref(null), menu = ref(null);
const page = ref({ current_page:1,last_page:1,total:0,from:0,to:0 });
const emptyFilters = () => ({ search:'',product_id:'',batch_id:'',branch_id:'',warehouse_id:'',batch_status:'',expiry_filter:'',date_from:'',date_to:'',movement_type:'',per_page:15 });
const filters = ref(emptyFilters());
const showFilters = ref(false), modal = ref(false), form = ref({}), errors = ref({}), selectedRow = ref(null), batchOptions = ref([]), batchSearch = ref('');
const qty = formatInventoryQty, date = formatInventoryDateTime;
const money = n => Number(n || 0).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2});
const label = s => ({empty:'Depleted',expire_today:'Expire Today',quarantined:'Quarantined'}[s] || String(s || '—').replaceAll('_',' ').replace(/\b\w/g,c=>c.toUpperCase()));
const notify = (message,type='success') => { toast.value = { message,type }; };
const fail = e => notify(e?.response?.data?.message || e.message || 'Unable to complete this action.','error');
const base = '/app/inventory/batches';
const localLink = path => `${String(axios.defaults.baseURL || '').replace(/\/$/,'')}${path}`;
const warehouses = branch => refs.value.warehouses.filter(w => !branch || Number(w.branch_id) === Number(branch));
const key = row => `${row.id}-${row.branch_id}-${row.warehouse_id}-${row.product_variant_id}-${row.condition_status}`;
const isMovement = computed(() => tab.value === 'Batch Movements' || (tab.value === 'Reports' && report.value === 'batch_movement'));
const requestFilters = computed(() => ({ ...filters.value, ...(tab.value === 'Quarantine' ? { batch_status:'quarantined' } : {}), ...(tab.value === 'Expiry' && !filters.value.expiry_filter ? {expiry_filter:'near'} : {}), report:isMovement.value ? 'batch_movement' : (tab.value === 'Reports' ? report.value : '') }));
let request = 0, timer, batchTimer;
async function load(number=1) {
    const token = ++request;
    loading.value = true;
    try {
        const response = await axios.get(`${base}/${isMovement.value ? 'movements' : 'list'}`, {params:{...requestFilters.value,page:number}});
        if (token !== request) return;
        if (isMovement.value) { rows.value=response.data.data; page.value=response.data; }
        else { rows.value=response.data.items; page.value=response.data.pagination; dashboard.value=response.data.dashboard; }
    } catch(e) { if(token===request) fail(e); }
    finally { if(token===request) loading.value=false; }
}
const cards = computed(() => [
    ['Active Batches',dashboard.value.active_batches,'active','good'], ['Near Expiry',dashboard.value.near_expiry,'near_expiry','warn'],
    ['Expired',dashboard.value.expired,'expired','bad'], ['Total Batch Quantity',qty(dashboard.value.total_batch_quantity),'','info'],
    ['Total Batch Value',`₹ ${money(dashboard.value.total_batch_value)}`,'','info'], ['Blocked',dashboard.value.blocked_batches,'blocked','bad'],
    ['Quarantine',dashboard.value.quarantined_batches,'quarantined','warn'],
]);
const alerts = computed(() => [['Expire Today',dashboard.value.expire_today,'today'],['Expire in 7 Days',dashboard.value.expire_7_days,'7'],['Expire in 30 Days',dashboard.value.expire_30_days,'30'],['Expired',dashboard.value.expired,'expired']]);
function filterStatus(status) { filters.value=emptyFilters(); filters.value.batch_status=status; tab.value=status==='quarantined'?'Quarantine':'Batches'; }
function filterExpiry(expiry) { filters.value=emptyFilters(); filters.value.expiry_filter=expiry; tab.value='Expiry'; }
function movements(row) { filters.value={...emptyFilters(),batch_id:row.id,product_id:row.product_id,branch_id:row.branch_id,warehouse_id:row.warehouse_id}; tab.value='Batch Movements'; menu.value=null; }
async function view(row) {
    menu.value=null; detailLoading.value=true;
    try { details.value=(await axios.get(`${base}/${row.id}`,{params:{branch_id:row.branch_id,warehouse_id:row.warehouse_id}})).data; }
    catch(e) { fail(e); } finally { detailLoading.value=false; }
}
function openOperation(operation='opening',row=null) {
    menu.value=null; selectedRow.value=row; errors.value={}; batchOptions.value=row?[row]:[];
    form.value={operation,operation_token:crypto.randomUUID(),batch_id:row?.id || '',product_id:row?.product_id || '',product_variant_id:row?.product_variant_id || null,batch_number:'',manufacturing_date:'',expiry_date:'',document_date:new Date().toLocaleDateString('en-CA'),branch_id:row?.branch_id || '',warehouse_id:row?.warehouse_id || '',quantity:'',unit_cost:row?.average_cost || 0,condition_status:row?.condition_status || 'saleable',to_condition:operation==='quarantine'?'quarantined':'saleable',direction:'out',destination_branch_id:'',destination_warehouse_id:'',reason:'',confirmed:false};
    modal.value=true;
    if (!row && !['opening','stock_in'].includes(operation)) searchBatches();
}
const receipt = computed(() => ['opening','stock_in'].includes(form.value.operation));
const statusOnly = computed(() => ['block','unblock'].includes(form.value.operation));
const reclassifying = computed(() => ['reclassify','release_quarantine'].includes(form.value.operation));
const operationBatchOptions = computed(() => batchOptions.value.filter(r => (r.actions || []).includes(form.value.operation)).map(r => ({...r,option_key:key(r),name:`${r.batch_number} · ${r.product_name} · ${r.branch_name} / ${r.warehouse_name} · ${label(r.condition_status)} · ${qty(r.quantity_on_hand)}`})));
const batchChoice = ref('');
async function searchBatches() {
    try { batchOptions.value=(await axios.get(`${base}/list`,{params:{search:batchSearch.value,per_page:100}})).data.items; } catch(e) { fail(e); }
}
function chooseBatch(value) {
    const row=operationBatchOptions.value.find(r=>r.option_key===value); if(!row) return;
    selectedRow.value=row;
    Object.assign(form.value,{batch_id:row.id,product_id:row.product_id,product_variant_id:row.product_variant_id,branch_id:row.branch_id,warehouse_id:row.warehouse_id,condition_status:row.condition_status,unit_cost:row.average_cost});
}
function closeModal() { if(!saving.value) {modal.value=false;batchChoice.value='';batchSearch.value='';} }
async function submit() {
    if(!form.value.confirmed) {errors.value={confirmation:['Confirm the operation before posting.']};return;}
    saving.value=true; errors.value={};
    try {
        const payload={...form.value}; delete payload.confirmed;
        Object.keys(payload).forEach(k=>{if(payload[k]==='')payload[k]=null;});
        const response=await axios.post(`${base}/operations`,payload);
        notify(response.data.document_number ? `Posted ${response.data.document_number}.` : 'Batch status updated.');
        modal.value=false; selectedRow.value=null; await load();
    } catch(e) { errors.value=e?.response?.data?.errors || {operation:[e?.response?.data?.message || 'Posting failed.']}; }
    finally {saving.value=false;}
}
async function exportReport(format) {
    exporting.value=true;
    try {
        const response=await axios.get(`${base}/export`,{params:{...requestFilters.value,format},responseType:'blob'});
        const url=URL.createObjectURL(response.data), link=document.createElement('a');
        link.href=url; link.download=`batch-${isMovement.value?'movements':report.value}.${format==='excel'?'xlsx':format}`; link.click(); URL.revokeObjectURL(url);
    } catch(e) {fail(e);} finally {exporting.value=false;}
}
watch(filters,()=>{clearTimeout(timer);timer=setTimeout(()=>load(),300);},{deep:true});
watch(tab,()=>{menu.value=null;load();}); watch(report,()=>load());
watch(()=>filters.value.branch_id,()=>{filters.value.warehouse_id='';});
watch(()=>form.value.destination_branch_id,()=>{form.value.destination_warehouse_id='';});
watch(()=>form.value.branch_id,(v)=>{if(receipt.value && !warehouses(v).some(w=>Number(w.id)===Number(form.value.warehouse_id))) form.value.warehouse_id='';});
watch(batchSearch,()=>{clearTimeout(batchTimer);batchTimer=setTimeout(searchBatches,300);});
onBeforeUnmount(()=>{clearTimeout(timer);clearTimeout(batchTimer);request++;});
onMounted(async()=>{try {refs.value=await InventoryApi.batchReferences();await load();}catch(e){fail(e);}});
</script>

<template>
<Layout :page="$props.page" :title="title">

    <div class="batch-page">
    <div class="bill-page-title"><span>INVENTORY CONTROL</span><h1>Batch &amp; Expiry</h1><p>Manage batch-level inventory, expiry, FEFO, quarantine, blocked stock and batch valuation.</p></div>
        <AppToast v-if="toast" show title="Batch & Expiry" :message="toast.message" :type="toast.type" />
        <div class="toolbar"><button v-if="operations.length" class="primary" @click="openOperation(operations[0][0])">+ New Batch Operation</button><button :disabled="loading" @click="load()">Refresh</button><template v-if="permissions.export"><button v-for="f in ['csv','excel','pdf']" :key="f" :disabled="exporting" @click="exportReport(f)">{{ f==='csv'?'CSV':f==='pdf'?'PDF':'Excel' }}</button></template></div>
        <nav class="tabs" aria-label="Batch inventory sections"><button v-for="t in tabs.filter(t=>t!=='Batch Movements'||permissions.view_ledger)" :key="t" :class="{active:tab===t}" @click="tab=t">{{ t }}</button></nav>
        <TableLoadingState v-if="loading && tab==='Overview'" title="Loading batch inventory…" />
        <template v-else-if="tab==='Overview'">
            <div class="summary-grid"><button v-for="[title,value,status,tone] in cards" :key="title" class="summary-card" :class="tone" @click="filterStatus(status)"><span>{{ title }}</span><strong>{{ value ?? 0 }}</strong><small>View batches →</small></button></div>
            <section class="panel"><h2>Expiry alerts</h2><div class="alert-grid"><button v-for="[title,value,expiry] in alerts" :key="title" class="alert-card" @click="filterExpiry(expiry)"><span>{{ title }}</span><strong>{{ value || 0 }}</strong><small>Review stock →</small></button></div></section>
            <section v-if="dashboard.expired || dashboard.quarantined_batches || dashboard.blocked_batches || dashboard.near_expiry" class="panel attention"><h2>Attention Required</h2><button v-if="dashboard.expired" @click="filterExpiry('expired')">{{ dashboard.expired }} expired batches still have physical stock. Review expiry or write-off.</button><button v-if="dashboard.quarantined_batches" @click="filterStatus('quarantined')">{{ dashboard.quarantined_batches }} quarantined batches await inspection and release.</button><button v-if="dashboard.blocked_batches" @click="filterStatus('blocked')">{{ dashboard.blocked_batches }} blocked batches are excluded from sales.</button><button v-if="dashboard.near_expiry" @click="filterExpiry('near')">{{ dashboard.near_expiry }} batches are expiring soon. Review FEFO priority.</button></section>
            <section v-if="!Number(dashboard.total_batch_quantity)" class="panel empty-state"><h2>No batch inventory yet</h2><p>Batch stock will appear when you record opening stock, purchase/inward stock, or another batch stock operation.</p><div><button v-if="allowed('opening')" class="primary" @click="openOperation('opening')">Add Opening Batch</button><a :href="localLink('/app/purchases')" class="button">Create Purchase Voucher</a></div></section>
            <section v-else class="panel"><h2>Where batch stock comes from</h2><p>Record receipts through Opening Stock, Purchase Vouchers or controlled Stock In. Sales and returns update the same batch. Transfers and condition changes retain the lot's identity and history.</p><div class="inline-actions"><a class="button" :href="localLink('/app/inventory/opening-stock')">Opening Stock</a><a class="button" :href="localLink('/app/purchases')">Purchase Vouchers</a><button @click="tab='Batches'">Manage Batches →</button></div></section>
        </template>
        <section v-else class="panel register">
            <div class="section-head"><h2>{{ tab==='Reports' ? reports[report] : tab }}</h2><button class="mobile-filter" @click="showFilters=!showFilters">Filters</button><select v-if="tab==='Reports'" v-model="report" aria-label="Report"><option v-for="(name,id) in reports" :key="id" :value="id">{{ name }}</option></select></div>
            <div v-if="tab==='Expiry'" class="chips"><button v-for="[name,value] in [['Expire Today','today'],['Next 7 Days','7'],['Next 30 Days','30'],['Near Expiry','near'],['Expired','expired']]" :key="value" :class="{active:(filters.expiry_filter || 'near')===value}" @click="filters.expiry_filter=value">{{ name }}</button></div>
            <div class="filters" :class="{'filters-open':showFilters}">
                <label>Search<input v-model="filters.search" placeholder="Batch / product / SKU / barcode" /></label>
                <SearchSelect v-model="filters.product_id" label="Product" :options="refs.products" placeholder="All products" />
                <label>Branch<select v-model="filters.branch_id"><option value="">All branches</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select></label>
                <label>Warehouse<select v-model="filters.warehouse_id"><option value="">All warehouses</option><option v-for="w in warehouses(filters.branch_id)" :key="w.id" :value="w.id">{{ w.name }}</option></select></label>
                <label v-if="!isMovement && tab!=='Quarantine'">Status<select v-model="filters.batch_status"><option value="">All statuses</option><option v-for="s in refs.statuses" :key="s" :value="s">{{ label(s) }}</option></select></label>
                <label v-if="!isMovement">Expiry<select v-model="filters.expiry_filter"><option value="">All expiry dates</option><option value="today">Expire Today</option><option value="7">Next 7 Days</option><option value="30">Next 30 Days</option><option value="near">Near Expiry</option><option value="expired">Expired</option></select></label>
                <label v-if="isMovement">Movement type<select v-model="filters.movement_type"><option value="">All movements</option><option v-for="m in ['opening_stock','purchase','sale','sales_return_in','purchase_return_out','stock_adjustment_in','stock_adjustment_out','stock_transfer_in','stock_transfer_out','stock_reclassification_in','stock_reclassification_out','physical_count_gain','physical_count_shortage','damaged_stock','expired_stock']" :key="m" :value="m">{{ label(m) }}</option></select></label>
                <label>From Date<input v-model="filters.date_from" type="date" /></label><label>To Date<input v-model="filters.date_to" type="date" /></label>
                <label>Rows per page<select v-model="filters.per_page"><option v-for="n in [15,25,50,100]" :key="n" :value="n">{{ n }}</option></select></label><button @click="filters=emptyFilters()">Clear</button>
                <button v-if="filters.batch_id" @click="filters.batch_id=''">Clear selected batch ×</button>
            </div>
            <TableLoadingState v-if="loading" title="Loading batch records…" />
            <div v-else-if="!rows.length" class="empty-state"><h2>{{ page.total ? 'No matching batches' : 'No batch inventory yet' }}</h2><p>Batch stock will appear when you record opening stock, purchase/inward stock, or another batch stock operation.</p><p v-if="Object.values(filters).some(v=>v && v!==15)">Try clearing the filters to see other inventory.</p><div><button v-if="allowed('opening')" class="primary" @click="openOperation('opening')">Add Opening Batch</button><a :href="localLink('/app/purchases')" class="button">Create Purchase Voucher</a></div></div>
            <div v-else class="table-wrapper"><table v-if="isMovement"><thead><tr><th>Date &amp; Time</th><th>Document Date</th><th>Movement Type</th><th>Voucher / Reference</th><th>Batch</th><th>Product</th><th>From Branch</th><th>To Branch</th><th>From Warehouse</th><th>To Warehouse</th><th>From Condition</th><th>To Condition</th><th>IN</th><th>OUT</th><th>Net Qty</th><th>Unit Cost</th><th>Value</th><th>User</th><th>Reason / Note</th></tr></thead><tbody><tr v-for="r in rows" :key="r.id"><td>{{ r.date }}</td><td>{{ r.document_date }}</td><td>{{ r.movement_label }}</td><td>{{ r.voucher }}</td><td>{{ r.batch_number }}</td><td>{{ r.product_name }}</td><td>{{ r.from_branch || '—' }}</td><td>{{ r.to_branch || '—' }}</td><td>{{ r.from_warehouse || '—' }}</td><td>{{ r.to_warehouse || '—' }}</td><td>{{ label(r.from_condition) }}</td><td>{{ label(r.to_condition) }}</td><td class="good-text">{{ qty(r.in) }}</td><td class="bad-text">{{ qty(r.out) }}</td><td>{{ qty(r.net_qty) }}</td><td>{{ money(r.cost) }}</td><td>{{ money(r.stock_value) }}</td><td>{{ r.user }}</td><td>{{ r.remarks }}</td></tr></tbody></table>
                <table v-else><thead><tr><th>Batch Number</th><th>Product</th><th>SKU</th><th>FEFO Priority</th><th>Condition</th><th>Branch</th><th>Warehouse</th><th>MFG Date</th><th>Expiry Date</th><th>Days Remaining</th><th v-if="tab==='Quarantine'">Quarantine Date</th><th>Current Qty</th><th>Available Qty</th><th>Reserved Qty</th><th>Unit Cost</th><th>Batch Value</th><th>Status</th><th>Actions</th></tr></thead><tbody><tr v-for="r in rows" :key="key(r)"><td><button class="text-button" @click="view(r)">{{ r.batch_number }}</button></td><td>{{ r.product_name }}</td><td>{{ r.sku || '—' }}</td><td><span v-if="r.fefo_priority" class="priority">{{ r.fefo_priority }}</span><span v-else>—</span></td><td>{{ label(r.condition_status) }}</td><td>{{ r.branch_name || '—' }}</td><td>{{ r.warehouse_name || '—' }}</td><td>{{ date(r.mfg_date) }}</td><td>{{ date(r.expiry_date) }}</td><td>{{ r.days_remaining ?? '—' }}</td><td v-if="tab==='Quarantine'">{{ date(r.quarantined_at) }}</td><td>{{ qty(r.quantity_on_hand) }}</td><td>{{ qty(r.quantity_available) }}</td><td>{{ qty(r.reserved_quantity) }}</td><td>{{ money(r.average_cost) }}</td><td>₹ {{ money(r.batch_value) }}</td><td><span class="status" :class="r.batch_status">{{ r.status_label }}</span></td><td><RowActionMenu :open="menu===key(r)" :show-view="false" more-label="Actions" @toggle="menu=menu===key(r)?null:key(r)" @close="menu=null"><template v-for="action in r.actions" :key="action"><button v-if="allowed(action)" @click="action==='view'?view(r):action==='movements'?movements(r):openOperation(action,r)">{{ action==='view'?'View':action==='movements'?'Movement History':operationNames[action] }}</button></template></RowActionMenu></td></tr></tbody></table>
            </div>
            <div class="pager"><span>{{ page.from || 0 }}–{{ page.to || 0 }} of {{ page.total || 0 }}</span><button :disabled="loading || page.current_page<=1" @click="load(page.current_page-1)">Previous</button><button :disabled="loading || page.current_page>=page.last_page" @click="load(page.current_page+1)">Next</button></div>
        </section>
    </div>
    <InventoryModal v-if="modal" :title="operationNames[form.operation]" subtitle="Post a controlled inventory operation with a document reference and reason." :errors="errors" @close="closeModal">
        <form class="operation-form" @submit.prevent="submit">
            <label class="full">Operation<select :value="form.operation" :disabled="saving" @change="openOperation($event.target.value)"><option v-for="[value,name] in operations" :key="value" :value="value">{{ name }}</option></select></label>
            <template v-if="receipt"><SearchSelect v-model="form.product_id" label="Product" :options="refs.products" required /><label>Batch Number<input v-model="form.batch_number" required maxlength="100" /></label><label>Manufacturing Date<input v-model="form.manufacturing_date" type="date" /></label><label>Expiry Date<input v-model="form.expiry_date" type="date" :min="form.manufacturing_date || undefined" /></label><p class="full hint" v-if="form.operation==='stock_in'">For supplier purchases, use <a :href="localLink('/app/purchases')">Purchase Vouchers</a>. Manual Stock In posts through Stock Adjustment and its configured accounting rules.</p></template>
            <template v-else><template v-if="!selectedRow"><label class="full">Search batch<input v-model="batchSearch" placeholder="Search batch or product" /></label><SearchSelect v-model="batchChoice" class="full" label="Batch and location" :options="operationBatchOptions" option-value-key="option_key" required @update:model-value="chooseBatch" /></template><div v-else class="selection full"><strong>{{ selectedRow.batch_number }} · {{ selectedRow.product_name }}</strong><span>{{ selectedRow.branch_name }} / {{ selectedRow.warehouse_name }} · {{ label(selectedRow.condition_status) }}</span><span>Physical {{ qty(selectedRow.quantity_on_hand) }} · Available {{ qty(selectedRow.quantity_available) }} · Reserved {{ qty(selectedRow.reserved_quantity) }}</span></div></template>
            <template v-if="!statusOnly"><template v-if="receipt"><label>Branch<select v-model="form.branch_id" required><option value="">Select branch</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select></label><label>Warehouse<select v-model="form.warehouse_id" required><option value="">Select warehouse</option><option v-for="w in warehouses(form.branch_id)" :key="w.id" :value="w.id">{{ w.name }}</option></select></label><label>Stock Condition<select v-model="form.condition_status"><option v-for="c in refs.conditions" :key="c" :value="c">{{ label(c) }}</option></select></label></template>
            <label v-if="form.operation==='adjust'">Direction<select v-model="form.direction"><option value="in">Adjustment In</option><option value="out">Adjustment Out</option></select></label><label>Quantity<input v-model="form.quantity" type="number" min="0.001" step="0.001" required /></label><label v-if="receipt || (form.operation==='adjust' && form.direction==='in')">Unit Cost<input v-model="form.unit_cost" type="number" min="0" step="0.01" required /></label><label>Document Date<input v-model="form.document_date" type="date" required /></label>
            <template v-if="form.operation==='transfer'"><label>To Branch<select v-model="form.destination_branch_id" required><option value="">Select branch</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select></label><label>To Warehouse<select v-model="form.destination_warehouse_id" required><option value="">Select warehouse</option><option v-for="w in warehouses(form.destination_branch_id).filter(w=>Number(w.id)!==Number(form.warehouse_id))" :key="w.id" :value="w.id">{{ w.name }}</option></select></label></template>
            <label v-if="reclassifying">To Condition<select v-model="form.to_condition" required><option v-for="c in refs.conditions.filter(c=>c!==form.condition_status)" :key="c" :value="c">{{ label(c) }}</option></select></label></template>
            <p v-if="statusOnly" class="full hint">This changes the batch's sale eligibility across all locations. Physical quantity stays unchanged.</p>
            <p v-if="form.operation==='writeoff'" class="full warning">Write-off permanently removes this quantity from physical stock. Correct mistakes with a new compensating operation.</p>
            <label class="full">Reason / Notes<textarea v-model="form.reason" required maxlength="255" rows="3" /></label><label class="confirm full"><input v-model="form.confirmed" type="checkbox" /> I have checked the batch, location, quantity and reason.</label><div class="full modal-footer"><button type="button" :disabled="saving" @click="closeModal">Cancel</button><button class="primary" :disabled="saving || (!receipt && !form.batch_id)" type="submit">{{ saving?'Posting…':'Post Operation' }}</button></div>
        </form>
    </InventoryModal>
    <InventoryModal v-if="detailLoading || details" :title="details ? `Batch ${details.batch.batch_number}` : 'Loading batch…'" wide @close="details=null">
        <TableLoadingState v-if="detailLoading" title="Loading batch details…" />
        <template v-else><h3>{{ details.batch.product }} · {{ details.batch.sku }}</h3><p>MFG {{ date(details.batch.mfg_date) }} · Expiry {{ date(details.batch.expiry_date) }} · {{ label(details.batch.status) }}</p><div class="alert-grid"><div class="selection"><span>Physical</span><strong>{{ qty(details.summary.current_qty) }}</strong></div><div class="selection"><span>Available</span><strong>{{ qty(details.summary.available_qty) }}</strong></div><div class="selection"><span>Reserved</span><strong>{{ qty(details.summary.reserved_qty) }}</strong></div><div class="selection"><span>Value</span><strong>₹ {{ money(details.summary.batch_value) }}</strong></div></div><h3>Location &amp; condition balances</h3><div class="table-wrapper"><table><thead><tr><th>Branch / Warehouse</th><th>Condition</th><th>Current</th><th>Available</th><th>Cost</th><th>Value</th></tr></thead><tbody><tr v-for="r in details.balances" :key="key(r)"><td>{{ r.branch_name }} / {{ r.warehouse_name }}</td><td>{{ label(r.condition_status) }}</td><td>{{ qty(r.quantity_on_hand) }}</td><td>{{ qty(r.quantity_available) }}</td><td>{{ money(r.average_cost) }}</td><td>{{ money(r.batch_value) }}</td></tr></tbody></table></div><h3>Recent movements</h3><p class="hint">Latest 80 entries. Use Movement History for the full paginated ledger.</p><div class="table-wrapper"><table><thead><tr><th>Date</th><th>Movement</th><th>Document</th><th>IN</th><th>OUT</th><th>Condition</th><th>User / Reason</th></tr></thead><tbody><tr v-for="r in details.ledger" :key="r.id"><td>{{ r.date }}</td><td>{{ r.movement_label }}</td><td>{{ r.voucher }}</td><td>{{ qty(r.in) }}</td><td>{{ qty(r.out) }}</td><td>{{ label(r.from_condition) }} → {{ label(r.to_condition) }}</td><td>{{ r.user }}<small>{{ r.remarks }}</small></td></tr></tbody></table></div><h3>Batch activity</h3><div v-for="(h,i) in details.history" :key="i" class="activity"><strong>{{ label(h.event_type) }}</strong><span>{{ h.date }} · {{ h.user }}</span><p>{{ h.remarks }}</p></div></template>
    </InventoryModal>
</Layout>
</template>

<style scoped>
.batch-page :deep(.search-select-hint){display:none}.batch-page .bill-page-title{max-width:none}.batch-page .bill-page-title h1{white-space:normal}.batch-page{display:grid;gap:16px;color:#26364d}.toolbar,.inline-actions,.section-head,.pager,.modal-footer{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.toolbar{justify-content:flex-end}.tabs{display:flex;gap:4px;border-bottom:1px solid #dce4ee;overflow-x:auto}.tabs button{border:0;border-radius:6px 6px 0 0;white-space:nowrap;padding:12px 18px;background:transparent}.tabs .active,.chips .active{background:#e9f1ff;color:#215bd8;box-shadow:inset 0 -2px #3268dc}button,.button{border:1px solid #d8e0eb;border-radius:7px;padding:8px 12px;min-height:36px;background:#fff;color:#344159;font-size:12px;font-weight:650;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}button:hover,.button:hover{background:#f3f7fc}button:disabled{opacity:.5;cursor:not-allowed}.primary{background:#285fd4;color:#fff;border-color:#285fd4}.primary:hover{background:#1e4fb7}.panel{border:1px solid #e0e6ef;background:#fff;border-radius:10px;padding:18px}h2{font-size:16px;margin:0 0 14px}h3{font-size:14px;margin:18px 0 10px}p{font-size:13px;color:#6b7890;line-height:1.6}.summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.summary-card{display:flex;align-items:flex-start;flex-direction:column;text-align:left;gap:12px;border-radius:10px;padding:18px;border-top:3px solid #5a87df}.summary-card strong{font-size:25px;color:#172e51}.summary-card span{font-size:12px;color:#68768b}.summary-card small,.alert-card small{font-size:11px;color:#7b8ba1}.summary-card.bad{border-top-color:#e36b77}.summary-card.warn{border-top-color:#e2b55b}.summary-card.good{border-top-color:#51ae94}.alert-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.alert-card{display:grid;text-align:left;justify-content:stretch;gap:10px;padding:16px;background:#fafbfe}.alert-card strong{font-size:22px}.attention{display:grid;gap:8px}.attention button{justify-content:flex-start;text-align:left;border-color:#f1dfb7;background:#fffcf4}.empty-state{text-align:center;padding:36px 20px}.empty-state p{max-width:620px;margin:10px auto 20px}.empty-state button{margin-right:8px}.section-head{justify-content:space-between;margin-bottom:12px}.section-head h2{margin:0}.filters{display:grid;grid-template-columns:repeat(5,minmax(130px,1fr));gap:12px;align-items:end;margin:16px 0}.filters label,.operation-form label{display:flex;flex-direction:column;gap:6px;font-size:11px;font-weight:650;color:#52647d}input,select,textarea{border:1px solid #d8e0eb;border-radius:6px;min-height:36px;padding:8px 10px;font-family:inherit;font-size:12px;background:#fff;color:#26364d;width:100%;box-sizing:border-box}.table-wrapper{overflow:auto;width:100%}table{width:100%;border-collapse:collapse;font-size:12px}th{background:#f5f7fb;font-weight:650;color:#60718a;white-space:nowrap}th,td{padding:12px 10px;text-align:left;border-bottom:1px solid #eaf0f5;white-space:nowrap}td small{display:block;color:#7b8798;margin-top:4px}.pager{justify-content:flex-end;margin-top:14px;font-size:12px}.pager span{margin-right:auto;color:#6b7890}.text-button{padding:0;border:0;color:#245ed3;background:transparent;min-height:auto}.status{font-size:10px;padding:5px 8px;border-radius:20px;background:#eef5ef;color:#397555}.status.expired,.status.blocked,.status.damaged{background:#fff0f0;color:#a6414f}.status.quarantined,.status.near_expiry,.status.expire_today{background:#fff7e7;color:#956f24}.status.empty{background:#edf0f5;color:#75839a}.priority{background:#eaf1ff;color:#3561bc;border-radius:5px;padding:4px 8px}.chips{display:flex;gap:8px;overflow:auto;margin:12px 0}.chips button{white-space:nowrap}.mobile-filter{display:none}.operation-form{display:grid;grid-template-columns:1fr 1fr;gap:14px}.full{grid-column:1/-1}.selection{display:grid;gap:7px;background:#f3f7fd;padding:14px;border-radius:7px;font-size:12px}.selection span{color:#6b7890}.hint{font-size:12px;margin:0}.warning{padding:12px;border-radius:6px;background:#fff2ed;color:#a45133}.confirm{flex-direction:row!important;align-items:center}.confirm input{width:16px;min-height:16px}.modal-footer{justify-content:flex-end}.good-text{color:#278560}.bad-text{color:#b64b56}.activity{border-left:2px solid #d8e4f7;padding:8px 14px;font-size:12px}.activity span{display:block;color:#76849a;margin-top:5px}@media(max-width:1100px){.filters{grid-template-columns:repeat(3,minmax(120px,1fr))}.summary-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:720px){.summary-grid,.alert-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.mobile-filter{display:inline-flex}.filters{display:none}.filters.filters-open{display:grid;grid-template-columns:1fr 1fr}.toolbar{justify-content:flex-start}.toolbar .primary{width:100%}.panel{padding:12px}.tabs button{padding:10px 12px}.operation-form{grid-template-columns:1fr}.full{grid-column:auto}}@media(max-width:420px){.summary-grid,.alert-grid,.filters.filters-open{grid-template-columns:1fr}}
</style>
