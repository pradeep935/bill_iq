<template>
  <Layout :page="page" :title="title">
    <template #topbar-title>
      <div class="bill-page-title">
        <span>INVENTORY MANAGEMENT</span>
        <h1>HSN/SAC Master</h1>
        <p>Maintain your business HSN/SAC records used in Product Master.</p>
      </div>
    </template>

    <div class="business-hsn-page">
      <div class="page-actions">
        <a class="secondary-action" href="/app/inventory/products">Products</a>
        <button type="button" class="primary-action" @click="openForm()">+ Add HSN/SAC</button>
      </div>

      <section class="bill-card">
        <div class="listing-toolbar">
          <input v-model="filters.search" type="search" placeholder="Search my HSN/SAC" @input="loadRecords" />
          <select v-model="filters.code_type" @change="loadRecords"><option value="">HSN + SAC</option><option value="HSN">HSN</option><option value="SAC">SAC</option></select>
          <select v-model="filters.reference_status" @change="loadRecords"><option value="">All Reference</option><option value="matched">Matched with BillIQ</option><option value="modified">Modified from BillIQ</option><option value="manual">Manual / Not Matched</option></select>
          <select v-model="filters.status" @change="loadRecords"><option value="">All Status</option><option value="active">Active</option><option value="inactive">Inactive</option></select>
        </div>

        <div class="table-wrapper">
          <table>
            <thead><tr><th>Code</th><th>Type</th><th>Description</th><th>GST</th><th>Taxability</th><th>Reference Status</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
              <tr v-for="row in records" :key="row.id">
                <td><strong>{{ row.hsn_code }}</strong></td>
                <td>{{ row.code_type }}</td>
                <td class="description-cell">{{ row.description }}</td>
                <td>{{ rateLabel(row.gst_rate) }}</td>
                <td>{{ label(row.taxability) }}</td>
                <td><span :class="['reference-pill', referenceTone(row.reference_status)]">{{ row.reference_status }}</span></td>
                <td><span class="status-pill">{{ label(row.status) }}</span></td>
                <td class="actions-cell"><button type="button" class="crud-action" @click="openForm(row)">Edit</button><button type="button" class="crud-action danger" @click="remove(row)">Delete</button></td>
              </tr>
              <tr v-if="!records.length && !loading"><td colspan="8" class="empty">No business HSN/SAC records found.</td></tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>

    <div v-if="showForm" class="drawer-shell">
      <div class="drawer">
        <div class="drawer-head">
          <div><span>MY HSN/SAC MASTER</span><h2>{{ form.id ? 'Edit HSN/SAC' : 'Add HSN/SAC' }}</h2></div>
          <button type="button" class="secondary-action" @click="closeForm">Close</button>
        </div>

        <section class="form-panel">
          <label class="field-label">Search BillIQ Reference</label>
          <div class="reference-search-row">
            <select v-model="form.code_type" :disabled="detailsEnabled && !manualMode"><option value="HSN">HSN</option><option value="SAC">SAC</option></select>
            <input v-model="referenceSearch" type="search" placeholder="Search HSN/SAC code or description" @input="searchReference" />
          </div>
          <div v-if="referenceSearching" class="subtle-message">Searching...</div>
          <div v-else-if="showNoReference" class="subtle-message">No matching BillIQ reference found. <button v-if="!form.id" type="button" class="inline-action" @click="createManual">Create Manual HSN/SAC</button></div>
          <div v-if="referenceResults.length" class="reference-results">
            <div v-for="ref in referenceResults" :key="ref.id" class="reference-result">
              <div><strong>{{ ref.code_type }} {{ ref.hsn_code }}</strong><span>{{ ref.description }}</span><small>GST: {{ ref.gst_rate === null || ref.gst_rate === undefined ? 'Rate pending' : rateLabel(ref.gst_rate) }} | {{ label(ref.taxability) }}</small></div>
              <button type="button" class="secondary-action" @click="useReference(ref)">Use Reference</button>
            </div>
          </div>
        </section>

        <section v-if="selectedReference || form.id || manualMode" class="selected-reference">
          <strong>{{ referenceStatusText }}</strong>
          <span v-if="selectedReference">BillIQ Reference: {{ selectedReference.code_type }} {{ selectedReference.hsn_code }} | GST {{ selectedReference.gst_rate === null || selectedReference.gst_rate === undefined ? 'Rate pending' : rateLabel(selectedReference.gst_rate) }}</span>
          <span v-if="rateWarning">{{ rateWarning }}</span>
        </section>

        <form v-if="detailsEnabled" class="form-panel form-grid" @submit.prevent="save">
          <h3 class="span-2">Business HSN/SAC Details</h3>
          <label>Code Type<select v-model="form.code_type" required><option value="HSN">HSN</option><option value="SAC">SAC</option></select></label>
          <label>HSN/SAC Code<input v-model="form.hsn_code" required maxlength="12" inputmode="numeric" pattern="[0-9]+" @input="digitsOnly" /></label>
          <label class="span-2">Description<textarea v-model="form.description" required rows="4"></textarea></label>
          <label>GST Rate<select v-model="form.gst_rate" required><option value="">Select GST</option><option v-for="rate in gstRateSlabs" :key="rate.value" :value="Number(rate.value)">{{ rate.label }}</option></select><small v-if="referenceRatePending">BillIQ does not currently have a verified GST rate for this classification.</small></label>
          <label>Cess<input v-model.number="form.cess_rate" type="number" min="0" max="100" step="0.01" /></label>
          <label>Taxability<select v-model="form.taxability" required><option value="taxable">Taxable</option><option value="nil_rated">Nil Rated</option><option value="exempt">Exempt</option><option value="non_gst">Non-GST</option></select></label>
          <label>Status<select v-model="form.status" required><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
          <p v-if="firstError" class="error span-2">{{ firstError }}</p>
          <div class="drawer-footer span-2"><button type="button" class="secondary-action" @click="closeForm">Cancel</button><button type="submit" class="primary-action" :disabled="saving">{{ saving ? 'Saving...' : 'Save HSN/SAC' }}</button></div>
        </form>
      </div>
    </div>
  </Layout>
