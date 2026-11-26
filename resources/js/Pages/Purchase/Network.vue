<script setup>
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';
import { usePage } from '@inertiajs/vue3';
import Layout from '../Layout.vue';
import { formatDate } from '../../Support/dateFormat.js';

defineProps({ page: { type: String, default: 'supplier-bills' }, title: { type: String, default: 'Supplier Bills' } });

// Only an admin decides which businesses are connected.
const isAdmin = [1, 2].includes(Number(usePage().props.auth?.user?.role_id));
const tab = ref(isAdmin && new URLSearchParams(window.location.search).get('tab') === 'connections' ? 'connections' : 'bills');
const data = ref({ me: {}, connections: [], incoming: [] });
const loading = ref(true);
const error = ref('');
const notice = ref('');
const term = ref('');
const found = ref(null);
const busy = ref(false);
const link = ref('');

const money = (value) => Number(value || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const message = (e) => Object.values(e.response?.data?.errors || {})[0]?.[0] || (e.response?.status === 429 ? 'Too many tries. Please wait a minute.' : e.response?.data?.message) || 'Something went wrong. Please try again.';
const requests = computed(() => data.value.connections.filter((c) => c.status === 'pending' && c.direction === 'received'));
const waiting = computed(() => data.value.connections.filter((c) => c.status === 'pending' && c.direction === 'sent'));
const connected = computed(() => data.value.connections.filter((c) => c.status === 'accepted'));
const newBills = computed(() => data.value.incoming.filter((b) => b.state === 'new').length);

const load = async () => {
    try {
        data.value = (await axios.get('/app/purchase/network/data')).data;
    } catch (e) {
        error.value = message(e);
    } finally {
        loading.value = false;
    }
};
const run = async (action) => {
    if (busy.value) return;
    busy.value = true;
    error.value = '';
    notice.value = '';
    try {
        const response = await action();
        if (response?.data?.message) notice.value = response.data.message;
        await load();
    } catch (e) {
        error.value = message(e);
    } finally {
        busy.value = false;
    }
};
const search = () => run(async () => {
    found.value = null;
    found.value = (await axios.get('/app/purchase/network/lookup', { params: { q: term.value.trim() } })).data.businesses;
});
const connect = (business) => run(async () => {
    const response = await axios.post('/app/purchase/network/connections', { q: term.value.trim(), business_id: business.id });
    found.value = null;
    term.value = '';
    return response;
});
const respond = (connection, action) => {
    if (action === 'remove' && !window.confirm(`Remove ${connection.business.name}? Bills can no longer be shared between you.`)) return;
    run(() => axios.post(`/app/purchase/network/connections/${connection.id}/${action}`));
};
const dismiss = (row) => {
    if (!window.confirm(`Remove bill ${row.invoice_number} from this list?`)) return;
    run(() => axios.post(`/app/purchase/network/bills/${row.id}/dismiss`));
};
const add = (row) => { window.location.href = `/app/purchase/bills?network_bill=${row.id}`; };
const openLink = () => { if (link.value.trim()) window.location.href = `/app/purchase/bills?network_link=${encodeURIComponent(link.value.trim())}`; };

onMounted(load);
</script>

<template>
    <Layout :page="page" :title="title">
        <template #topbar-title>
            <div class="bill-page-title"><span>PURCHASE MANAGEMENT</span><h1>Supplier Bills</h1><p>Receive bills from suppliers who use BillIQ and add them to purchases without typing.</p></div>
        </template>
        <div class="net-page">
            <div class="net-tabs" role="tablist">
                <button type="button" role="tab" :aria-selected="tab === 'bills'" :class="{ active: tab === 'bills' }" @click="tab = 'bills'">Received Bills <b v-if="newBills">{{ newBills }}</b></button>
                <button v-if="isAdmin" type="button" role="tab" :aria-selected="tab === 'connections'" :class="{ active: tab === 'connections' }" @click="tab = 'connections'">Connections <b v-if="requests.length">{{ requests.length }}</b></button>
            </div>
            <p v-if="notice" class="net-note ok" role="status">{{ notice }}</p>
            <p v-if="error" class="net-note bad" role="alert">{{ error }}</p>

            <template v-if="tab === 'bills'">
                <section class="panel">
                    <h2>Have a bill link?</h2>
                    <p class="muted">Paste the link your supplier sent on WhatsApp.</p>
                    <form class="row" @submit.prevent="openLink">
                        <input v-model="link" aria-label="Bill link" placeholder="Paste bill link" autocomplete="off" />
                        <button type="submit" class="primary" :disabled="!link.trim()">Add Purchase</button>
                    </form>
                </section>
                <section class="panel">
                    <h2>Bills sent to you</h2>
                    <p v-if="loading" class="muted">Loading…</p>
                    <p v-else-if="!data.incoming.length && !isAdmin" class="muted">No bills yet. Ask your supplier to open the sales invoice and click “Send on BillIQ”. Your admin manages the connections.</p>
                    <p v-else-if="!data.incoming.length" class="muted">No bills yet. Connect with your supplier under <button type="button" class="linkish" @click="tab = 'connections'">Connections</button>, then ask them to open the sales invoice and click “Send on BillIQ”.</p>
                    <div v-else class="table-wrapper">
                        <table>
                            <thead><tr><th>Supplier</th><th>Bill no.</th><th>Bill date</th><th class="num">Amount</th><th>Status</th><th></th></tr></thead>
                            <tbody>
                                <tr v-for="row in data.incoming" :key="row.id">
                                    <td><strong>{{ row.supplier }}</strong></td>
                                    <td>{{ row.invoice_number }}</td>
                                    <td>{{ formatDate(row.invoice_date) }}</td>
                                    <td class="num">{{ row.grand_total === null ? '—' : money(row.grand_total) }}</td>
                                    <td><span class="badge" :class="row.state">{{ row.state === 'new' ? 'New' : row.state === 'added' ? `Added · ${row.purchase_number}` : 'Cancelled by supplier' }}</span></td>
                                    <td class="actions">
                                        <button v-if="row.state === 'new'" type="button" class="primary" @click="add(row)">Add to Purchase</button>
                                        <button type="button" :disabled="busy" @click="dismiss(row)">Remove</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </template>

            <template v-else>
                <section class="panel">
                    <h2>Connect with a business</h2>
                    <p class="muted">Enter their full mobile number or GSTIN, as saved in their BillIQ business profile. Bills are shared only after they accept.</p>
                    <form class="row" @submit.prevent="search">
                        <input v-model="term" aria-label="Mobile number or GSTIN" placeholder="10 digit mobile or 15 character GSTIN" autocomplete="off" maxlength="30" />
                        <button type="submit" class="primary" :disabled="busy || !term.trim()">Search</button>
                    </form>
                    <p v-if="found && !found.length" class="muted">No BillIQ business found with this number. Check it, or ask them to fill mobile and GSTIN in their business profile.</p>
                    <ul v-if="found?.length" class="cards">
                        <li v-for="business in found" :key="business.id">
                            <div><strong>{{ business.name }}</strong><small>{{ [business.state, business.gstin].filter(Boolean).join(' · ') }}</small></div>
                            <span v-if="business.connection === 'accepted'" class="badge added">Connected</span>
                            <span v-else-if="business.connection === 'pending'" class="badge">Request pending</span>
                            <button v-else type="button" class="primary" :disabled="busy" @click="connect(business)">Send Request</button>
                        </li>
                    </ul>
                    <p v-if="data.me.name" class="mine">Others can find you by: <b>{{ data.me.phone || 'no mobile saved' }}</b> · <b>{{ data.me.gstin || 'no GSTIN saved' }}</b></p>
                </section>
                <section v-if="requests.length" class="panel">
                    <h2>Requests for you</h2>
                    <p class="muted">Accept only a business you know. After accepting, you can send bills to each other.</p>
                    <ul class="cards">
                        <li v-for="c in requests" :key="c.id">
                            <div><strong>{{ c.business.name }}</strong><small>{{ [c.business.state, c.business.gstin, c.business.phone].filter(Boolean).join(' · ') }}</small></div>
                            <span class="actions"><button type="button" class="primary" :disabled="busy" @click="respond(c, 'accept')">Accept</button><button type="button" :disabled="busy" @click="respond(c, 'reject')">Decline</button></span>
                        </li>
                    </ul>
                </section>
                <section class="panel">
                    <h2>Connected businesses</h2>
                    <p v-if="!connected.length && !waiting.length" class="muted">No connections yet.</p>
                    <ul v-else class="cards">
                        <li v-for="c in [...connected, ...waiting]" :key="c.id">
                            <div><strong>{{ c.business.name }}</strong><small>{{ [c.business.state, c.business.gstin, c.business.phone].filter(Boolean).join(' · ') }}</small></div>
                            <span class="actions"><span class="badge" :class="{ added: c.status === 'accepted' }">{{ c.status === 'accepted' ? 'Connected' : 'Waiting for them' }}</span><button type="button" :disabled="busy" @click="respond(c, 'remove')">Remove</button></span>
                        </li>
                    </ul>
                </section>
            </template>
        </div>
    </Layout>
</template>

<style scoped>
.net-page{padding:4px 0 28px}
.net-tabs{display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap}
input,button{min-height:38px;padding:8px 12px;color:#344159;background:#fff;border:1px solid #d8e0eb;border-radius:8px;font-size:13px}
button{font-weight:750;cursor:pointer}
button:disabled{opacity:.55;cursor:not-allowed}
.primary{color:#fff;background:#2457d6;border-color:#2457d6}
.net-tabs button.active{color:#2457d6;background:#edf4ff;border-color:#9db7f0}
.net-tabs b{display:inline-block;min-width:18px;margin-left:4px;padding:0 5px;color:#fff;background:#d23f49;border-radius:999px;font-size:11px}
.panel{margin-bottom:14px;padding:16px 18px;background:#fff;border:1px solid #dfe6ef;border-radius:10px}
.panel h2{margin:0 0 4px;color:#142139;font-size:15px;font-weight:800}
.muted{margin:0 0 10px;color:#667085;font-size:13px}
.linkish{min-height:0;padding:0;color:#2457d6;background:none;border:0}
.row{display:flex;gap:8px}
.row input{flex:1;min-width:0;max-width:520px}
.mine{margin:12px 0 0;color:#667085;font-size:12px}
.cards{margin:10px 0 0;padding:0;list-style:none}
.cards li{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 0;border-top:1px solid #eef2f7;flex-wrap:wrap}
.cards div{display:grid;gap:2px}
.cards strong{color:#17233b;font-size:13px}
.cards small{color:#758197;font-size:12px}
.actions{display:flex;align-items:center;justify-content:flex-end;gap:8px}
.table-wrapper{overflow-x:auto}
table{width:100%;border-collapse:collapse}
th{padding:10px 8px;color:#69758a;background:#f8fafc;border-bottom:1px solid #e7ecf2;font-size:10px;font-weight:800;text-align:left;text-transform:uppercase;white-space:nowrap}
td{padding:10px 8px;color:#27344c;border-bottom:1px solid #edf1f5;font-size:13px;white-space:nowrap}
.num{text-align:right}
td.actions{border-bottom:1px solid #edf1f5}
.badge{padding:4px 9px;color:#475569;background:#eef2f7;border-radius:999px;font-size:11px;font-weight:800}
.badge.new{color:#2457d6;background:#edf2ff}
.badge.added{color:#168757;background:#eaf8f1}
.badge.cancelled{color:#d23f49;background:#fff3f4}
.net-note{margin:0 0 12px;padding:10px 12px;border-radius:8px;font-size:13px}
.net-note.ok{color:#146c43;background:#eaf8f1}
.net-note.bad{color:#96333a;background:#fff3f4}
@media(max-width:600px){.row{flex-direction:column}.row input{max-width:none}}
</style>
