<template>
  <div class="gr">
    <div class="gr-bar">
      <label>Return period
        <input v-model="month" type="month" @change="load" />
      </label>
      <button type="button" @click="load">Refresh</button>
      <span class="gr-spacer"></span>
      <a class="gr-dl" :href="url('xlsx')">⬇ Excel (GSTR-1 + 3B)</a>
      <a class="gr-dl alt" :href="url('json')">⬇ GSTR-1 JSON</a>
    </div>

    <p v-if="error" class="gr-warn">{{ error }}</p>
    <p class="gr-hint">Working figures from approved invoices, credit notes and purchases. Please verify in the GST portal / offline tool before filing.</p>

    <template v-if="d">
      <div v-if="d.gstr1.missing_hsn || d.gstr1.odd_rates.length" class="gr-warn">
        <strong>Check before filing:</strong>
        <span v-if="d.gstr1.missing_hsn"> {{ d.gstr1.missing_hsn }} invoice(s) have items without HSN.</span>
        <span v-if="d.gstr1.odd_rates.length"> Non-standard GST rates found: {{ d.gstr1.odd_rates.join('%, ') }}%.</span>
      </div>

      <h3>GSTR-1 summary</h3>
      <div class="gr-cards">
        <div class="c"><span>Invoices</span><strong>{{ d.gstr1.counts.invoices }}</strong></div>
        <div class="c"><span>B2B (GST customers)</span><strong>{{ d.gstr1.counts.b2b }}</strong></div>
        <div class="c"><span>B2C Large</span><strong>{{ d.gstr1.counts.b2cl }}</strong></div>
        <div class="c"><span>B2C Small rows</span><strong>{{ d.gstr1.counts.b2cs_rows }}</strong></div>
        <div class="c"><span>Credit notes</span><strong>{{ d.gstr1.counts.credit_notes }}</strong></div>
      </div>

      <h3>GSTR-3B summary</h3>
      <table class="gr-table">
        <thead><tr><th>Particular</th><th class="r">Taxable</th><th class="r">IGST</th><th class="r">CGST</th><th class="r">SGST</th><th class="r">Cess</th></tr></thead>
        <tbody>
          <tr v-for="row in rows" :key="row.label" :class="row.cls">
            <td>{{ row.label }}</td><td class="r">{{ f(row.v.taxable) }}</td><td class="r">{{ f(row.v.igst) }}</td><td class="r">{{ f(row.v.cgst) }}</td><td class="r">{{ f(row.v.sgst) }}</td><td class="r">{{ f(row.v.cess) }}</td>
          </tr>
        </tbody>
      </table>
      <p class="gr-hint">Negative net payable means input credit is higher than output tax and can be carried forward.</p>
    </template>
    <p v-else-if="loading" class="gr-hint">Loading…</p>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';

const today = new Date();
const prev = new Date(today.getFullYear(), today.getMonth() - 1, 1);
const month = ref(`${prev.getFullYear()}-${String(prev.getMonth() + 1).padStart(2, '0')}`);
const d = ref(null);
const loading = ref(false);
const error = ref('');

const range = computed(() => {
  const [y, m] = month.value.split('-').map(Number);
  const last = new Date(y, m, 0).getDate();
  return { from: `${month.value}-01`, to: `${month.value}-${String(last).padStart(2, '0')}` };
});
const url = (format) => `/app/accounting/gst-returns/export?format=${format}&from=${range.value.from}&to=${range.value.to}`;
const f = (v) => Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const rows = computed(() => d.value ? [
  { label: '3.1(a) Outward taxable supplies', v: d.value.gstr3b.outward },
  { label: 'Less: Credit notes', v: d.value.gstr3b.credit_notes },
  { label: 'Net outward tax liability', v: d.value.gstr3b.net_outward, cls: 'bold' },
  { label: '4(A) ITC available (purchases)', v: d.value.gstr3b.itc },
  { label: 'Net tax payable', v: { taxable: 0, ...d.value.gstr3b.net_payable }, cls: 'bold total' },
] : []);

async function load() {
  loading.value = true; error.value = '';
  try {
    d.value = (await axios.get('/app/accounting/gst-returns/data', { params: range.value })).data;
  } catch (e) { error.value = 'Could not load GST return data.'; d.value = null; } finally { loading.value = false; }
}
onMounted(load);
</script>

<style scoped>
.gr { padding: 4px 2px 20px; }
.gr h3 { margin: 18px 0 8px; font-size: 14px; color: #142139; }
.gr-bar { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
.gr-bar label { display: flex; flex-direction: column; gap: 4px; font-size: 11px; font-weight: 800; color: #69758a; }
.gr-spacer { flex: 1; }
.gr-dl { background: #2563eb; color: #fff; text-decoration: none; border-radius: 8px; padding: 10px 14px; font-size: 12px; font-weight: 800; }
.gr-dl.alt { background: #142139; }
.gr-hint { color: #758197; font-size: 12px; margin: 8px 0; }
.gr-warn { background: #fff7ed; color: #b45309; border-radius: 8px; padding: 9px 12px; font-size: 12px; margin: 8px 0; }
.gr-cards { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px; }
.gr-cards .c { background: #fff; border: 1px solid #dfe6ef; border-left: 4px solid #2563eb; border-radius: 8px; padding: 10px 12px; }
.gr-cards span { display: block; font-size: 10px; font-weight: 800; color: #7f8da4; margin-bottom: 4px; }
.gr-cards strong { font-size: 20px; color: #142139; }
.gr-table { border-collapse: collapse; width: 100%; background: #fff; border: 1px solid #edf1f5; }
.gr-table th, .gr-table td { border-bottom: 1px solid #edf1f5; padding: 10px; font-size: 12px; text-align: left; }
.gr-table th { background: #f8fafc; color: #69758a; font-size: 10px; text-transform: uppercase; }
.r { text-align: right !important; } .bold td { font-weight: 800; } .total td { background: #eef4ff; }
@media (max-width: 800px) { .gr-cards { grid-template-columns: repeat(2, 1fr); } }
.gr{max-width:100%;overflow-x:auto}
</style>
