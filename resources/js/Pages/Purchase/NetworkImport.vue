<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import axios from 'axios';
import PurchaseApi from './PurchaseApi';
import { formatDate } from '../../Support/dateFormat.js';

// Step before the purchase form: paste a supplier's bill link (or open a received bill) and match its items to own products.
const props = defineProps({ source: { type: Object, default: null }, suppliers: { type: Array, default: () => [] } });
const emit = defineEmits(['close', 'ready']);

const link = ref('');
const current = ref(null);
const bill = ref(null);
const supplierId = ref('');
const rows = ref([]);
const busy = ref(false);
const error = ref('');

const money = (value) => Number(value || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const message = (e) => Object.values(e.response?.data?.errors || {})[0]?.[0] || e.response?.data?.message || 'Something went wrong. Please try again.';
const pending = computed(() => rows.value.filter((row) => row.action === '').length);

const load = async (source) => {
    if (busy.value) return;
    busy.value = true;
    error.value = '';
    try {
        const { data } = await axios.post('/app/purchase/network/preview', { source });
        current.value = source;
        bill.value = data;
        supplierId.value = data.supplier.matched?.id || '';
        rows.value = data.items.map((item) => reactive({
            ...item,
            action: item.match ? 'link' : (item.is_service ? 'charge' : ''),
            product: item.match, search: '', results: [], searched: false,
        }));
    } catch (e) {
        error.value = message(e);
    } finally {
        busy.value = false;
    }
};

let timer;
const searchProduct = (row) => {
    clearTimeout(timer);
    row.searched = false;
    const term = row.search.trim();
    if (term.length < 2) { row.results = []; return; }
    timer = setTimeout(async () => {
        const found = await PurchaseApi.searchProducts(term).catch(() => []);
        if (row.search.trim() !== term) return;
        row.results = found.slice(0, 8);
        row.searched = true;
    }, 250);
};
const pick = (row, product) => Object.assign(row, { action: 'link', product: { id: product.id, name: product.name }, results: [], search: '', searched: false });
const choose = (row, action) => Object.assign(row, { action, product: null, results: [], search: '', searched: false });
const createRest = () => rows.value.filter((row) => row.action === '').forEach((row) => choose(row, row.is_service ? 'charge' : 'create'));

const proceed = async () => {
    if (busy.value || pending.value) return;
    busy.value = true;
    error.value = '';
    try {
        const { data } = await axios.post('/app/purchase/network/prepare', {
            source: current.value,
            supplier_id: supplierId.value || null,
            items: rows.value.map((row) => ({ source_item_id: row.source_item_id, action: row.action, product_id: row.product?.id || null })),
        });
        emit('ready', data);
    } catch (e) {
        error.value = message(e);
    } finally {
        busy.value = false;
    }
};

onMounted(() => { if (props.source) load(props.source); });
</script>

<template>
    <div class="ni-backdrop" @click.self="emit('close')">
        <section class="ni-panel" role="dialog" aria-modal="true" aria-label="Add purchase from supplier bill">
            <div class="ni-head">
                <div><span>SUPPLIER BILL</span><h2>{{ bill ? `Bill ${bill.invoice.number}` : 'Add purchase from bill link' }}</h2></div>
                <button type="button" @click="emit('close')">Close</button>
            </div>

            <template v-if="!bill">
                <p class="ni-help">Paste the BillIQ bill link your supplier sent on WhatsApp. The whole message can be pasted too.</p>
                <form class="ni-link" @submit.prevent="link.trim() && load({ token: link.trim() })">
                    <input v-model="link" aria-label="Bill link" placeholder="https://…/i/…" autocomplete="off" />
                    <button type="submit" class="primary" :disabled="busy || !link.trim()">{{ busy ? 'Opening…' : 'Open Bill' }}</button>
                </form>
                <p v-if="busy && source" class="ni-help">Opening bill…</p>
            </template>

            <template v-else>
                <div class="ni-summary">
                    <div><span>From</span><strong>{{ bill.supplier.name }}</strong><small>{{ bill.supplier.gstin ? `GSTIN ${bill.supplier.gstin}` : 'No GSTIN' }}<template v-if="bill.supplier.mobile"> · {{ bill.supplier.mobile }}</template></small></div>
                    <div><span>Date</span><strong>{{ formatDate(bill.invoice.date) }}</strong></div>
                    <div><span>Bill amount</span><strong>Rs. {{ money(bill.invoice.grand_total) }}</strong></div>
                </div>

                <label class="ni-supplier">Add under supplier
                    <select v-model="supplierId">
                        <option v-if="!bill.supplier.matched" value="">+ Create new supplier “{{ bill.supplier.name }}”</option>
                        <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.supplier_name || s.name }}</option>
                    </select>
                </label>

                <div class="ni-items-head">
                    <h3>Match items with your products</h3>
                    <button v-if="pending > 1" type="button" class="secondary" @click="createRest">Create all {{ pending }} unmatched as new products</button>
                </div>
                <ul class="ni-items">
                    <li v-for="(row, index) in rows" :key="row.source_item_id" :class="{ todo: !row.action }">
                        <div class="ni-item">
                            <strong>{{ index + 1 }}. {{ row.name }}</strong>
                            <small>{{ row.quantity }} {{ row.unit }}<template v-if="row.free_quantity"> + {{ row.free_quantity }} free</template> × {{ money(row.rate) }} · GST {{ row.gst_rate }}% · Rs. {{ money(row.amount) }}</small>
                        </div>
                        <div class="ni-choice">
                            <p v-if="row.action === 'link'" class="ni-chosen ok">Your product: <b>{{ row.product.name }}</b> <button type="button" class="link" @click="choose(row, '')">Change</button></p>
                            <p v-else-if="row.action === 'create'" class="ni-chosen new">Will be added as a new product <button type="button" class="link" @click="choose(row, '')">Change</button></p>
                            <p v-else-if="row.action === 'charge'" class="ni-chosen">Added to Transport / Other Charges (no stock) <button type="button" class="link" @click="choose(row, '')">Change</button></p>
                            <template v-else>
                                <input v-model="row.search" :aria-label="`Search your product for ${row.name}`" placeholder="Search your product…" autocomplete="off" @input="searchProduct(row)" />
                                <div v-if="row.results.length" class="ni-results">
                                    <button v-for="product in row.results" :key="product.id" type="button" @click="pick(row, product)">{{ product.name }} <small>{{ product.sku }}</small></button>
                                </div>
                                <small v-else-if="row.searched">No product found. Add it as new.</small>
                                <div class="ni-options">
                                    <button v-if="!row.is_service" type="button" class="secondary" @click="choose(row, 'create')">+ New product</button>
                                    <button type="button" @click="choose(row, 'charge')">Not stock (charge)</button>
                                </div>
                            </template>
                        </div>
                    </li>
                </ul>
            </template>

            <p v-if="error" class="ni-error" role="alert">{{ error }}</p>

            <div v-if="bill" class="ni-foot">
                <span>{{ pending ? `${pending} item${pending > 1 ? 's' : ''} still to match` : 'Next you can check the purchase before saving. Nothing is posted yet.' }}</span>
                <button type="button" class="primary" :disabled="busy || pending > 0" @click="proceed">{{ busy ? 'Please wait…' : 'Continue to Purchase' }}</button>
            </div>
        </section>
    </div>
