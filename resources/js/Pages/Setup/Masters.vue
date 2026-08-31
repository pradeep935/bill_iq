<template>
  <Layout :page="page" :title="title">
    <template #topbar-title>
      <div class="bill-page-title">
        <span>ADMIN SETUP</span>
        <h1>{{ title }}</h1>
        <p>{{ pageDescription }}</p>
      </div>
    </template>

    <div v-if="visibleTabs.length > 1" class="master-tabs">
      <button v-for="tab in visibleTabs" :key="tab.key" :class="{ active: activeTab === tab.key }" @click="switchTab(tab.key)">
        {{ tab.label }}
      </button>
    </div>

    <section class="bill-card master-panel">
      <div class="bill-card-head">
        <div>
          <h3>{{ current.label }}</h3>
          <p>{{ current.hint }}</p>
        </div>
        <div class="master-filters">
          <input v-model="filters.search" type="search" placeholder="Search records" @keyup.enter="loadRecords" />
          <template v-if="activeTab === 'hsn'">
            <select v-model="filters.code_type" @change="loadRecords">
              <option value="">HSN + SAC</option>
              <option value="HSN">HSN Goods</option>
              <option value="SAC">SAC Services</option>
            </select>
            <select v-model="filters.taxability" @change="loadRecords">
              <option value="">All Taxability</option>
              <option value="taxable">Taxable</option>
              <option value="exempt">Exempt</option>
              <option value="nil_rated">Nil Rated</option>
              <option value="non_gst">Non-GST</option>
            </select>
            <select v-model="filters.verification_status" @change="loadRecords">
              <option value="">All Verification</option>
              <option value="verified">Classification Verified</option>
              <option value="rate_suggested">Rate Suggested</option>
              <option value="rate_verified">Rate Verified</option>
              <option value="unverified">Unverified</option>
            </select>
            <select v-model="filters.source" @change="loadRecords">
              <option value="">All Sources</option>
              <option value="official">Official</option>
              <option value="custom">Business Custom</option>
            </select>
            <input v-model="filters.chapter_code" class="compact-filter" type="search" placeholder="Chapter" @keyup.enter="loadRecords" />
            <select v-model="filters.per_page" @change="loadRecords">
              <option value="25">25</option>
              <option value="50">50</option>
              <option value="100">100</option>
            </select>
          </template>
          <select v-model="filters.status" @change="loadRecords">
            <option value="">All Status</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
          <button type="button" class="primary-add" @click="openDrawer()">+ Add {{ current.singular }}</button>
          <button v-if="activeTab === 'hsn'" type="button" class="secondary-action" @click="notify('HSN/SAC import will be available soon.', 'success')">Import HSN/SAC</button>
        </div>
      </div>

      <div v-if="flash.message" :class="['master-flash', flash.type]">{{ flash.message }}</div>

      <div v-if="activeTab === 'hsn'" class="hsn-summary-grid">
        <div v-for="item in hsnSummary" :key="item.label" class="hsn-summary-item">
          <span>{{ item.label }}</span>
          <strong>{{ item.value }}</strong>
        </div>
      </div>

      <div class="master-layout">
        <CrudTable
          :columns="current.columns"
          :rows="records"
          :loading="loading"
          :value-for="valueFor"
          @edit="editRecord"
          @delete="deleteRecord"
        >
          <template v-if="activeTab === 'hsn'" #cell-hsn_code="{ row }">
            <div class="hsn-code-cell">
              <strong>{{ row.hsn_code || '-' }}</strong>
              <span :class="['hsn-type-pill', String(row.code_type || 'HSN').toLowerCase()]">{{ row.code_type || 'HSN' }}</span>
            </div>
          </template>

          <template v-if="activeTab === 'hsn'" #cell-gst_rate="{ row }">
            <div class="hsn-tax-cell">
              <strong>{{ hsnRateTitle(row) }}</strong>
              <span>{{ hsnRateSubtext(row) }}</span>
            </div>
          </template>

          <template v-if="activeTab === 'hsn'" #cell-verification_status="{ row }">
            <span :class="['hsn-verify-pill', row.verification_status || 'verified']">
              {{ verificationLabel(row.verification_status) }}
            </span>
          </template>

          <template v-if="activeTab === 'hsn'" #cell-description="{ row }">
            <div class="hsn-description-cell">
              <strong>{{ row.description || '-' }}</strong>
              <span>{{ hsnMeta(row) }}</span>
            </div>
          </template>

          <template v-if="activeTab === 'hsn'" #actions="{ row }">
            <button v-if="row.is_official" type="button" class="crud-action" @click="openRateDrawer(row)">{{ row.rate_verified ? 'Rate' : 'Verify' }}</button>
            <button v-if="row.is_official" type="button" class="crud-action" @click="cloneHsnRecord(row)">Override</button>
            <button v-else type="button" class="crud-action" @click="editRecord(row)">Edit</button>
            <button v-if="!row.is_official" type="button" class="crud-action danger" @click="deleteRecord(row)">Delete</button>
          </template>
        </CrudTable>

        <div v-if="pagination.total > pagination.per_page" class="master-pagination">
          <button type="button" :disabled="pagination.current_page <= 1 || loading" @click="goToPage(pagination.current_page - 1)">Previous</button>
          <span>Page {{ pagination.current_page }} of {{ pagination.last_page }} | {{ pagination.total }} records</span>
          <button type="button" :disabled="pagination.current_page >= pagination.last_page || loading" @click="goToPage(pagination.current_page + 1)">Next</button>
        </div>
      </div>
    </section>

    <CrudDrawer
      :model-value="drawerOpen"
      :title="drawerTitle"
      :description="drawerDescription"
      :processing="saving"
      :save-label="editingId ? 'Update' : 'Save'"
      @close="closeDrawer"
      @save="saveRecord"
    >
      <template #tabs>
        <button v-for="tab in visibleTabs" :key="tab.key" type="button" class="master-drawer-tab" :class="{ active: activeTab === tab.key }" @click="switchTab(tab.key)">
          {{ tab.label }}
        </button>
      </template>

          <form class="master-form-card" @submit.prevent="saveRecord">
            <div class="master-section-header">
              <div class="section-number">01</div>
              <div>
                <h3>{{ current.singular }} Information</h3>
                <p>{{ current.hint }}</p>
              </div>
            </div>

            <template v-if="activeTab === 'branch'">
              <DrawerField v-model="form.name" label="Name" hint="Enter the official branch name used on vouchers and reports." required />
              <DrawerField v-model="form.type" label="Type" placeholder="Head Office, Store, Godown" />
              <SearchSelect
                v-model="form.state_id"
                label="State"
                :options="references.states"
                select-placeholder="Search state"
                hint="Select the state from the master list."
                required
              />
              <SearchSelect
                v-model="form.city_id"
                label="City"
                :options="filteredCities"
                :disabled="!form.state_id"
                :select-placeholder="form.state_id ? 'Search city' : 'Select state first'"
                hint="City list is filtered from the selected state."
                required
              />
              <DrawerField v-model="form.address" label="Address" as="textarea" :span="2" />
            </template>

            <template v-if="activeTab === 'warehouse'">
              <DrawerField v-model="form.branch_id" label="Branch" as="select" hint="Select the branch that owns or operates this warehouse." required>
                <option value="">Select branch</option>
                <option v-for="branch in references.branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
              </DrawerField>
              <DrawerField v-model="form.name" label="Name" hint="Enter the warehouse name shown in stock transactions." required />
              <DrawerField v-model="form.code" label="Code" />
            </template>

            <template v-if="activeTab === 'category'">
              <DrawerField v-model="form.name" label="Name" hint="Enter a broad product group, for example Electronics or Hardware." required />
            </template>

            <template v-if="activeTab === 'subcategory'">
              <DrawerField v-model="form.parent_id" label="Category" as="select" hint="Choose the parent category for this sub category." required>
                <option value="">Select category</option>
                <option v-for="category in references.categories" :key="category.id" :value="category.id">{{ category.name }}</option>
              </DrawerField>
              <DrawerField v-model="form.name" label="Name" hint="Enter a more specific product group under the selected category." required />
            </template>

            <template v-if="activeTab === 'brand'">
              <DrawerField v-model="form.name" label="Name" hint="Enter the brand or manufacturer name used in Product Master." required />
            </template>

            <template v-if="activeTab === 'unit'">
              <DrawerField v-model="form.code" label="Code" placeholder="PCS" hint="Use a short unit code such as PCS, KG, LTR, BOX or MTR." required />
              <DrawerField v-model="form.name" label="Name" placeholder="Pieces" hint="Enter the full unit name displayed to users." required />
            </template>

            <template v-if="activeTab === 'hsn'">
              <section v-if="rateMode" class="rate-context-card">
                <span>{{ form.code_type }}</span>
                <strong>{{ form.hsn_code }}</strong>
                <p>{{ form.description }}</p>
              </section>
              <template v-else>
                <DrawerField v-model="form.hsn_code" label="HSN/SAC Code" hint="Enter the tax classification code used for GST invoices." required />
                <DrawerField v-model="form.code_type" label="Code Type" as="select" hint="Use HSN for goods and SAC for services." required>
                  <option value="HSN">HSN - Goods</option>
                  <option value="SAC">SAC - Services</option>
                </DrawerField>
              </template>

              <DrawerField v-model="form.taxability" label="Taxability" as="select" hint="Defines how this classification is treated for GST." required>
                <option value="taxable">Taxable</option>
                <option value="exempt">Exempt</option>
                <option value="nil_rated">Nil Rated</option>
                <option value="non_gst">Non-GST</option>
              </DrawerField>
              <DrawerField v-model="gstRateSelection" label="GST Rate %" as="select" hint="Select the statutory GST rate for this classification." required>
                <option v-for="rate in gstRateOptions" :key="rate.value" :value="rate.value">{{ rate.label }}</option>
                <option value="custom">Custom rate</option>
              </DrawerField>
              <DrawerField v-if="gstRateSelection === 'custom'" v-model="form.gst_rate" label="Custom GST Rate %" type="number" :min="0" :max="100" step="0.01" hint="Enter a custom GST percentage only when needed." number required />
              <DrawerField v-model="form.cess_rate" label="CESS Rate %" type="number" :min="0" :max="100" step="0.01" hint="Optional compensation cess percentage. Leave as 0 when not applicable." number />
              <DrawerField v-model="form.verification_status" label="Verification" as="select" hint="Only verified active records are available in Product Master." required>
                <option value="classification_verified">Classification Verified</option>
                <option value="verified">Rate Verified</option>
                <option value="unverified">Unverified</option>
              </DrawerField>

              <DrawerField v-model="form.effective_from" label="Effective From" type="date" hint="Required start date for this tax rate." required />
              <DrawerField v-model="form.effective_to" label="Effective To" type="date" hint="Optional end date. Leave blank while this rate is current." />
              <template v-if="rateMode">
                <DrawerField v-model="form.notification_number" label="Notification No." hint="CBIC/GST Council rate notification reference used to verify this rate." />
                <DrawerField v-model="form.source_reference" label="Source Reference" hint="Add source link or document name for audit trail." />
                <DrawerField v-model="form.notes" label="Rate Notes" as="textarea" hint="Mention conditions, exemptions or usage notes if any." :span="2" />
              </template>
              <template v-else>
                <DrawerField v-model="form.chapter_code" label="Chapter Code" hint="Optional classification chapter used for filtering and reports." />
                <DrawerField v-model="form.description" label="Classification Description" as="textarea" hint="Describe the tax classification, not a product or brand name." :span="2" required />
              </template>
            </template>

            <DrawerField v-if="!rateMode" v-model="form.status" label="Status" as="select" hint="Keep active records available for selection in transactions." required>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </DrawerField>

            <div v-if="firstError" class="field-error">{{ firstError }}</div>

            <section v-if="!rateMode" class="master-help-card">
              <div class="help-icon">i</div>
              <div>
                <strong>{{ current.helpTitle }}</strong>
                <ul>
                  <li v-for="point in current.helpPoints" :key="point">{{ point }}</li>
                </ul>
              </div>
            </section>
          </form>
    </CrudDrawer>
  </Layout>
