<script setup>
import { computed, ref } from 'vue';
import axios from 'axios';
import Layout from '../Layout.vue';

const props = defineProps({
  page: String,
  title: String,
  company: { type: Object, default: () => ({}) },
  sourceTypes: { type: Array, default: () => [] },
  batches: { type: Array, default: () => [] },
});

const sourceType = ref(props.sourceTypes[0]?.key || 'gstr1_json');
const expectedReturnPeriod = ref('082026');
const file = ref(null);
const batches = ref(props.batches);
const selected = ref(null);
const activeSection = ref('b2b');
const busy = ref(false);
const message = ref('');
const errors = ref({});

const cards = computed(() => [
  { label: 'Import Batches', value: batches.value.length },
  { label: 'Blocked', value: batches.value.filter((batch) => batch.status === 'validation_failed').length },
  { label: 'Ready / Approved', value: batches.value.filter((batch) => ['preview_ready', 'approved_for_reconciliation', 'committed_as_evidence'].includes(batch.status)).length },
]);

const upload = async () => {
  if (!file.value) return;
  busy.value = true;
  errors.value = {};
  message.value = '';
  const form = new FormData();
  form.append('source_type', sourceType.value);
  if (expectedReturnPeriod.value) form.append('expected_return_period', expectedReturnPeriod.value);
  form.append('file', file.value);
  try {
    const response = await axios.post('/app/accounting/gst-imports', form, { headers: { 'Content-Type': 'multipart/form-data' } });
    batches.value = response.data.batches;
    selected.value = response.data.batch;
    message.value = response.data.message;
  } catch (error) {
    errors.value = error.response?.data?.errors || { general: [error.response?.data?.message || 'Upload failed.'] };
  } finally {
    busy.value = false;
  }
};

const loadBatch = async (batch) => {
  busy.value = true;
  try {
    selected.value = (await axios.get(`/app/accounting/gst-imports/${batch.id}`)).data;
    activeSection.value = 'b2b';
  } finally {
    busy.value = false;
  }
};

const action = async (name) => {
  if (!selected.value) return;
  busy.value = true;
  errors.value = {};
  message.value = '';
  try {
    const response = await axios.post(`/app/accounting/gst-imports/${selected.value.id}/${name}`);
    selected.value = response.data.batch;
    batches.value = response.data.batches;
    message.value = response.data.message;
  } catch (error) {
    errors.value = error.response?.data?.errors || { general: [error.response?.data?.message || `${name} failed.`] };
  } finally {
    busy.value = false;
  }
};