</template>

<style scoped>
.ni-backdrop{position:fixed;z-index:1000;inset:0;display:grid;place-items:center;padding:16px;background:rgba(15,23,42,.45)}
.ni-panel{width:min(900px,100%);max-height:calc(100vh - 32px);overflow:auto;padding:18px;background:#fff;border-radius:12px;box-shadow:0 24px 70px rgba(15,23,42,.25)}
.ni-head,.ni-items-head,.ni-foot{display:flex;align-items:center;justify-content:space-between;gap:12px}
.ni-head span{color:#2457d6;font-size:10px;font-weight:800;letter-spacing:1.2px}
.ni-head h2{margin:2px 0 0;color:#142139;font-size:20px}
.ni-help{color:#667085;font-size:13px}
input,select,button{min-height:38px;padding:8px 10px;color:#344159;background:#fff;border:1px solid #d8e0eb;border-radius:8px;font-size:13px}
button{font-weight:750;cursor:pointer}
button:disabled{opacity:.55;cursor:not-allowed}
.primary{color:#fff;background:#2457d6;border-color:#2457d6}
.secondary{color:#2457d6;background:#edf4ff;border-color:#cfe0ff}
button.link{min-height:0;padding:0;color:#2457d6;background:none;border:0;font-size:12px}
.ni-link{display:flex;gap:8px}
.ni-link input{flex:1;min-width:0}
.ni-summary{display:grid;grid-template-columns:2fr 1fr 1fr;gap:12px;margin:14px 0;padding:12px 14px;background:#f8fafc;border:1px solid #e3e9f2;border-radius:10px}
.ni-summary div{display:grid;gap:2px;align-content:start}
.ni-summary span{color:#98a2b3;font-size:10px;font-weight:800;text-transform:uppercase}
.ni-summary strong{color:#142139;font-size:15px}
.ni-summary small,.ni-item small,.ni-choice small{color:#667085;font-size:12px}
.ni-supplier select{width:100%;min-width:0}
.ni-supplier{display:grid;grid-template-columns:minmax(0,1fr);gap:5px;max-width:420px;color:#667085;font-size:11px;font-weight:800}
.ni-items-head{margin:16px 0 8px;flex-wrap:wrap}
.ni-items-head h3{margin:0;color:#142139;font-size:14px}
.ni-items{margin:0;padding:0;list-style:none;border:1px solid #e3e9f2;border-radius:10px}
.ni-items li{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:14px;padding:10px 12px;border-bottom:1px solid #eef2f7}
.ni-items li:last-child{border-bottom:0}
.ni-items li.todo{background:#fffaf0}
.ni-item{display:grid;gap:3px;align-content:start;font-size:13px;color:#17233b}
.ni-choice{display:grid;gap:6px;align-content:start}
.ni-choice input{width:100%}
.ni-chosen{margin:0;font-size:13px;color:#475569}
.ni-chosen.ok{color:#168757}
.ni-chosen.new{color:#b54708}
.ni-results{display:grid;border:1px solid #dce4ef;border-radius:8px;max-height:180px;overflow:auto}
.ni-results button{border:0;border-bottom:1px solid #f1f4f8;border-radius:0;text-align:left;font-weight:600}
.ni-options{display:flex;gap:8px;flex-wrap:wrap}
.ni-error{margin:12px 0 0;padding:10px 12px;color:#96333a;background:#fff3f4;border:1px solid #ffd4d8;border-radius:8px;font-size:13px}
.ni-foot{position:sticky;bottom:-18px;margin:14px -18px -18px;padding:12px 18px;background:#fff;border-top:1px solid #edf1f5;flex-wrap:wrap}
.ni-foot span{color:#667085;font-size:12px}
@media(max-width:640px){.ni-summary,.ni-items li{grid-template-columns:minmax(0,1fr)}.ni-link{flex-direction:column}}
</style>