</template>

<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
import axios from 'axios';
import CrudDrawer from '../../Components/Common/CrudDrawer.vue';
import CrudTable from '../../Components/Common/CrudTable.vue';
import DrawerField from '../../Components/Common/DrawerField.vue';
import SearchSelect from '../../Components/Common/SearchSelect.vue';
import Layout from '../Layout.vue';

const props = defineProps({
  page: { type: String, default: 'masters' },
  title: { type: String, default: 'Masters' },
  initial_tab: { type: String, default: 'category' },
  role_id: { type: Number, default: null },
});

const tabs = [
  { key: 'branch', label: 'Branches', singular: 'Branch', hint: 'Create business branches used in vouchers, payroll and inventory reports.', helpTitle: 'Where is this used?', helpPoints: ['Separates sales, purchases, payroll and reports by business location.', 'Links warehouses, employees and vouchers to the correct branch.', 'Helps generate branch-wise profit, stock and outstanding reports.'], columns: [{ key: 'name', label: 'Name' }, { key: 'type', label: 'Type' }, { key: 'city_id', label: 'City', type: 'city' }, { key: 'state_id', label: 'State', type: 'state' }, { key: 'address', label: 'Address' }] },
  { key: 'warehouse', label: 'Warehouses', singular: 'Warehouse', hint: 'Create warehouses and map each warehouse to a branch for stock transactions.', helpTitle: 'Where is this used?', helpPoints: ['Tracks stock receiving, issuing, transfers and adjustments by warehouse.', 'Supports multiple storage points under the same branch.', 'Shows the exact warehouse location in inventory reports.'], columns: [{ key: 'name', label: 'Name' }, { key: 'code', label: 'Code' }, { key: 'branch_id', label: 'Branch', type: 'branch' }] },
  { key: 'category', label: 'Categories', singular: 'Category', hint: 'Maintain product categories used in Product Master and stock reports.', helpTitle: 'Where is this used?', helpPoints: ['Groups products into broad catalog sections in Product Master.', 'Supports category-wise sales, purchase and inventory reporting.', 'Makes product search and filtering cleaner for daily billing.'], columns: [{ key: 'name', label: 'Name' }] },
  { key: 'subcategory', label: 'Sub Categories', singular: 'Sub Category', hint: 'Maintain sub categories under product categories for cleaner catalog grouping.', helpTitle: 'Where is this used?', helpPoints: ['Adds a more specific grouping under each product category.', 'Improves product filters, barcode labels and catalog organization.', 'Helps compare and report similar products together.'], columns: [{ key: 'name', label: 'Name' }, { key: 'parent_id', label: 'Category', type: 'category' }] },
  { key: 'brand', label: 'Brands', singular: 'Brand', hint: 'Maintain product brands used in Product Master, filters and reports.', helpTitle: 'Where is this used?', helpPoints: ['Tags products with the brand or manufacturer name.', 'Supports brand-wise sales, purchase and stock performance reporting.', 'Improves product search, filtering and billing selection.'], columns: [{ key: 'name', label: 'Name' }] },
  { key: 'unit', label: 'Units', singular: 'Unit', hint: 'Maintain units such as PCS, KG, LTR and BOX for billing and inventory quantities.', helpTitle: 'Where is this used?', helpPoints: ['Defines how quantities are entered in billing and inventory.', 'Keeps purchase and sales quantities in a consistent measurement format.', 'Improves accuracy in stock movement and valuation reports.'], columns: [{ key: 'code', label: 'Code' }, { key: 'name', label: 'Name' }] },
  { key: 'hsn', label: 'HSN/SAC', singular: 'HSN/SAC', hint: 'Maintain HSN/SAC tax classifications and GST rates used by many products and services.', helpTitle: 'Where is this used?', helpPoints: ['One HSN/SAC classification can be linked with many products.', 'Product Master stores the actual product name separately from this tax description.', 'Provides stable tax snapshots for invoices, tax summaries and GST reports.'], columns: [{ key: 'hsn_code', label: 'Code', class: 'hsn-code-column', hint: 'HSN/SAC code and whether it applies to goods or services.' }, { key: 'gst_rate', label: 'GST', class: 'hsn-rate-column', hint: 'GST percentage and taxability type.' }, { key: 'verification_status', label: 'Verification', class: 'hsn-verify-column', hint: 'Only verified active classifications should be used in Product Master.' }, { key: 'description', label: 'Classification', class: 'hsn-description-column', hint: 'Official classification description and validity details.' }] },
];