</template>

<script setup>
import axios from 'axios';
import { computed, onMounted, reactive, ref } from 'vue';
import Layout from '../Layout.vue';

const props = defineProps({
  page: { type: String, default: 'business-hsn-master' },
  title: { type: String, default: 'HSN/SAC Master' },
  gst_rate_slabs: { type: Array, default: () => [] },
});

const records = ref([]);
const loading = ref(false);
const saving = ref(false);
const showForm = ref(false);
const manualMode = ref(false);
const errors = ref({});
const referenceSearch = ref('');
const referenceResults = ref([]);
const referenceSearching = ref(false);
const selectedReference = ref(null);
let timer = null;

const filters = reactive({ search: '', code_type: '', reference_status: '', status: '', per_page: 25 });
const form = reactive(defaults());
const gstRateSlabs = computed(() => props.gst_rate_slabs || []);
const detailsEnabled = computed(() => Boolean(form.id || selectedReference.value || manualMode.value));
const firstError = computed(() => Object.values(errors.value || {})?.[0]?.[0] || '');
const referenceRatePending = computed(() => selectedReference.value && (selectedReference.value.gst_rate === null || selectedReference.value.gst_rate === undefined));
const searchedReference = ref(false);
const showNoReference = computed(() => referenceSearch.value.trim().length >= 2 && searchedReference.value && !referenceSearching.value && !referenceResults.value.length);
const referenceStatusText = computed(() => !form.reference_hsn_master_id ? 'Manual / Not Matched' : (rateWarning.value ? 'Modified from BillIQ' : 'Matched with BillIQ'));
const rateWarning = computed(() => {
  if (!selectedReference.value) return '';
  const changed = Number(selectedReference.value.gst_rate || 0) !== Number(form.gst_rate || 0)
    || Number(selectedReference.value.cess_rate || 0) !== Number(form.cess_rate || 0)
    || String(selectedReference.value.taxability || '') !== String(form.taxability || '')
    || String(selectedReference.value.description || '') !== String(form.description || '');
  return changed ? `Your value differs from the BillIQ reference. BillIQ GST: ${selectedReference.value.gst_rate === null || selectedReference.value.gst_rate === undefined ? 'Rate pending' : rateLabel(selectedReference.value.gst_rate)}. Your GST: ${rateLabel(form.gst_rate)}.` : '';
});

