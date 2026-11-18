<template>
  <Layout :page="page" :title="title">
    <template #topbar-title><h1 class="pr-title">Payment Reminders</h1></template>
    <section class="pr">
      <div class="pr-cards">
        <div class="pr-card"><span>Customers with dues</span><strong>{{ totals.customers }}</strong></div>
        <div class="pr-card"><span>Total receivable</span><strong>{{ money(totals.total_due) }}</strong></div>
        <div class="pr-card warn"><span>Overdue amount</span><strong>{{ money(totals.overdue_due) }}</strong></div>
      </div>

      <div class="pr-bar">
        <input v-model="search" type="search" placeholder="Search customer or mobile…" />
        <label><input v-model="overdueOnly" type="checkbox" /> Overdue only</label>
        <button type="button" @click="load">Refresh</button>
      </div>
      <p v-if="notice" class="pr-notice">{{ notice }}</p>

      <div class="pr-wrap">
        <table class="pr-table">
          <thead><tr><th>Customer</th><th>Mobile</th><th class="r">Invoices</th><th class="r">Total due</th><th class="r">Overdue</th><th class="r">Oldest (days)</th><th></th></tr></thead>
          <tbody>
            <tr v-for="r in filtered" :key="r.customer_id">
              <td><strong>{{ r.name }}</strong></td>
              <td>{{ r.mobile || '—' }}</td>
              <td class="r">{{ r.invoices }}</td>
              <td class="r">{{ money(r.total_due) }}</td>
              <td class="r" :class="{ red: r.overdue_due > 0 }">{{ money(r.overdue_due) }}</td>
              <td class="r">{{ r.oldest_overdue_days || '—' }}</td>
              <td class="r">
                <button type="button" class="wa" :disabled="!r.has_whatsapp || sending === r.customer_id" :title="r.has_whatsapp ? 'Send WhatsApp reminder' : 'No valid mobile number'" @click="remind(r)">
                  {{ sending === r.customer_id ? '…' : 'WhatsApp reminder' }}
                </button>
              </td>
            </tr>
            <tr v-if="!filtered.length"><td colspan="7" class="empty">{{ loading ? 'Loading…' : 'No pending payments. 🎉' }}</td></tr>
          </tbody>
        </table>
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
const overdueOnly = ref(false);
const sending = ref(null);
const notice = ref('');

const money = (v) => 'Rs. ' + Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const filtered = computed(() => rows.value.filter((r) => {
  if (overdueOnly.value && !(r.overdue_due > 0)) return false;
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
.pr-cards { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
.pr-card { background: #fff; border: 1px solid #dfe6ef; border-left: 4px solid #2563eb; border-radius: 8px; padding: 13px 14px; }
.pr-card.warn { border-left-color: #ef4444; }
.pr-card span { display: block; color: #7f8da4; font-size: 11px; font-weight: 800; margin-bottom: 6px; }
.pr-card strong { font-size: 22px; color: #142139; }
.pr-bar { display: flex; gap: 12px; align-items: center; margin-bottom: 12px; flex-wrap: wrap; }
.pr-bar input[type=search] { flex: 1; min-width: 220px; border: 1px solid #cfd6e0; border-radius: 8px; padding: 9px 12px; }
.pr-bar label { font-size: 13px; display: flex; gap: 6px; align-items: center; }
.pr-bar button { background: #fff; border: 1px solid #d8e0eb; border-radius: 8px; padding: 9px 14px; font-weight: 700; cursor: pointer; }
.pr-notice { background: #eef4ff; color: #1d4ed8; border-radius: 8px; padding: 9px 12px; font-size: 13px; }
.pr-wrap { background: #fff; border: 1px solid #dfe6ef; border-radius: 8px; overflow: auto; }
.pr-table { border-collapse: collapse; width: 100%; min-width: 760px; }
.pr-table th, .pr-table td { border-bottom: 1px solid #edf1f5; padding: 11px 12px; font-size: 13px; text-align: left; }
.pr-table th { background: #f8fafc; color: #69758a; font-size: 10px; letter-spacing: .04em; text-transform: uppercase; }
.r { text-align: right !important; } .red { color: #dc2626; font-weight: 800; }
.wa { background: #16a34a; color: #fff; border: 0; border-radius: 8px; padding: 7px 12px; font-weight: 700; font-size: 12px; cursor: pointer; }
.wa:disabled { background: #cbd5e1; cursor: not-allowed; }
.empty { text-align: center !important; color: #8490a2; padding: 28px !important; }
@media (max-width: 700px) { .pr-cards { grid-template-columns: 1fr; } }
</style>