const fallbackGstRateOptions = [
  { value: '0', label: '0%' },
  { value: '5', label: '5%' },
  { value: '12', label: '12%' },
  { value: '18', label: '18%' },
  { value: '28', label: '28%' },
];
const gstRateOptions = computed(() => references.gst_rate_slabs?.length ? references.gst_rate_slabs : fallbackGstRateOptions);

const visibleTabs = computed(() => {
  if (props.page === 'branches') return tabs.filter((tab) => tab.key === 'branch');
  if (props.page === 'inventory-warehouses') return tabs.filter((tab) => tab.key === 'warehouse');
  return tabs.filter((tab) => {
    if (['branch', 'warehouse'].includes(tab.key)) return false;
    if (tab.key === 'hsn') return Number(props.role_id) === 1;
    return true;
  });
});

const initialTab = computed(() => (
  visibleTabs.value.some((tab) => tab.key === props.initial_tab)
    ? props.initial_tab
    : visibleTabs.value[0]?.key || 'category'
));
const activeTab = ref(initialTab.value);
const records = ref([]);
const references = reactive({ branches: [], categories: [], states: [], cities: [], gst_rate_slabs: [] });
const filters = reactive({ search: '', status: '', code_type: '', taxability: '', verification_status: '', source: '', chapter_code: '', per_page: 25, page: 1 });
const form = reactive({});
const hsnServerSummary = ref(null);
const pagination = reactive({ current_page: 1, last_page: 1, per_page: 25, total: 0 });
const gstRateSelection = ref('0');
const errors = ref({});
const flash = reactive({ message: '', type: 'success' });
const loading = ref(false);
const saving = ref(false);
const editingId = ref(null);
const drawerOpen = ref(false);
const rateMode = ref(false);
const hydratingForm = ref(false);

