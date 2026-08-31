<template>
  <Layout :page="page" :title="title">
    <template #topbar-title>
      <div class="bill-page-title">
        <span>INVENTORY MANAGEMENT</span>
        <h1>HSN/SAC Master</h1>
        <p>Create your business HSN/SAC records used in Product Master.</p>
      </div>
    </template>

    <div class="hsn-page">
      <div class="page-actions">
        <a class="secondary-action" href="/app/inventory/products">Products</a>
        <button type="button" class="primary-action" @click="openForm()">+ Add HSN/SAC</button>
      </div>

      <section class="bill-card">
        <div class="toolbar">
          <input v-model="filters.search" type="search" placeholder="Search my HSN/SAC" @keyup.enter="loadRecords" />
          <select v-model="filters.code_type" @change="loadRecords">
            <option value="">HSN + SAC</option>
            <option value="HSN">HSN</option>
            <option value="SAC">SAC</option>
          </select>
          <select v-model="filters.status" @change="loadRecords">
            <option value="">All Status</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>

        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>Code</th>
                <th>Type</th>
                <th>Description</th>
                <th>GST</th>
                <th>Taxability</th>
                <th>Reference Status</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in records" :key="row.id">
                <td><strong>{{ row.hsn_code }}</strong></td>
                <td>{{ row.code_type }}</td>
                <td>{{ row.description }}</td>
                <td>{{ money(row.gst_rate) }}%</td>
                <td>{{ label(row.taxability) }}</td>
                <td><span class="reference-pill">{{ row.reference_status }}</span></td>
                <td><span class="status-pill">{{ row.status }}</span></td>
                <td>
                  <button type="button" class="crud-action" @click="openForm(row)">Edit</button>
                  <button type="button" class="crud-action danger" @click="remove(row)">Delete</button>
                </td>
              </tr>
              <tr v-if="!records.length && !loading">
                <td colspan="8" class="empty">No business HSN/SAC records found.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>

    <div v-if="showForm" class="drawer-shell">
      <div class="drawer">
        <div class="drawer-head">
          <div>
            <span>MY HSN/SAC MASTER</span>
            <h2>{{ form.id ? 'Edit HSN/SAC' : 'Add HSN/SAC' }}</h2>
          </div>
          <button type="button" @click="closeForm">Close</button>
        </div>

        <div class="reference-box">
          <label>Search BillIQ Reference</label>
          <input v-model="referenceSearch" type="search" placeholder="Type code or description" @input="searchReference" />
          <div v-if="referenceResults.length" class="reference-results">
            <button v-for="ref in referenceResults" :key="ref.id" type="button" @click="useReference(ref)">
              <strong>{{ ref.code_type }} {{ ref.hsn_code }}</strong>
              <span>{{ ref.description }}</span>
              <small>Suggested GST: {{ money(ref.gst_rate) }}%</small>
            </button>
          </div>
          <p v-if="referenceStatus" class="reference-message">{{ referenceStatus }}</p>
        </div>

        <form class="form-grid" @submit.prevent="save">
          <label>Code Type
            <select v-model="form.code_type" required>
              <option value="HSN">HSN</option>
              <option value="SAC">SAC</option>
            </select>
          </label>
          <label>HSN/SAC Code
            <input v-model="form.hsn_code" required maxlength="12" @blur="checkExactReference" />
          </label>
          <label class="span-2">Description
            <textarea v-model="form.description" required rows="4"></textarea>
          </label>
          <label>GST Rate
            <select v-model.number="form.gst_rate" :disabled="form.taxability !== 'taxable'" required>
              <option :value="0">0%</option>
              <option :value="5">5%</option>
              <option :value="12">12%</option>
              <option :value="18">18%</option>
              <option :value="28">28%</option>
            </select>
          </label>
          <label>Cess
            <input v-model.number="form.cess_rate" type="number" min="0" max="100" step="0.01" />
          </label>
          <label>Taxability
            <select v-model="form.taxability" required>
              <option value="taxable">Taxable</option>
              <option value="nil_rated">Nil Rated</option>
              <option value="exempt">Exempt</option>
              <option value="non_gst">Non-GST</option>
            </select>
          </label>
          <label>Status
            <select v-model="form.status" required>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </label>

          <p v-if="rateWarning" class="warning span-2">{{ rateWarning }}</p>
          <p v-if="firstError" class="error span-2">{{ firstError }}</p>

          <div class="form-actions span-2">
            <button type="button" class="secondary-action" @click="closeForm">Cancel</button>
            <button type="submit" class="primary-action" :disabled="saving">{{ saving ? 'Saving...' : 'Save HSN/SAC' }}</button>
          </div>
        </form>
      </div>
    </div>
  </Layout>
</template>

<script setup>
import axios from 'axios';
import { computed, reactive, ref, watch, onMounted } from 'vue';
import Layout from '../Layout.vue';

defineProps({
  page: { type: String, default: 'business-hsn-master' },
  title: { type: String, default: 'HSN/SAC Master' },
});

const records = ref([]);
const loading = ref(false);
const saving = ref(false);
const showForm = ref(false);
const errors = ref({});
const referenceSearch = ref('');
const referenceResults = ref([]);
const referenceStatus = ref('');
let timer = null;

const filters = reactive({ search: '', code_type: '', status: '', per_page: 25 });
const form = reactive(defaults());

