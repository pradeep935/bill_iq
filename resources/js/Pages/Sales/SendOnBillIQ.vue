<script setup>
import { onMounted, ref } from 'vue';
import axios from 'axios';

const props = defineProps({ sale: { type: Object, required: true } });
const emit = defineEmits(['close']);

const businesses = ref([]);
const loading = ref(true);
const busy = ref(false);
const error = ref('');
const notice = ref('');
const message = (e) => Object.values(e.response?.data?.errors || {})[0]?.[0] || e.response?.data?.message || 'Something went wrong. Please try again.';

const load = async () => {
    try {
        businesses.value = (await axios.get(`/app/sales/invoices/${props.sale.id}/network`)).data.businesses;
    } catch (e) {
        error.value = message(e);
    } finally {
        loading.value = false;
    }
};
const send = async (business) => {
    if (busy.value) return;
    // A bill carries rates and GSTIN, so a send to someone other than the customer on it is confirmed.
    if (!business.matches_customer && !window.confirm(`${business.name} does not match this invoice's customer mobile or GSTIN. Send bill ${props.sale.invoice_number} to them?`)) return;
    busy.value = true;
    error.value = '';
    try {
        notice.value = (await axios.post(`/app/sales/invoices/${props.sale.id}/network`, { business_id: business.id })).data.message;
        await load();
    } catch (e) {
        error.value = message(e);
    } finally {
        busy.value = false;
    }
};

onMounted(load);
</script>

<template>
    <div class="sb-backdrop" @click.self="emit('close')">
        <section class="sb-panel" role="dialog" aria-modal="true" aria-label="Send bill on BillIQ">
            <div class="sb-head"><div><span>SEND ON BILLIQ</span><h2>{{ sale.invoice_number }} · {{ sale.customer }}</h2></div><button type="button" @click="emit('close')">Close</button></div>
            <p class="sb-help">The buyer receives this bill in “Supplier Bills” and can add it to their purchases.</p>
            <p v-if="notice" class="sb-note ok" role="status">{{ notice }}</p>
            <p v-if="error" class="sb-note bad" role="alert">{{ error }}</p>
            <p v-if="loading" class="sb-help">Loading…</p>
            <p v-else-if="!businesses.length && !error" class="sb-help">No connected business yet. <a href="/app/purchase/network?tab=connections">Connect with your customer</a> first. They must accept your request.</p>
            <ul v-else>
                <li v-for="business in businesses" :key="business.id">
                    <div><strong>{{ business.name }} <em v-if="business.matches_customer">This customer</em></strong><small>{{ [business.state, business.gstin].filter(Boolean).join(' · ') }}</small></div>
                    <button type="button" :class="{ primary: !business.already_sent }" :disabled="busy" @click="send(business)">{{ business.already_sent ? 'Send again' : 'Send' }}</button>
                </li>
            </ul>
        </section>
    </div>
</template>

<style scoped>
.sb-backdrop{position:fixed;z-index:1100;inset:0;display:grid;place-items:center;padding:16px;background:rgba(15,23,42,.45)}
.sb-panel{width:min(560px,100%);max-height:calc(100vh - 32px);overflow:auto;padding:18px;background:#fff;border-radius:12px;box-shadow:0 24px 70px rgba(15,23,42,.25)}
.sb-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
.sb-head span{color:#2457d6;font-size:10px;font-weight:800;letter-spacing:1.2px}
.sb-head h2{margin:2px 0 0;color:#142139;font-size:17px}
.sb-help{color:#667085;font-size:13px}
button{min-height:36px;padding:7px 14px;color:#344159;background:#fff;border:1px solid #d8e0eb;border-radius:8px;font-size:13px;font-weight:750;cursor:pointer}
button.primary{color:#fff;background:#2457d6;border-color:#2457d6}
ul{margin:0;padding:0;list-style:none}
li{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 0;border-top:1px solid #eef2f7}
li div{display:grid;gap:2px}
li strong{color:#17233b;font-size:13px}
li small{color:#758197;font-size:12px}
em{margin-left:6px;padding:2px 7px;color:#168757;background:#eaf8f1;border-radius:999px;font-size:10px;font-style:normal;font-weight:800}
.sb-note{margin:0 0 10px;padding:9px 12px;border-radius:8px;font-size:13px}
.sb-note.ok{color:#146c43;background:#eaf8f1}
.sb-note.bad{color:#96333a;background:#fff3f4}
</style>