const current = computed(() => visibleTabs.value.find((tab) => tab.key === activeTab.value) || visibleTabs.value[0] || tabs[0]);
const filteredCities = computed(() => references.cities.filter((city) => Number(city.state_id) === Number(form.state_id)));
const pageDescription = computed(() => {
  if (props.page === 'branches') return 'Create and maintain business branches used in vouchers, payroll and inventory reports.';
  if (props.page === 'inventory-warehouses') return 'Create warehouses and map storage locations to business branches for stock transactions.';
  return 'Maintain catalog, product grouping, units and tax master records used across billing and inventory.';
});
const firstError = computed(() => Object.values(errors.value || {})?.[0]?.[0] || '');
const drawerTitle = computed(() => {
  if (rateMode.value) return `Verify GST Rate - ${form.hsn_code || 'HSN/SAC'}`;
  return editingId.value ? `Edit ${current.value.singular}` : `Add ${current.value.singular}`;
});
const drawerDescription = computed(() => rateMode.value
  ? 'Set the current verified GST/CESS rate against this official HSN/SAC classification.'
  : current.value.hint
);
const hsnSummary = computed(() => {
  if (hsnServerSummary.value) {
    return [
      { label: 'Total', value: hsnServerSummary.value.total },
      { label: 'Official', value: hsnServerSummary.value.official },
      { label: 'Classified', value: hsnServerSummary.value.classification_verified },
      { label: 'Rate Suggested', value: hsnServerSummary.value.rate_suggested },
      { label: 'Rate Verified', value: hsnServerSummary.value.rate_verified },
      { label: 'HSN / SAC', value: `${hsnServerSummary.value.hsn} / ${hsnServerSummary.value.sac}` },
    ];
  }

  return [
    { label: 'Showing', value: records.value.length },
  ];
});