const money = (value) => Number(value || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const sectionDefinitions = [
  {
    key: 'b2b',
    label: 'B2B Sales',
    sections: ['gstr1_b2b_invoice_evidence'],
    columns: ['document', 'date', 'gstin', 'taxable', 'igst', 'total'],
  },
  {
    key: 'b2c',
    label: 'B2C Sales',
    sections: ['gstr1_b2cl_invoice_evidence', 'gstr1_b2cs_summary_evidence'],
    columns: ['document', 'date', 'taxable', 'igst', 'total'],
  },
  {
    key: 'notes',
    label: 'Credit/Debit Notes',
    sections: ['gstr1_credit_debit_note_evidence', 'gstr1_unregistered_credit_debit_note_evidence'],
    columns: ['document', 'date', 'gstin', 'taxable', 'igst', 'total'],
  },
  {
    key: 'hsn',
    label: 'HSN Summary',
    sections: ['gstr1_hsn_summary_evidence'],
    columns: ['hsn', 'description', 'uqc', 'qty', 'rate', 'taxable', 'igst', 'cgst', 'sgst', 'cess', 'total'],
  },
  {
    key: 'documents',
    label: 'Documents Issued',
    sections: ['gstr1_document_issue_evidence'],
    columns: ['docType', 'from', 'to', 'totalIssued', 'cancelled', 'netIssued'],
  },
  {
    key: 'exports',
    label: 'Exports',
    sections: ['gstr1_export_invoice_evidence'],
    columns: ['document', 'date', 'taxable', 'igst', 'total'],
  },
  {
    key: 'advances',
    label: 'Advances / Adjustments',
    sections: ['gstr1_advance_tax_evidence', 'gstr1_advance_adjustment_evidence'],
    columns: ['section', 'taxable', 'igst', 'cgst', 'sgst', 'cess', 'total'],
  },
  {
    key: 'nil',
    label: 'Nil / Exempt',
    sections: ['gstr1_nil_supply_evidence'],
    columns: ['section', 'taxable', 'total'],
  },
];

const rowPayload = (row) => row?.payload?.document || row?.payload?.invoice || row?.payload || {};
const activeDefinition = computed(() => sectionDefinitions.find((section) => section.key === activeSection.value) || sectionDefinitions[0]);
const sectionRows = (definition) => (selected.value?.rows || []).filter((row) => definition.sections.includes(row.section));
const activeRows = computed(() => sectionRows(activeDefinition.value));
const sectionTotals = (rows) => rows.reduce((totals, row) => {
  totals.taxable += Number(row.taxable_value || 0);
  totals.igst += Number(row.igst_amount || 0);
  totals.cgst += Number(row.cgst_amount || 0);
  totals.sgst += Number(row.sgst_amount || 0);
  totals.cess += Number(row.cess_amount || 0);
  totals.total += Number(row.total_value || 0);
  return totals;
}, { taxable: 0, igst: 0, cgst: 0, sgst: 0, cess: 0, total: 0 });
const activeTotals = computed(() => sectionTotals(activeRows.value));
const docType = (row) => row.payload?.doc_num || rowPayload(row)?.doc_num || '-';
</script>

<template>
  <Layout :page="page" :title="title">
    <template #topbar-title>
      <div class="bill-page-title">
        <span>GST IMPORTS</span>
        <h1>{{ title }}</h1>
        <p>Upload, validate, preview and reconcile GST files without posting business transactions.</p>
      </div>
    </template>

    <section class="import-shell">
      <header class="company-band">
        <div><span>Company</span><strong>{{ company.name }}</strong></div>
        <div><span>GSTIN</span><strong>{{ company.gstin || 'Not configured' }}</strong></div>
        <div><span>State</span><strong>{{ company.state || '-' }}</strong></div>
      </header>

      <section class="cards">
        <article v-for="card in cards" :key="card.label"><span>{{ card.label }}</span><strong>{{ card.value }}</strong></article>
      </section>

      <section class="upload-panel">
        <select v-model="sourceType"><option v-for="type in sourceTypes" :key="type.key" :value="type.key">{{ type.label }}</option></select>
        <input v-model="expectedReturnPeriod" type="text" maxlength="6" placeholder="MMYYYY" aria-label="Expected filing period" />
        <input type="file" accept=".json,.xlsx,.xls,.csv,.txt" @change="file = $event.target.files[0]" />
        <button type="button" :disabled="busy || !file" @click="upload">{{ busy ? 'Working...' : 'Upload & Preview' }}</button>
      </section>

      <div v-if="message" class="success">{{ message }}</div>
      <div v-if="Object.keys(errors).length" class="errors"><span v-for="(messages, field) in errors" :key="field">{{ messages[0] }}</span></div>

      <section class="workspace-grid">
        <aside class="history-panel">
          <h2>Import History</h2>
          <button v-for="batch in batches" :key="batch.id" type="button" :class="{ active: selected?.id === batch.id }" @click="loadBatch(batch)">
            <strong>{{ batch.original_filename }}</strong>
            <span>{{ batch.source_type }} · {{ batch.status }}</span>
            <small>{{ batch.source_gstin || 'GSTIN not detected' }} · {{ batch.return_period || 'Period pending' }}</small>
          </button>
          <p v-if="!batches.length">No GST files uploaded for this company yet.</p>
        </aside>

        <main class="preview-panel">
          <template v-if="selected">
            <header>
              <div>
                <h2>{{ selected.original_filename }}</h2>
                <p>{{ selected.source_type }} · {{ selected.status }}</p>
              </div>
              <div class="actions">
                <button type="button" :disabled="busy || selected.status === 'validation_failed'" @click="action('approve')">Approve Evidence</button>
                <button type="button" :disabled="busy || selected.status !== 'approved_for_reconciliation'" @click="action('commit')">Commit Evidence</button>
              </div>
            </header>

            <section class="validation-grid">
              <article><span>Source GSTIN</span><strong>{{ selected.source_gstin || '-' }}</strong></article>
              <article><span>Return Period</span><strong>{{ selected.return_period || '-' }}</strong></article>
              <article><span>Taxable</span><strong>{{ money(selected.totals?.taxable_value) }}</strong></article>
              <article><span>Total Value</span><strong>{{ money(selected.totals?.total_value) }}</strong></article>
            </section>

            <section class="action-row">
              <button type="button" :disabled="busy || selected.status === 'validation_failed'" @click="action('approve')">Approve Evidence</button>
              <button type="button" :disabled="busy || selected.status !== 'approved_for_reconciliation'" @click="action('commit')">Commit Evidence</button>
            </section>

            <div v-if="selected.validation?.errors?.length" class="errors"><span v-for="item in selected.validation.errors" :key="item">{{ item }}</span></div>
            <div v-if="selected.validation?.warnings?.length" class="warnings"><span v-for="item in selected.validation.warnings" :key="item">{{ item }}</span></div>

            <section class="recon">
              <h3>Reconciliation</h3>
              <div v-for="item in selected.reconciliation || []" :key="item.category" class="recon-row">
                <span>{{ item.category }}</span>
                <strong>{{ item.status }}</strong>
                <em>{{ money(item.difference_amount) }}</em>
              </div>
            </section>

            <section v-if="selected.source_type === 'gstr1_json'" class="gstr1-details">
              <header class="section-header">
                <div>
                  <h3>GSTR-1 Details</h3>
                  <p>GST portal style sections from the saved source file. Missing sections are shown as empty evidence, not inferred transactions.</p>
                </div>
                <strong>{{ selected.return_period || '-' }}</strong>
              </header>

              <nav class="section-tabs" aria-label="GSTR-1 sections">
                <button
                  v-for="section in sectionDefinitions"
                  :key="section.key"
                  type="button"
                  :class="{ active: activeSection === section.key }"
                  @click="activeSection = section.key"
                >
                  <span>{{ section.label }}</span>
                  <strong>{{ sectionRows(section).length }}</strong>
                </button>
              </nav>

              <section class="section-summary">
                <article><span>Records</span><strong>{{ activeRows.length }}</strong></article>
                <article><span>Taxable</span><strong>{{ money(activeTotals.taxable) }}</strong></article>
                <article><span>IGST</span><strong>{{ money(activeTotals.igst) }}</strong></article>
                <article><span>Total</span><strong>{{ money(activeTotals.total) }}</strong></article>
              </section>

              <div v-if="!activeRows.length" class="no-source-records">No records in source file</div>

              <div v-else class="table-wrap gst-section-table">
                <table>
                  <thead>
                    <tr>
                      <template v-if="activeDefinition.key === 'hsn'">
                        <th>HSN/SAC</th><th>Description</th><th>UQC</th><th>Qty</th><th>Rate</th><th>Taxable</th><th>IGST</th><th>CGST</th><th>SGST</th><th>Cess</th><th>Total</th>
                      </template>
                      <template v-else-if="activeDefinition.key === 'documents'">
                        <th>Document Type</th><th>From</th><th>To</th><th>Total Issued</th><th>Cancelled</th><th>Net Issued</th>
                      </template>
                      <template v-else>
                        <th>Section</th><th>GSTIN</th><th>Document</th><th>Date</th><th>Taxable</th><th>IGST</th><th>CGST</th><th>SGST</th><th>Cess</th><th>Total</th>
                      </template>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="row in activeRows" :key="row.id">
                      <template v-if="activeDefinition.key === 'hsn'">
                        <td>{{ row.hsn_code || '-' }}</td>
                        <td>{{ rowPayload(row).user_desc || rowPayload(row).desc || '-' }}</td>
                        <td>{{ rowPayload(row).uqc || '-' }}</td>
                        <td>{{ rowPayload(row).qty ?? '-' }}</td>
                        <td>{{ rowPayload(row).rt ?? '-' }}</td>
                        <td>{{ money(row.taxable_value) }}</td>
                        <td>{{ money(row.igst_amount) }}</td>
                        <td>{{ money(row.cgst_amount) }}</td>
                        <td>{{ money(row.sgst_amount) }}</td>
                        <td>{{ money(row.cess_amount) }}</td>
                        <td>{{ money(row.total_value) }}</td>
                      </template>
                      <template v-else-if="activeDefinition.key === 'documents'">
                        <td>{{ docType(row) }}</td>
                        <td>{{ rowPayload(row).from || '-' }}</td>
                        <td>{{ rowPayload(row).to || '-' }}</td>
                        <td>{{ rowPayload(row).totnum ?? '-' }}</td>
                        <td>{{ rowPayload(row).cancel ?? '-' }}</td>
                        <td>{{ rowPayload(row).net_issue ?? '-' }}</td>
                      </template>
                      <template v-else>
                        <td>{{ row.section }}</td>
                        <td>{{ row.counterparty_gstin || '-' }}</td>
                        <td>{{ row.document_number || '-' }}</td>
                        <td>{{ row.document_date || '-' }}</td>
                        <td>{{ money(row.taxable_value) }}</td>
                        <td>{{ money(row.igst_amount) }}</td>
                        <td>{{ money(row.cgst_amount) }}</td>
                        <td>{{ money(row.sgst_amount) }}</td>
                        <td>{{ money(row.cess_amount) }}</td>
                        <td>{{ money(row.total_value) }}</td>
                      </template>
                    </tr>
                  </tbody>
                </table>
              </div>
            </section>

            <div class="table-wrap">
              <table>
                <thead><tr><th>#</th><th>Section</th><th>GSTIN</th><th>Document</th><th>HSN</th><th>Taxable</th><th>IGST</th><th>Total</th></tr></thead>
                <tbody>
                  <tr v-for="row in selected.rows || []" :key="row.id">
                    <td>{{ row.row_number }}</td>
                    <td>{{ row.section }}</td>
                    <td>{{ row.counterparty_gstin || '-' }}</td>
                    <td>{{ row.document_number || '-' }}</td>
                    <td>{{ row.hsn_code || '-' }}</td>
                    <td>{{ money(row.taxable_value) }}</td>
                    <td>{{ money(row.igst_amount) }}</td>
                    <td>{{ money(row.total_value) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>
          <div v-else class="empty">Select or upload a GST file to preview validation and reconciliation evidence.</div>
        </main>
      </section>
    </section>
  </Layout>
</template>

<style scoped>
.import-shell{display:grid;gap:16px}.company-band,.cards,.upload-panel,.workspace-grid{display:grid;gap:12px}.company-band{grid-template-columns:2fr 1fr 1fr;background:#fff;border:1px solid #dfe6ef;border-radius:8px;padding:14px}.company-band span,.cards span,.validation-grid span,.section-summary span{display:block;color:#6b778c;font-size:11px;font-weight:850}.company-band strong,.cards strong,.validation-grid strong,.section-summary strong{display:block;color:#142139;margin-top:4px}.cards{grid-template-columns:repeat(3,minmax(0,1fr))}.cards article,.upload-panel,.history-panel,.preview-panel{background:#fff;border:1px solid #dfe6ef;border-radius:8px;padding:14px}.cards strong{font-size:24px}.upload-panel{grid-template-columns:220px 120px 1fr auto;align-items:center}.upload-panel select,.upload-panel input,.upload-panel button,.actions button,.action-row button{min-height:40px;border:1px solid #d8e0eb;border-radius:8px;padding:8px 10px}.upload-panel button,.actions button,.action-row button{background:#2457d6;color:#fff;font-weight:850}.upload-panel button:disabled,.actions button:disabled,.action-row button:disabled{opacity:.6}.workspace-grid{grid-template-columns:330px 1fr}.history-panel{display:grid;align-content:start;gap:8px}.history-panel h2,.preview-panel h2{margin:0;color:#142139;font-size:18px}.history-panel button{display:grid;gap:3px;text-align:left;background:#fff;border:1px solid #e4eaf2;border-radius:8px;padding:10px}.history-panel button.active{border-color:#2457d6;background:#f5f8ff}.history-panel span,.history-panel small,.preview-panel p{color:#758197;font-size:12px}.preview-panel header{display:flex;justify-content:space-between;gap:12px;margin-bottom:14px}.actions,.action-row{display:flex;gap:8px}.action-row{justify-content:flex-end;margin:-2px 0 12px}.validation-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:12px}.validation-grid article{border:1px solid #edf1f5;border-radius:8px;padding:10px}.warnings,.errors,.success{display:grid;gap:5px;border-radius:8px;padding:10px;font-size:12px;font-weight:750}.warnings{background:#fffbeb;border:1px solid #fde68a;color:#92400e}.errors{background:#fff1f2;border:1px solid #fecdd3;color:#9f1239}.success{background:#ecfdf3;border:1px solid #bbf7d0;color:#166534}.recon{display:grid;gap:8px;margin:12px 0}.recon h3,.gstr1-details h3{margin:0;color:#142139}.recon-row{display:grid;grid-template-columns:1fr auto auto;gap:10px;border:1px solid #edf1f5;border-radius:8px;padding:9px}.recon-row span{text-transform:capitalize}.gstr1-details{display:grid;gap:12px;margin:14px 0 18px}.section-header{align-items:start;border-top:1px solid #edf1f5;padding-top:14px}.section-header strong{background:#eef4ff;border:1px solid #c7d7fe;border-radius:999px;color:#2457d6;padding:6px 10px}.section-tabs{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}.section-tabs button{display:flex;align-items:center;justify-content:space-between;gap:8px;background:#fff;border:1px solid #dfe6ef;border-radius:8px;color:#142139;min-height:42px;padding:8px 10px;text-align:left}.section-tabs button.active{border-color:#2457d6;background:#f5f8ff;color:#173ea5}.section-tabs strong{font-size:12px}.section-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.section-summary article{border:1px solid #edf1f5;border-radius:8px;padding:10px}.no-source-records{border:1px dashed #ccd6e3;border-radius:8px;color:#6b778c;display:grid;font-weight:800;min-height:96px;place-items:center}.gst-section-table table{min-width:1040px}.table-wrap{overflow:auto;border:1px solid #edf1f5;border-radius:8px}table{border-collapse:collapse;min-width:920px;width:100%}th,td{border-bottom:1px solid #edf1f5;font-size:12px;padding:10px;text-align:left;vertical-align:top}th{background:#f8fafc;color:#667085;text-transform:uppercase;font-size:10px}.empty{display:grid;place-items:center;min-height:320px;color:#758197;border:1px dashed #d8e0eb;border-radius:8px}@media(max-width:960px){.company-band,.cards,.upload-panel,.workspace-grid,.validation-grid,.section-summary{grid-template-columns:1fr}.section-tabs{grid-template-columns:1fr 1fr}.preview-panel header{flex-direction:column}.actions,.action-row{flex-wrap:wrap}}@media(max-width:560px){.section-tabs{grid-template-columns:1fr}.section-tabs button{min-height:38px}}
</style>
