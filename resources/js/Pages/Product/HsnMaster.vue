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

    <Transition name="product-drawer">
      <div v-if="showForm" class="product-drawer-wrapper">
        <div class="product-drawer-backdrop" @click="closeForm"></div>
        <aside class="product-drawer-panel">
          <header class="product-drawer-header">
            <div class="drawer-heading">
              <div class="drawer-heading-icon">HSN</div>
              <div><span class="drawer-eyebrow">MY HSN/SAC MASTER</span><h2>{{ form.id ? 'Edit HSN/SAC' : 'Add HSN/SAC' }}</h2><p>Search BillIQ reference, then save your business-owned HSN/SAC.</p></div>
            </div>
            <button type="button" class="drawer-close-button" @click="closeForm">x</button>
          </header>

          <nav class="product-tabs">
            <button type="button" :class="{ active: activeDrawerTab === 'reference' }" @click="activeDrawerTab = 'reference'">Reference</button>
            <button type="button" :disabled="!detailsEnabled" :class="{ active: activeDrawerTab === 'details' }" @click="activeDrawerTab = 'details'">Business Details</button>
          </nav>

          <main class="product-drawer-content">
            <section v-show="activeDrawerTab === 'reference'" class="product-section">
              <div class="section-header">
                <div class="section-number">01</div>
                <div><h3>Search BillIQ Reference</h3><p>Search HSN/SAC code or description from the global reference master.</p></div>
              </div>
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

            <section v-show="activeDrawerTab === 'details' && detailsEnabled" class="product-section">
              <div class="section-header">
                <div class="section-number">02</div>
                <div><h3>Business HSN/SAC Details</h3><p>These values belong to this business and are used in Product Master.</p></div>
              </div>
              <div v-if="selectedReference || form.id || manualMode" class="selected-reference">
                <strong>{{ referenceStatusText }}</strong>
                <span v-if="selectedReference">BillIQ Reference: {{ selectedReference.code_type }} {{ selectedReference.hsn_code }} | GST {{ selectedReference.gst_rate === null || selectedReference.gst_rate === undefined ? 'Rate pending' : rateLabel(selectedReference.gst_rate) }}</span>
                <span v-if="rateWarning">{{ rateWarning }}</span>
              </div>
              <form class="form-grid" @submit.prevent="save">
                <label class="product-field">Code Type<select v-model="form.code_type" required><option value="HSN">HSN</option><option value="SAC">SAC</option></select></label>
                <label class="product-field">HSN/SAC Code<input v-model="form.hsn_code" required maxlength="12" inputmode="numeric" pattern="[0-9]+" @input="digitsOnly" /></label>
                <label class="product-field span-2">Description<textarea v-model="form.description" required rows="4"></textarea></label>
                <label class="product-field">GST Rate<select v-model="form.gst_rate" required><option value="">Select GST</option><option v-for="rate in gstRateSlabs" :key="rate.value" :value="Number(rate.value)">{{ rate.label }}</option></select><small v-if="referenceRatePending">BillIQ does not currently have a verified GST rate for this classification.</small></label>
                <label class="product-field">Cess<input v-model.number="form.cess_rate" type="number" min="0" max="100" step="0.01" /></label>
                <label class="product-field">Taxability<select v-model="form.taxability" required><option value="taxable">Taxable</option><option value="nil_rated">Nil Rated</option><option value="exempt">Exempt</option><option value="non_gst">Non-GST</option></select></label>
                <label class="product-field">Status<select v-model="form.status" required><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
                <p v-if="firstError" class="error span-2">{{ firstError }}</p>
              </form>
            </section>

            <section v-show="activeDrawerTab === 'details' && !detailsEnabled" class="product-section empty-guidance">
              <div class="section-header">
                <div class="section-number">02</div>
                <div><h3>Business HSN/SAC Details</h3><p>Select a BillIQ reference or create a manual HSN/SAC to continue.</p></div>
              </div>
              <div class="field-help">
                <span class="help-icon">i</span>
                <span>Your business master stays separate from the BillIQ reference master. Products will search only the HSN/SAC records saved here.</span>
              </div>
            </section>
          </main>

          <footer class="product-drawer-footer">
            <div class="footer-help">Fields marked with required validation must be completed.</div>
            <div class="footer-actions"><button type="button" class="secondary-action" @click="closeForm">Cancel</button><button type="button" class="primary-action" :disabled="saving || !detailsEnabled" @click="save">{{ saving ? 'Saving...' : 'Save HSN/SAC' }}</button></div>
          </footer>
        </aside>
      </div>
    </Transition>
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
const activeDrawerTab = ref('reference');
const errors = ref({});
const referenceSearch = ref('');
const referenceResults = ref([]);
const referenceSearching = ref(false);
const selectedReference = ref(null);
let timer = null;

