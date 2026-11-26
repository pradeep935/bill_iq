<template>
  <Layout :page="page" :title="title">
    <template #topbar-title><h1 class="pr-title">Payment Reminders</h1></template>
    <section class="pr">
      <div class="bq-cards">
        <button type="button" class="bq-card" @click="show = 'all'"><span>To collect</span><strong>{{ money(totals.total_due) }}</strong><small>{{ totals.customers }} customers with dues</small></button>
        <button type="button" class="bq-card due" @click="show = 'overdue'"><span>Overdue</span><strong>{{ money(totals.overdue_due) }}</strong><small>Past the due date</small></button>
      </div>

      <div class="pr-panel">
        <form class="bq-search" role="search" @submit.prevent="load">
          <input v-model="search" type="search" aria-label="Search customer or mobile" placeholder="Search customer or mobile" />
          <button type="submit">Refresh</button>
        </form>
        <div class="bq-chips">
          <button v-for="chip in chips" :key="chip.value" type="button" :class="{ active: show === chip.value }" :aria-pressed="show === chip.value" @click="show = chip.value">{{ chip.label }}</button>
        </div>
        <p v-if="notice" class="pr-notice">{{ notice }}</p>

        <div class="pr-wrap">
          <table class="bq-table bq-labeled">
            <thead><tr><th>Customer</th><th>Mobile</th><th class="num">Unpaid bills</th><th class="num">Total due</th><th class="num">Overdue</th><th class="num">Days late</th><th></th></tr></thead>
            <tbody>
              <tr v-for="r in filtered" :key="r.customer_id">
                <td data-label="Customer" class="bq-name"><strong>{{ r.name }}</strong></td>
                <td data-label="Mobile">{{ r.mobile || '—' }}</td>
                <td data-label="Unpaid bills" class="num">{{ r.invoices }}</td>
                <td data-label="Total due" class="num">{{ money(r.total_due) }}</td>
                <td data-label="Overdue" class="num" :class="{ red: r.overdue_due > 0 }">{{ money(r.overdue_due) }}</td>
                <td data-label="Days late" class="num">{{ r.oldest_overdue_days || '—' }}</td>
                <td class="bq-actions">
                  <div class="row-actions">
                    <button type="button" class="bq-quick wa" :disabled="!r.has_whatsapp || sending === r.customer_id" :title="r.has_whatsapp ? 'Send WhatsApp reminder' : 'No valid mobile number'" @click="remind(r)">
                      {{ sending === r.customer_id ? '…' : (r.has_whatsapp ? 'WhatsApp reminder' : 'No mobile number') }}
                    </button>
                  </div>
                </td>
              </tr>
              <tr v-if="!filtered.length"><td colspan="7" class="empty">{{ loading ? 'Loading…' : (rows.length ? 'No customer matches.' : 'No pending payments. 🎉') }}</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>
  </Layout>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';
import Layout from '../Layout.vue';

defineProps({ page: { type: String, default: 'payment-reminders' }, title: { type: String, default: 'Payment Reminders' } });

const rows = ref([]);
const totals = ref({ customers: 0, total_due: 0, overdue_due: 0 });
const loading = ref(true);
const search = ref('');
const show = ref('all');
const chips = [{ value: 'all', label: 'All dues' }, { value: 'overdue', label: 'Overdue only' }];
const sending = ref(null);
const notice = ref('');

const money = (v) => 'Rs. ' + Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const filtered = computed(() => rows.value.filter((r) => {
  if (show.value === 'overdue' && !(r.overdue_due > 0)) return false;
  const q = search.value.trim().toLowerCase();
  return !q || String(r.name).toLowerCase().includes(q) || String(r.mobile || '').includes(q);
}));

async function load() {
  loading.value = true;
  try {
    const { data } = await axios.get('/app/sales/payment-reminders/data');
    rows.value = data.rows; totals.value = data.totals;
  } catch (e) { notice.value = 'Could not load pending payments.'; } finally { loading.value = false; }
}

async function remind(r) {
  sending.value = r.customer_id; notice.value = '';
  try {
    const { data } = await axios.post(`/app/sales/payment-reminders/${r.customer_id}/whatsapp`);
    window.open(data.url, '_blank', 'noopener');
    notice.value = `WhatsApp opened for ${r.name}. Press Send in WhatsApp to deliver the reminder.`;
  } catch (e) {
    notice.value = e.response?.data?.errors?.whatsapp_number?.[0] || e.response?.data?.message || 'Could not prepare the reminder.';
  } finally { sending.value = null; }
}

onMounted(load);
</script>

<style scoped>
.pr-title { margin: 0; font-size: 22px; }
.pr { padding: 8px 0 40px; }
.pr-panel { background: #fff; border: 1px solid #dfe6ef; border-radius: 10px; padding: 18px; }
.pr-panel input, .pr-panel button { border: 1px solid #d8e0eb; border-radius: 8px; background: #fff; color: #344159; padding: 8px 12px; font-weight: 700; cursor: pointer; }
.pr-notice { background: #eef4ff; color: #1d4ed8; border-radius: 8px; padding: 9px 12px; font-size: 13px; }
.pr-wrap { overflow-x: auto; }
table { border-collapse: collapse; width: 100%; }
th { background: #f8fafc; color: #69758a; font-size: 11px; text-transform: uppercase; text-align: left; border-bottom: 1px solid #e7ecf2; padding: 11px 10px; }
td { border-bottom: 1px solid #edf1f5; padding: 11px 10px; }
th.num { text-align: right; }
.red { color: #dc2626; font-weight: 800; }
.pr-panel button.wa { background: #16a34a; color: #fff; border-color: #16a34a; padding: 7px 14px; }
.pr-panel button.wa:disabled { background: #cbd5e1; border-color: #cbd5e1; cursor: not-allowed; }
.empty { text-align: center; color: #8490a2; padding: 28px; }
</style>