onMounted(loadRecords);

function defaults() {
  return { id: null, code_type: 'HSN', hsn_code: '', description: '', gst_rate: '', cess_rate: 0, taxability: 'taxable', status: 'active', reference_hsn_master_id: null };
}

async function loadRecords() {
  loading.value = true;
  try {
    const { data } = await axios.get('/app/inventory/hsn-master/list', { params: filters });
    records.value = data.data?.data || [];
  } finally {
    loading.value = false;
  }
}

function openForm(row = null) {
  Object.assign(form, defaults(), row || {});
  form.gst_rate = row ? Number(row.gst_rate || 0) : '';
  selectedReference.value = row?.reference_hsn_master_id ? { ...row, gst_rate: row.reference_gst_rate } : null;
  referenceSearch.value = row ? `${row.hsn_code} ${row.description}` : '';
  referenceResults.value = [];
  manualMode.value = Boolean(row && !row.reference_hsn_master_id);
  errors.value = {};
  showForm.value = true;
}

function closeForm() {
  showForm.value = false;
}

function searchReference() {
  clearTimeout(timer);
  timer = setTimeout(async () => {
    const q = referenceSearch.value.trim();
    referenceResults.value = [];
    selectedReference.value = null;
    searchedReference.value = false;
    if (q.length < 2) return;
    referenceSearching.value = true;
    try {
      const { data } = await axios.get('/app/inventory/hsn-master/reference-search', { params: { q, code_type: form.code_type } });
      referenceResults.value = data.data || [];
      searchedReference.value = true;
    } finally {
      referenceSearching.value = false;
    }
  }, 250);
}

function createManual() {
  manualMode.value = true;
  selectedReference.value = null;
  form.reference_hsn_master_id = null;
  if (/^[0-9]+$/.test(referenceSearch.value.trim())) form.hsn_code = referenceSearch.value.trim();
}

function useReference(ref) {
  selectedReference.value = ref;
  manualMode.value = false;
  form.reference_hsn_master_id = ref.id;
  form.code_type = ref.code_type || form.code_type;
  form.hsn_code = ref.hsn_code || '';
  form.description = ref.description || '';
  form.gst_rate = ref.gst_rate === null || ref.gst_rate === undefined ? '' : Number(ref.gst_rate);
  form.cess_rate = Number(ref.cess_rate || 0);
  form.taxability = ref.taxability || 'taxable';
  referenceResults.value = [];
}

function digitsOnly() {
  form.hsn_code = String(form.hsn_code || '').replace(/\D+/g, '');
}

async function save() {
  digitsOnly();
  saving.value = true;
  errors.value = {};
  try {
    const url = form.id ? `/app/inventory/hsn-master/${form.id}` : '/app/inventory/hsn-master';
    form.id ? await axios.put(url, { ...form }) : await axios.post(url, { ...form });
    closeForm();
    await loadRecords();
  } catch (error) {
    errors.value = error.response?.data?.errors || {};
  } finally {
    saving.value = false;
  }
}

async function remove(row) {
  if (!confirm(`Delete ${row.code_type} ${row.hsn_code}?`)) return;
  await axios.delete(`/app/inventory/hsn-master/${row.id}`);
  await loadRecords();
}