const firstError = computed(() => Object.values(errors.value || {})?.[0]?.[0] || '');
const rateWarning = computed(() => {
  if (!form.reference_hsn_master_id || form.reference_gst_rate === null || form.reference_gst_rate === undefined) return '';
  if (Number(form.reference_gst_rate) === Number(form.gst_rate || 0)) return '';
  return `BillIQ reference GST is ${money(form.reference_gst_rate)}%. Your selected GST is ${money(form.gst_rate)}%.`;
});

watch(() => form.taxability, (value) => {
  if (value !== 'taxable') form.gst_rate = 0;
});

onMounted(loadRecords);

function defaults() {
  return {
    id: null,
    code_type: 'HSN',
    hsn_code: '',
    description: '',
    gst_rate: 0,
    cess_rate: 0,
    taxability: 'taxable',
    status: 'active',
    reference_hsn_master_id: null,
    reference_gst_rate: null,
  };
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
  referenceSearch.value = row ? `${row.hsn_code} ${row.description}` : '';
  referenceResults.value = [];
  referenceStatus.value = '';
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
    if (q.length < 2) return;
    const { data } = await axios.get('/app/inventory/hsn-master/reference-search', { params: { q, code_type: form.code_type } });
    referenceResults.value = data.data || [];
  }, 250);
}

async function checkExactReference() {
  if (!form.hsn_code) return;
  const { data } = await axios.get('/app/inventory/hsn-master/reference-search', { params: { q: form.hsn_code, code_type: form.code_type } });
  const exact = (data.data || []).find((row) => String(row.hsn_code) === String(form.hsn_code));
  referenceStatus.value = exact
    ? `Found in BillIQ Reference: ${exact.description} | GST ${money(exact.gst_rate)}%`
    : 'This HSN/SAC code was not found in the BillIQ reference master. It will be saved as Manual / Not Matched.';
}

function useReference(ref) {
  form.reference_hsn_master_id = ref.id;
  form.reference_gst_rate = ref.gst_rate;
  form.code_type = ref.code_type || form.code_type;
  form.hsn_code = ref.hsn_code || '';
  form.description = ref.description || '';
  form.gst_rate = Number(ref.gst_rate || 0);
  form.cess_rate = Number(ref.cess_rate || 0);
  form.taxability = ref.taxability || 'taxable';
  referenceStatus.value = `Found in BillIQ Reference: ${ref.description}`;
  referenceResults.value = [];
}

async function save() {
  saving.value = true;
  errors.value = {};
  try {
    const payload = { ...form };
    const url = form.id ? `/app/inventory/hsn-master/${form.id}` : '/app/inventory/hsn-master';
    form.id ? await axios.put(url, payload) : await axios.post(url, payload);
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

const money = (value) => Number(value || 0).toFixed(2).replace(/\.00$/, '');
const label = (value) => String(value || '').replace('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
</script>

<style scoped>
.hsn-page { display: grid; gap: 18px; }
.page-actions { display: flex; justify-content: flex-end; gap: 12px; }
.primary-action, .secondary-action, .crud-action { border: 1px solid #d9e2f2; border-radius: 8px; padding: 10px 16px; font-weight: 800; text-decoration: none; cursor: pointer; }
.primary-action { background: #2f63df; color: #fff; border-color: #2f63df; }
.secondary-action, .crud-action { background: #fff; color: #24324a; }
.danger { color: #b42318; }
.bill-card { background: #fff; border: 1px solid #dce5f3; border-radius: 8px; padding: 18px; }
.toolbar { display: flex; gap: 12px; margin-bottom: 16px; }
input, select, textarea { width: 100%; border: 1px solid #d8e2f1; border-radius: 8px; padding: 12px; font: inherit; }
.table-wrapper { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; }
th, td { text-align: left; padding: 13px 12px; border-bottom: 1px solid #edf1f7; vertical-align: top; }
th { font-size: 12px; color: #70809a; text-transform: uppercase; letter-spacing: .04em; }
.status-pill, .reference-pill { display: inline-flex; border-radius: 999px; padding: 5px 9px; background: #eef4ff; font-weight: 800; font-size: 12px; }
.empty { text-align: center; color: #718096; }
.drawer-shell { position: fixed; inset: 0; background: rgba(11, 20, 36, .48); display: flex; justify-content: flex-end; z-index: 40; }
.drawer { width: min(760px, 100%); height: 100%; overflow: auto; background: #f6f9fd; padding: 24px; }
.drawer-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; }
.drawer-head span { color: #2f63df; font-size: 12px; font-weight: 900; letter-spacing: .12em; }
.drawer-head h2 { margin: 4px 0 0; }
.reference-box, .form-grid { background: #fff; border: 1px solid #dce5f3; border-radius: 8px; padding: 18px; margin-bottom: 16px; }
.reference-results { display: grid; gap: 8px; margin-top: 10px; }
.reference-results button { text-align: left; border: 1px solid #d8e2f1; border-radius: 8px; padding: 10px; background: #fff; display: grid; gap: 4px; }
.reference-results span, .reference-results small, .reference-message { color: #66758f; }
.form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
label { display: grid; gap: 7px; font-weight: 800; color: #27364f; }
.span-2 { grid-column: 1 / -1; }
.warning { border: 1px dashed #f6b73c; background: #fff8e8; color: #8a5a00; border-radius: 8px; padding: 12px; }
.error { color: #b42318; font-weight: 800; }
.form-actions { display: flex; justify-content: flex-end; gap: 12px; }
@media (max-width: 720px) {
  .toolbar, .form-grid, .page-actions { grid-template-columns: 1fr; flex-direction: column; }
  .span-2 { grid-column: auto; }
}
</style>