const defaults = () => ({
  name: '',
  type: '',
  address: '',
  city: '',
  state: '',
  state_id: '',
  city_id: '',
  branch_id: '',
  parent_id: '',
  code: '',
  hsn_code: '',
  code_type: 'HSN',
  taxability: 'taxable',
  description: '',
  chapter_code: '',
  gst_rate: 0,
  cess_rate: 0,
  verification_status: 'classification_verified',
  effective_from: new Date().toISOString().slice(0, 10),
  effective_to: '',
  status: 'active',
  notification_number: '',
  source_reference: '',
  notes: '',
});

const resetForm = () => {
  editingId.value = null;
  rateMode.value = false;
  errors.value = {};
  Object.assign(form, defaults());
  gstRateSelection.value = '0';
};

const openDrawer = (record = null) => {
  resetForm();
  if (record) {
    hydratingForm.value = true;
    editingId.value = record.id;
    Object.keys(defaults()).forEach((key) => {
      form[key] = record[key] ?? '';
    });
    form.status = record.status || 'active';
    gstRateSelection.value = gstRateOptions.value.some((rate) => Number(rate.value) === Number(form.gst_rate))
      ? String(Number(form.gst_rate))
      : 'custom';
    nextTick(() => {
      hydratingForm.value = false;
    });
  }
  drawerOpen.value = true;
};

const closeDrawer = () => {
  drawerOpen.value = false;
  resetForm();
};

const openRateDrawer = (record) => {
  openDrawer(record);
  rateMode.value = true;
  form.gst_rate = record.gst_rate ?? 0;
  form.cess_rate = record.cess_rate ?? 0;
  form.taxability = record.taxability || 'taxable';
  form.effective_from = formatDate(record.effective_from) || new Date().toISOString().slice(0, 10);
  form.effective_to = formatDate(record.effective_to);
  gstRateSelection.value = gstRateOptions.value.some((rate) => Number(rate.value) === Number(form.gst_rate))
    ? String(Number(form.gst_rate))
    : 'custom';
};

const notify = (message, type = 'success') => {
  flash.message = message;
  flash.type = type;
  setTimeout(() => {
    if (flash.message === message) flash.message = '';
  }, 2600);
};

const loadReferences = async () => {
  const { data } = await axios.get('/app/setup/masters/references');
  references.branches = data.branches || [];
  references.categories = data.categories || [];
  references.states = data.states || [];
  references.cities = data.cities || [];
  references.gst_rate_slabs = data.gst_rate_slabs || [];
};

const loadRecords = async () => {
  loading.value = true;
  try {
    const { data } = await axios.get(`/app/setup/masters/${activeTab.value}/list`, { params: filters });
    const pageData = data.data || {};
    records.value = pageData.data || [];
    pagination.current_page = pageData.current_page || 1;
    pagination.last_page = pageData.last_page || 1;
    pagination.per_page = pageData.per_page || Number(filters.per_page || 25);
    pagination.total = pageData.total || records.value.length;
    hsnServerSummary.value = activeTab.value === 'hsn' ? (data.summary || null) : null;
  } finally {
    loading.value = false;
  }
};

const goToPage = async (page) => {
  filters.page = page;
  await loadRecords();
};

const switchTab = async (tab) => {
  activeTab.value = tab;
  filters.search = '';
  filters.status = '';
  filters.code_type = '';
  filters.taxability = '';
  filters.verification_status = '';
  filters.source = '';
  filters.chapter_code = '';
  filters.page = 1;
  hsnServerSummary.value = null;
  resetForm();
  await loadReferences();
  await loadRecords();
};