const rateLabel = (value) => value === null || value === undefined || value === '' ? 'Rate pending' : `${Number(value || 0).toFixed(2).replace(/\.00$/, '')}%`;
const label = (value) => String(value || '').replace('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
const referenceTone = (value) => String(value || '').startsWith('Matched') ? 'matched' : String(value || '').startsWith('Modified') ? 'modified' : 'manual';
</script>

<style scoped>
.business-hsn-page { display: grid; gap: 18px; }
.page-actions, .drawer-footer { display: flex; justify-content: flex-end; gap: 12px; }
.primary-action, .secondary-action, .crud-action, .inline-action { border: 1px solid #d9e2f2; border-radius: 8px; padding: 10px 16px; font-weight: 800; text-decoration: none; cursor: pointer; }
.primary-action { background: #2f63df; color: #fff; border-color: #2f63df; }
.secondary-action, .crud-action { background: #fff; color: #24324a; }
.inline-action { margin-left: 8px; color: #2f63df; background: #fff; }
.danger { color: #b42318; }
.bill-card, .form-panel, .selected-reference { background: #fff; border: 1px solid #dce5f3; border-radius: 8px; padding: 18px; }
.listing-toolbar { display: grid; grid-template-columns: minmax(220px, 1fr) repeat(3, minmax(140px, 190px)); gap: 12px; margin-bottom: 16px; }
.reference-search-row { display: grid; grid-template-columns: 130px minmax(0, 1fr); gap: 12px; }
input, select, textarea { width: 100%; border: 1px solid #d8e2f1; border-radius: 8px; padding: 12px; font: inherit; min-width: 0; }
.table-wrapper { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; }
th, td { text-align: left; padding: 13px 12px; border-bottom: 1px solid #edf1f7; vertical-align: top; }
th { font-size: 12px; color: #70809a; text-transform: uppercase; }
.description-cell { max-width: 520px; white-space: normal; overflow-wrap: anywhere; }
.actions-cell { white-space: nowrap; }
.status-pill, .reference-pill { display: inline-flex; border-radius: 999px; padding: 5px 9px; font-weight: 800; font-size: 12px; background: #eef4ff; }
.reference-pill.matched { background: #eaf8ef; color: #157347; }
.reference-pill.modified { background: #fff8e8; color: #8a5a00; }
.reference-pill.manual { background: #f3f6fb; color: #526277; }
.empty, .subtle-message { text-align: center; color: #718096; padding: 14px; }
.drawer-shell { position: fixed; inset: 0; background: rgba(11, 20, 36, .48); display: flex; justify-content: flex-end; z-index: 40; }
.drawer { width: min(800px, 100%); height: 100%; overflow-y: auto; background: #f6f9fd; padding: 24px; display: grid; align-content: start; gap: 16px; }
.drawer-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
.drawer-head span { color: #2f63df; font-size: 12px; font-weight: 900; letter-spacing: .12em; }
.drawer-head h2 { margin: 4px 0 0; }
.field-label, label { display: grid; gap: 7px; font-weight: 800; color: #27364f; }
.reference-results { display: grid; gap: 10px; margin-top: 14px; }
.reference-result { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 12px; align-items: center; border: 1px solid #d8e2f1; border-radius: 8px; padding: 12px; }
.reference-result div, .selected-reference { display: grid; gap: 5px; }
.reference-result span, .reference-result small, .selected-reference span, label small { color: #66758f; overflow-wrap: anywhere; }
.form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
.span-2 { grid-column: 1 / -1; }
.error { color: #b42318; font-weight: 800; }
@media (max-width: 760px) {
  .listing-toolbar, .reference-search-row, .form-grid, .reference-result { grid-template-columns: 1fr; }
  .page-actions, .drawer-footer { flex-direction: column; }
  .span-2 { grid-column: auto; }
  .drawer { padding: 16px; }
}
</style>