const filters = reactive({ search: '', code_type: '', reference_status: '', status: 'active', per_page: 25 });
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
  activeDrawerTab.value = row ? 'details' : 'reference';
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
  activeDrawerTab.value = 'details';
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
  activeDrawerTab.value = 'details';
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
  records.value = records.value.filter((record) => record.id !== row.id);
  await loadRecords();
}

const rateLabel = (value) => value === null || value === undefined || value === '' ? 'Rate pending' : `${Number(value || 0).toFixed(2).replace(/\.00$/, '')}%`;
const label = (value) => String(value || '').replace('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
const referenceTone = (value) => String(value || '').startsWith('Matched') ? 'matched' : String(value || '').startsWith('Modified') ? 'modified' : 'manual';
</script>

<style scoped>
.business-hsn-page { display: grid; gap: 18px; }
.page-actions { display: flex; justify-content: flex-end; gap: 12px; }
.primary-action, .secondary-action, .crud-action, .inline-action { border: 1px solid #d9e2f2; border-radius: 8px; padding: 10px 16px; font-weight: 800; text-decoration: none; cursor: pointer; }
.primary-action { background: #2f63df; color: #fff; border-color: #2f63df; }
.secondary-action, .crud-action { background: #fff; color: #24324a; }
.inline-action { margin-left: 8px; color: #2f63df; background: #fff; }
.danger { color: #b42318; }
.bill-card, .product-section, .selected-reference { background: #fff; border: 1px solid #dce5f3; border-radius: 8px; padding: 18px; }
.listing-toolbar { display: grid; grid-template-columns: minmax(220px, 1fr) repeat(3, minmax(140px, 190px)); gap: 12px; margin-bottom: 16px; }
.reference-search-row { display: grid; grid-template-columns: 130px minmax(0, 1fr); gap: 12px; }
input, select, textarea { width: 100%; min-width: 0; min-height: 44px; padding: 10px 12px; color: #17233b; background: #fff; border: 1px solid #d8e0eb; border-radius: 9px; outline: none; font-size: 13px; font-weight: 500; }
textarea { min-height: 82px; resize: vertical; line-height: 1.45; }
input::placeholder { color: #a0a9b8; }
input:focus, select:focus, textarea:focus { border-color: #2457d6; box-shadow: 0 0 0 3px rgba(36, 87, 214, .1); }
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
.product-drawer-wrapper { position: fixed; inset: 0; z-index: 9999; }
.product-drawer-backdrop { position: absolute; inset: 0; background: rgba(5, 18, 38, .62); backdrop-filter: blur(3px); }
.product-drawer-panel { position: absolute; top: 0; right: 0; width: min(960px, 100%); height: 100vh; display: flex; flex-direction: column; background: #f4f7fb; box-shadow: -24px 0 60px rgba(7, 25, 51, .22); }
.product-drawer-header { min-height: 96px; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; padding: 19px 28px; background: #fff; border-bottom: 1px solid #e3e9f2; }
.drawer-heading { display: flex; align-items: center; gap: 15px; }
.drawer-heading-icon { width: 48px; height: 48px; display: grid; place-items: center; flex-shrink: 0; color: #2457d6; background: linear-gradient(145deg, #edf3ff, #dce7ff); border: 1px solid #d4e1ff; border-radius: 14px; font-size: 11px; font-weight: 900; }
.drawer-eyebrow { display: block; margin-bottom: 2px; color: #2457d6; font-size: 10px; font-weight: 800; letter-spacing: 1.5px; }
.drawer-heading h2 { margin: 0; color: #101c34; font-size: 22px; font-weight: 800; line-height: 1.25; }
.drawer-heading p { margin: 4px 0 0; color: #738098; font-size: 12px; }
.drawer-close-button { width: 40px; height: 40px; display: grid; place-items: center; padding: 0; color: #536078; background: #f4f6fa; border: 1px solid #dfe5ee; border-radius: 11px; font-size: 25px; font-weight: 300; line-height: 1; cursor: pointer; }
.product-tabs { display: flex; gap: 7px; padding: 12px 28px; overflow-x: auto; background: #fff; border-bottom: 1px solid #e3e9f2; }
.product-tabs button { min-height: 34px; flex-shrink: 0; padding: 7px 13px; color: #5e6a7f; background: #f6f8fb; border: 1px solid #dfe6ef; border-radius: 8px; font-size: 11px; font-weight: 750; cursor: pointer; }
.product-tabs button.active { color: #fff; background: #2457d6; border-color: #2457d6; }
.product-tabs button:disabled { opacity: .55; cursor: not-allowed; }
.product-drawer-content { min-height: 0; flex: 1; padding: 22px 28px 30px; overflow-y: auto; }
.product-section { margin-bottom: 18px; padding: 22px; background: #fff; border: 1px solid #e1e7f0; border-radius: 15px; box-shadow: 0 6px 20px rgba(27, 52, 87, .045); }
.section-header { display: flex; align-items: flex-start; gap: 13px; margin-bottom: 21px; padding-bottom: 16px; border-bottom: 1px solid #edf1f6; }
.section-number { min-width: 38px; height: 30px; display: grid; place-items: center; border-radius: 8px; color: #2457d6; background: #eaf0ff; font-size: 11px; font-weight: 800; }
.section-header h3 { margin: 0; color: #15223b; font-size: 15px; font-weight: 800; }
.section-header p { margin: 4px 0 0; color: #7b879c; font-size: 12px; }
.product-drawer-footer { min-height: 74px; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; gap: 16px; padding: 14px 28px; background: #fff; border-top: 1px solid #dfe6ef; box-shadow: 0 -5px 18px rgba(18, 40, 71, .05); }
.footer-help { color: #7c8799; font-size: 11px; }
.footer-actions { display: flex; align-items: center; gap: 10px; }
.field-label, .product-field { min-width: 0; width: 100%; display: grid; gap: 7px; margin: 0; color: #344159; font-size: 12px; font-weight: 700; }
.product-field input,
.product-field select,
.product-field textarea,
.reference-search-row input,
.reference-search-row select,
.listing-toolbar input,
.listing-toolbar select { width: 100%; min-width: 0; min-height: 44px; padding: 10px 12px; color: #17233b; background: #fff; border: 1px solid #d8e0eb; border-radius: 9px; outline: none; font-size: 13px; font-weight: 500; transition: border-color .15s ease, box-shadow .15s ease; }
.product-field textarea { min-height: 94px; resize: vertical; line-height: 1.35; }
.product-field input::placeholder,
.reference-search-row input::placeholder,
.listing-toolbar input::placeholder { color: #a0a9b8; }
.product-field input:focus,
.product-field select:focus,
.product-field textarea:focus,
.reference-search-row input:focus,
.reference-search-row select:focus,
.listing-toolbar input:focus,
.listing-toolbar select:focus { border-color: #2457d6; box-shadow: 0 0 0 3px rgba(36, 87, 214, .12); }
.reference-results { display: grid; gap: 10px; margin-top: 14px; }
.reference-result { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 12px; align-items: center; border: 1px solid #d8e2f1; border-radius: 8px; padding: 12px; }
.reference-result div, .selected-reference { display: grid; gap: 5px; }
.reference-result span, .reference-result small, .selected-reference span, label small { color: #66758f; overflow-wrap: anywhere; }
.form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px 20px; }
.field-help { display: flex; align-items: flex-start; gap: 9px; padding: 11px 13px; color: #66738a; background: #f6f8fc; border: 1px dashed #d7deea; border-radius: 9px; font-size: 11px; line-height: 1.5; }
.help-icon { width: 18px; height: 18px; display: grid; place-items: center; flex-shrink: 0; color: #2457d6; background: #eaf0ff; border-radius: 50%; font-size: 11px; font-weight: 900; }
.empty-guidance { min-height: 180px; align-content: start; }
.span-2 { grid-column: 1 / -1; }
.error { color: #b42318; font-weight: 800; }
@media (max-width: 760px) {
  .listing-toolbar, .reference-search-row, .form-grid, .reference-result { grid-template-columns: 1fr; }
  .page-actions, .footer-actions { flex-direction: column; }
  .span-2 { grid-column: auto; }
  .product-drawer-header { min-height: 84px; padding: 15px 16px; }
  .drawer-heading-icon, .drawer-heading p, .footer-help { display: none; }
  .product-tabs { padding: 10px 14px; }
  .product-drawer-content { padding: 15px 14px 24px; }
  .product-drawer-footer { padding: 12px 14px; }
}
</style>