const payload = () => {
  const data = { status: form.status };
  const allowed = {
    branch: ['name', 'type', 'address', 'city_id', 'state_id', 'status'],
    warehouse: ['branch_id', 'name', 'code', 'status'],
    category: ['name', 'status'],
    subcategory: ['parent_id', 'name', 'status'],
    brand: ['name', 'status'],
    unit: ['code', 'name', 'status'],
    hsn: ['hsn_code', 'code_type', 'taxability', 'description', 'chapter_code', 'gst_rate', 'cess_rate', 'verification_status', 'effective_from', 'effective_to', 'status'],
  }[activeTab.value];

  if (activeTab.value === 'hsn' && rateMode.value) {
    if (gstRateSelection.value !== 'custom') {
      form.gst_rate = Number(form.taxability === 'taxable' ? gstRateSelection.value : 0);
    }

    return {
      rate_update_only: true,
      taxability: form.taxability,
      gst_rate: form.taxability === 'taxable' ? Number(form.gst_rate || 0) : 0,
      cess_rate: Number(form.cess_rate || 0),
      effective_from: form.effective_from,
      effective_to: form.effective_to || null,
      notification_number: form.notification_number || null,
      source_reference: form.source_reference || null,
      notes: form.notes || null,
    };
  }

  if (activeTab.value === 'hsn' && gstRateSelection.value !== 'custom') {
    form.gst_rate = Number(form.taxability === 'taxable' ? gstRateSelection.value : 0);
  }

  allowed.forEach((key) => {
    data[key] = form[key] === '' ? null : form[key];
  });

  return data;
};

const saveRecord = async () => {
  saving.value = true;
  errors.value = {};
  try {
    if (editingId.value) {
      await axios.put(`/app/setup/masters/${activeTab.value}/${editingId.value}`, payload());
    } else {
      await axios.post(`/app/setup/masters/${activeTab.value}`, payload());
    }
    notify(editingId.value ? 'Record updated successfully.' : 'Record saved successfully.');
    closeDrawer();
    await loadReferences();
    await loadRecords();
  } catch (error) {
    errors.value = error.response?.data?.errors || {};
    notify(error.response?.data?.message || 'Please check the highlighted fields.', 'error');
  } finally {
    saving.value = false;
  }
};

const editRecord = (record) => {
  openDrawer(record);
};

const cloneHsnRecord = (record) => {
  openDrawer({ ...record, id: null, status: 'active' });
  editingId.value = null;
  notify('Official record copied. Save to create a business override.', 'success');
};

const deleteRecord = async (record) => {
  if (!confirm(`Delete ${record.name || record.code || record.hsn_code}?`)) return;
  await axios.delete(`/app/setup/masters/${activeTab.value}/${record.id}`);
  notify('Record deleted successfully.');
  if (editingId.value === record.id) resetForm();
  await loadReferences();
  await loadRecords();
};

const valueFor = (record, column) => {
  if (column.type === 'city') {
    return record.city_name || references.cities.find((city) => Number(city.id) === Number(record.city_id))?.name || record.city || '-';
  }
  if (column.type === 'state') {
    return record.state_name || references.states.find((state) => Number(state.id) === Number(record.state_id))?.name || record.state || '-';
  }
  if (column.type === 'branch') {
    return references.branches.find((branch) => Number(branch.id) === Number(record[column.key]))?.name || '-';
  }
  if (column.type === 'category') {
    return references.categories.find((category) => Number(category.id) === Number(record[column.key]))?.name || '-';
  }
  if (column.key === 'gst_rate') {
    return formatPercent(record[column.key]);
  }
  if (column.key === 'cess_rate') {
    return formatPercent(record[column.key]);
  }
  if (column.key === 'taxability') {
    return taxabilityLabel(record[column.key]);
  }
  if (column.key === 'verification_status') {
    return verificationLabel(record[column.key]);
  }
  return record[column.key] || '-';
};

const formatPercent = (value) => `${Number(value || 0).toFixed(2)}%`;
const taxabilityLabel = (value) => String(value || '-').replace('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
const verificationLabel = (value) => {
  const status = String(value || '').toLowerCase().trim();
  if (['verified', 'rate_verified', 'rate verified'].includes(status)) return 'Rate Verified';
  if (['rate_suggested', 'rate suggested'].includes(status)) return 'Rate Suggested';
  if (['classification_verified', 'classification verified'].includes(status)) return 'Classification Verified';
  return 'Unverified';
};

const hsnRateTitle = (record) => {
  if (record.gst_rate === null || record.gst_rate === undefined || record.gst_rate === '') return 'Rate pending';
  return `${formatPercent(record.gst_rate)} GST${record.rate_suggested ? ' suggested' : ''}`;
};

const hsnRateSubtext = (record) => {
  if (record.rate_verified) return taxabilityLabel(record.taxability);
  if (record.rate_suggested) return 'Auto-filled; verify before billing';
  return 'Verify from CBIC notification';
};

const hsnMeta = (record) => {
  const parts = [];
  if (record.source_label) parts.push(record.source_label);
  if (record.chapter_code) parts.push(`Chapter ${record.chapter_code}`);
  if (record.rate_verified && Number(record.cess_rate || 0) > 0) parts.push(`${formatPercent(record.cess_rate)} CESS`);
  if (record.effective_from) parts.push(`From ${formatDate(record.effective_from)}`);
  if (record.effective_to) parts.push(`To ${formatDate(record.effective_to)}`);
  return parts.length ? parts.join(' | ') : 'Current tax classification';
};

const formatDate = (value) => {
  if (!value) return '';
  return String(value).slice(0, 10);
};

watch(
  () => form.state_id,
  (stateId, previousStateId) => {
    if (hydratingForm.value) return;
    if (activeTab.value === 'branch' && previousStateId !== undefined && Number(stateId) !== Number(previousStateId)) {
      form.city_id = '';
    }
  }
);

watch(
  () => form.taxability,
  (taxability) => {
    if (activeTab.value === 'hsn' && taxability !== 'taxable') {
      gstRateSelection.value = '0';
      form.gst_rate = 0;
    }
  }
);

watch(
  gstRateSelection,
  (rate) => {
    if (activeTab.value === 'hsn' && rate !== 'custom') {
      form.gst_rate = Number(form.taxability === 'taxable' ? rate : 0);
    }
  }
);

onMounted(async () => {
  resetForm();
  await loadReferences();
  await loadRecords();
});
</script>

<style scoped>
.master-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 20px;
}

.master-tabs button,
.primary-add,
.secondary-action {
  border: 1px solid #dbe4f0;
  background: #fff;
  color: #334155;
  border-radius: 8px;
  padding: 10px 16px;
  font-weight: 800;
  cursor: pointer;
}

.master-tabs button.active,
.primary-add {
  background: #2563eb;
  color: #fff;
  border-color: #2563eb;
}

.primary-add {
  white-space: nowrap;
}

.secondary-action {
  background: #f8fafc;
  border-color: #cbd5e1;
  color: #334155;
  white-space: nowrap;
}

.master-panel {
  padding: 22px;
}

.master-panel .bill-card-head {
  align-items: flex-start;
  display: grid;
  gap: 18px;
  grid-template-columns: minmax(260px, 1fr) minmax(0, 2.4fr);
}

.bill-card-head p {
  color: #718198;
  margin-top: 4px;
}

.master-filters {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  justify-content: flex-end;
  max-width: 100%;
}

.master-filters input,
.master-filters select {
  border: 1px solid #dbe4f0;
  border-radius: 8px;
  color: #27344c;
  font-family: inherit;
  font-size: 12px;
  font-weight: 650;
  padding: 11px 12px;
}

.master-filters input {
  width: 170px;
}

.master-filters select {
  width: 142px;
}

.master-filters .compact-filter {
  width: 92px;
}

.hsn-summary-grid {
  display: grid;
  gap: 10px;
  grid-template-columns: repeat(6, minmax(0, 1fr));
  margin: 14px 0 16px;
}

.hsn-summary-item {
  background: #f8fbff;
  border: 1px solid #e2eaf5;
  border-radius: 8px;
  padding: 12px 14px;
}

.hsn-summary-item span {
  color: #718198;
  display: block;
  font-size: 10px;
  font-weight: 800;
  text-transform: uppercase;
}

.hsn-summary-item strong {
  color: #17233b;
  display: block;
  font-size: 18px;
  font-weight: 850;
  margin-top: 3px;
}

:deep(.hsn-code-column) {
  width: 150px;
}

:deep(.hsn-rate-column),
:deep(.hsn-verify-column) {
  width: 150px;
}

:deep(.hsn-description-column) {
  min-width: 420px;
}

.hsn-code-cell,
.hsn-tax-cell,
.hsn-description-cell {
  display: grid;
  gap: 5px;
}

.hsn-code-cell strong {
  color: #17233b;
  font-size: 15px;
  font-weight: 850;
}

.hsn-type-pill,
.hsn-verify-pill {
  align-items: center;
  border-radius: 7px;
  display: inline-flex;
  font-size: 10px;
  font-weight: 850;
  justify-content: center;
  line-height: 1;
  padding: 6px 8px;
  width: max-content;
}

.hsn-type-pill.hsn {
  background: #edf2ff;
  color: #2457d6;
}

.hsn-type-pill.sac {
  background: #f0fdf4;
  color: #168757;
}

.hsn-tax-cell strong {
  color: #17233b;
  font-size: 13px;
  font-weight: 850;
}

.hsn-tax-cell strong:first-child {
  color: #17233b;
}

.hsn-tax-cell span,
.hsn-description-cell span {
  color: #718198;
  font-size: 11px;
  font-weight: 700;
}

.hsn-verify-pill.verified {
  background: #eaf8f1;
  color: #168757;
}

.hsn-verify-pill.rate_verified {
  background: #eaf8f1;
  color: #168757;
}

.hsn-verify-pill.rate_suggested {
  background: #eff6ff;
  color: #2457d6;
}

.hsn-verify-pill.unverified {
  background: #fff7ed;
  color: #c76513;
}

.hsn-description-cell {
  max-width: 720px;
  white-space: normal;
}

.hsn-description-cell strong {
  color: #27344c;
  display: -webkit-box;
  font-size: 12px;
  font-weight: 750;
  line-height: 1.45;
  overflow: hidden;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
}

.rate-context-card {
  background: #f8fbff;
  border: 1px solid #e2eaf5;
  border-radius: 10px;
  display: grid;
  gap: 5px;
  grid-column: 1 / -1;
  padding: 13px 14px;
}

.rate-context-card span {
  color: #2457d6;
  font-size: 10px;
  font-weight: 850;
}

.rate-context-card strong {
  color: #17233b;
  font-size: 18px;
  font-weight: 850;
}

.rate-context-card p {
  color: #66758d;
  font-size: 12px;
  font-weight: 700;
  line-height: 1.45;
  margin: 0;
}

.master-flash {
  border-radius: 8px;
  margin: 12px 0;
  padding: 12px 14px;
  font-weight: 800;
}

.master-flash.success {
  background: #ecfdf5;
  color: #047857;
}

.master-flash.error,
.field-error {
  background: #fef2f2;
  color: #dc2626;
}

.master-pagination {
  align-items: center;
  border-top: 1px solid #edf1f5;
  color: #66758d;
  display: flex;
  font-size: 12px;
  font-weight: 750;
  gap: 12px;
  justify-content: flex-end;
  padding-top: 14px;
}

.master-pagination button {
  background: #fff;
  border: 1px solid #dbe4f0;
  border-radius: 8px;
  color: #334155;
  cursor: pointer;
  font-size: 11px;
  font-weight: 800;
  padding: 9px 12px;
}

.master-pagination button:disabled {
  cursor: not-allowed;
  opacity: .55;
}

.master-layout {
  display: block;
}

.master-drawer-tab {
  background: #f6f8fb;
  border: 1px solid #dfe6ef;
  border-radius: 8px;
  color: #5e6a7f;
  cursor: pointer;
  flex-shrink: 0;
  font-size: 11px;
  font-weight: 750;
  min-height: 34px;
  padding: 7px 13px;
}

.master-drawer-tab.active {
  background: #2457d6;
  border-color: #2457d6;
  color: #fff;
}

.master-form-card {
  align-content: start;
  background: #fff;
  border: 1px solid #e1e7f0;
  border-radius: 15px;
  box-shadow: 0 6px 20px rgba(27, 52, 87, 0.045);
  display: grid;
  gap: 18px;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  max-width: 100%;
  padding: 22px;
}

.master-section-header {
  align-items: flex-start;
  border-bottom: 1px solid #e8edf3;
  display: flex;
  gap: 13px;
  grid-column: 1 / -1;
  margin-bottom: 2px;
  padding-bottom: 16px;
}

.section-number {
  background: #eef3ff;
  border-radius: 8px;
  color: #2457d6;
  display: grid;
  flex-shrink: 0;
  font-size: 13px;
  font-weight: 850;
  height: 34px;
  place-items: center;
  width: 44px;
}

.master-section-header h3 {
  color: #15213a;
  font-size: 16px;
  font-weight: 850;
  line-height: 1.25;
  margin: 0;
}

.master-section-header p {
  color: #7a869a;
  font-size: 12px;
  font-weight: 650;
  margin: 3px 0 0;
}

.master-form-card .field-error {
  grid-column: 1 / -1;
}

.master-help-card {
  align-items: flex-start;
  background: #f7faff;
  border: 1px dashed #d8e3f6;
  border-radius: 12px;
  color: #66758d;
  display: flex;
  gap: 11px;
  grid-column: 1 / -1;
  margin-top: 2px;
  padding: 13px 14px;
}

.help-icon {
  background: #e8f0ff;
  border-radius: 50%;
  color: #2457d6;
  display: grid;
  flex-shrink: 0;
  font-size: 11px;
  font-weight: 850;
  height: 22px;
  place-items: center;
  width: 22px;
}

.master-help-card strong {
  color: #17233b;
  display: block;
  font-size: 12px;
  font-weight: 800;
  margin-bottom: 5px;
}

.master-help-card ul {
  display: grid;
  gap: 4px;
  list-style: disc;
  margin: 0;
  padding-left: 16px;
}

.master-help-card li {
  font-size: 11px;
  font-weight: 650;
  line-height: 1.45;
}

.field-error {
  border-radius: 8px;
  font-size: 12px;
  font-weight: 750;
  padding: 10px 12px;
}

@media (max-width: 1100px) {
  .master-panel .bill-card-head {
    grid-template-columns: 1fr;
  }

  .master-filters {
    justify-content: flex-start;
  }

  .hsn-summary-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 720px) {
  .master-panel {
    padding: 16px;
  }

  .master-filters,
  .master-filters input,
  .master-filters select,
  .primary-add,
  .secondary-action {
    width: 100%;
  }

  .hsn-summary-grid {
    grid-template-columns: 1fr;
  }

  .master-form-card {
    grid-template-columns: 1fr;
    padding: 17px 15px;
  }

  .master-form-card .field-error,
  .master-help-card {
    grid-column: auto;
  }
}
</style>
