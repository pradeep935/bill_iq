<template>
  <Layout page="masters" title="Masters">
    <template #topbar-title>
      <div class="bill-page-title">
        <span>ADMIN SETUP</span>
        <h1>Masters</h1>
        <p>Maintain branch, warehouse, catalog and tax master records used across billing and inventory.</p>
      </div>
    </template>

    <div class="master-tabs">
      <button v-for="tab in tabs" :key="tab.key" :class="{ active: activeTab === tab.key }" @click="switchTab(tab.key)">
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
          <select v-model="filters.status" @change="loadRecords">
            <option value="">All Status</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
          <button type="button" class="primary-add" @click="openDrawer()">+ Add {{ current.singular }}</button>
        </div>
      </div>

      <div v-if="flash.message" :class="['master-flash', flash.type]">{{ flash.message }}</div>

      <div class="master-layout">
        <CrudTable
          :columns="current.columns"
          :rows="records"
          :loading="loading"
          :value-for="valueFor"
          @edit="editRecord"
          @delete="deleteRecord"
        />
      </div>
    </section>

    <CrudDrawer
      :model-value="drawerOpen"
      :title="editingId ? `Edit ${current.singular}` : `Add ${current.singular}`"
      :description="current.hint"
      :processing="saving"
      :save-label="editingId ? 'Update' : 'Save'"
      @close="closeDrawer"
      @save="saveRecord"
    >
      <template #tabs>
        <button v-for="tab in tabs" :key="tab.key" type="button" class="master-drawer-tab" :class="{ active: activeTab === tab.key }" @click="switchTab(tab.key)">
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
              <DrawerField v-model="form.city" label="City" />
              <DrawerField v-model="form.state" label="State" />
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
              <DrawerField v-model="form.hsn_code" label="HSN/SAC Code" hint="Enter the tax classification code used for GST invoices." required />
              <DrawerField v-model="form.chapter_code" label="Chapter Code" />
              <DrawerField v-model="form.gst_rate" label="GST Rate %" type="number" :min="0" :max="100" step="0.01" hint="Enter the GST percentage applied to this HSN/SAC code." number required />
              <DrawerField v-model="form.cess_rate" label="CESS Rate %" type="number" :min="0" :max="100" step="0.01" number />
              <DrawerField v-model="form.effective_from" label="Effective From" type="date" />
              <DrawerField v-model="form.effective_to" label="Effective To" type="date" />
              <DrawerField v-model="form.description" label="Description" as="textarea" hint="Describe the goods or services covered by this code." :span="2" required />
            </template>

            <DrawerField v-model="form.status" label="Status" as="select" hint="Keep active records available for selection in transactions." required>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </DrawerField>

            <div v-if="firstError" class="field-error">{{ firstError }}</div>

            <section class="master-help-card">
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
import { computed, onMounted, reactive, ref } from 'vue';
import axios from 'axios';
import CrudDrawer from '../../Components/Common/CrudDrawer.vue';
import CrudTable from '../../Components/Common/CrudTable.vue';
import DrawerField from '../../Components/Common/DrawerField.vue';
import Layout from '../Layout.vue';

const props = defineProps({
  initial_tab: { type: String, default: 'branch' },
});

const tabs = [
  { key: 'branch', label: 'Branches', singular: 'Branch', hint: 'Create business branches used in vouchers, payroll and inventory reports.', helpTitle: 'Where is this used?', helpPoints: ['Separates sales, purchases, payroll and reports by business location.', 'Links warehouses, employees and vouchers to the correct branch.', 'Helps generate branch-wise profit, stock and outstanding reports.'], columns: [{ key: 'name', label: 'Name' }, { key: 'type', label: 'Type' }, { key: 'city', label: 'City' }, { key: 'state', label: 'State' }] },
  { key: 'warehouse', label: 'Warehouses', singular: 'Warehouse', hint: 'Create warehouses and map each warehouse to a branch for stock transactions.', helpTitle: 'Where is this used?', helpPoints: ['Tracks stock receiving, issuing, transfers and adjustments by warehouse.', 'Supports multiple storage points under the same branch.', 'Shows the exact warehouse location in inventory reports.'], columns: [{ key: 'name', label: 'Name' }, { key: 'code', label: 'Code' }, { key: 'branch_id', label: 'Branch', type: 'branch' }] },
  { key: 'category', label: 'Categories', singular: 'Category', hint: 'Maintain product categories used in Product Master and stock reports.', helpTitle: 'Where is this used?', helpPoints: ['Groups products into broad catalog sections in Product Master.', 'Supports category-wise sales, purchase and inventory reporting.', 'Makes product search and filtering cleaner for daily billing.'], columns: [{ key: 'name', label: 'Name' }] },
  { key: 'subcategory', label: 'Sub Categories', singular: 'Sub Category', hint: 'Maintain sub categories under product categories for cleaner catalog grouping.', helpTitle: 'Where is this used?', helpPoints: ['Adds a more specific grouping under each product category.', 'Improves product filters, barcode labels and catalog organization.', 'Helps compare and report similar products together.'], columns: [{ key: 'name', label: 'Name' }, { key: 'parent_id', label: 'Category', type: 'category' }] },
  { key: 'brand', label: 'Brands', singular: 'Brand', hint: 'Maintain product brands used in Product Master, filters and reports.', helpTitle: 'Where is this used?', helpPoints: ['Tags products with the brand or manufacturer name.', 'Supports brand-wise sales, purchase and stock performance reporting.', 'Improves product search, filtering and billing selection.'], columns: [{ key: 'name', label: 'Name' }] },
  { key: 'unit', label: 'Units', singular: 'Unit', hint: 'Maintain units such as PCS, KG, LTR and BOX for billing and inventory quantities.', helpTitle: 'Where is this used?', helpPoints: ['Defines how quantities are entered in billing and inventory.', 'Keeps purchase and sales quantities in a consistent measurement format.', 'Improves accuracy in stock movement and valuation reports.'], columns: [{ key: 'code', label: 'Code' }, { key: 'name', label: 'Name' }] },
  { key: 'hsn', label: 'HSN/SAC', singular: 'HSN/SAC', hint: 'Maintain HSN/SAC tax codes and GST rates for taxable products and services.', helpTitle: 'Where is this used?', helpPoints: ['Applies the correct GST rate to products and services.', 'Provides tax classification for invoices, tax summaries and GST reports.', 'Keeps Product Master tax setup faster and consistent.'], columns: [{ key: 'hsn_code', label: 'Code' }, { key: 'description', label: 'Description' }, { key: 'gst_rate', label: 'GST %' }] },
];

const activeTab = ref(tabs.some((tab) => tab.key === props.initial_tab) ? props.initial_tab : 'branch');
const records = ref([]);
const references = reactive({ branches: [], categories: [] });
const filters = reactive({ search: '', status: '' });
const form = reactive({});
const errors = ref({});
const flash = reactive({ message: '', type: 'success' });
const loading = ref(false);
const saving = ref(false);
const editingId = ref(null);
const drawerOpen = ref(false);

const current = computed(() => tabs.find((tab) => tab.key === activeTab.value));
const firstError = computed(() => Object.values(errors.value || {})?.[0]?.[0] || '');

const defaults = () => ({
  name: '',
  type: '',
  address: '',
  city: '',
  state: '',
  branch_id: '',
  parent_id: '',
  code: '',
  hsn_code: '',
  description: '',
  chapter_code: '',
  gst_rate: 0,
  cess_rate: 0,
  effective_from: '',
  effective_to: '',
  status: 'active',
});

const resetForm = () => {
  editingId.value = null;
  errors.value = {};
  Object.assign(form, defaults());
};

const openDrawer = (record = null) => {
  resetForm();
  if (record) {
    editingId.value = record.id;
    Object.keys(defaults()).forEach((key) => {
      form[key] = record[key] ?? '';
    });
    form.status = record.status || 'active';
  }
  drawerOpen.value = true;
};

const closeDrawer = () => {
  drawerOpen.value = false;
  resetForm();
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
};

const loadRecords = async () => {
  loading.value = true;
  try {
    const { data } = await axios.get(`/app/setup/masters/${activeTab.value}/list`, { params: filters });
    records.value = data.data?.data || [];
  } finally {
    loading.value = false;
  }
};

const switchTab = async (tab) => {
  activeTab.value = tab;
  filters.search = '';
  filters.status = '';
  resetForm();
  await loadReferences();
  await loadRecords();
};

const payload = () => {
  const data = { status: form.status };
  const allowed = {
    branch: ['name', 'type', 'address', 'city', 'state', 'status'],
    warehouse: ['branch_id', 'name', 'code', 'status'],
    category: ['name', 'status'],
    subcategory: ['parent_id', 'name', 'status'],
    brand: ['name', 'status'],
    unit: ['code', 'name', 'status'],
    hsn: ['hsn_code', 'description', 'chapter_code', 'gst_rate', 'cess_rate', 'effective_from', 'effective_to', 'status'],
  }[activeTab.value];

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

const deleteRecord = async (record) => {
  if (!confirm(`Delete ${record.name || record.code || record.hsn_code}?`)) return;
  await axios.delete(`/app/setup/masters/${activeTab.value}/${record.id}`);
  notify('Record deleted successfully.');
  if (editingId.value === record.id) resetForm();
  await loadReferences();
  await loadRecords();
};

const valueFor = (record, column) => {
  if (column.type === 'branch') {
    return references.branches.find((branch) => Number(branch.id) === Number(record[column.key]))?.name || '-';
  }
  if (column.type === 'category') {
    return references.categories.find((category) => Number(category.id) === Number(record[column.key]))?.name || '-';
  }
  if (column.key === 'gst_rate') {
    return `${Number(record[column.key] || 0).toFixed(2)}%`;
  }
  return record[column.key] || '-';
};

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
.primary-add {
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

.master-panel {
  padding: 22px;
}

.bill-card-head p {
  color: #718198;
  margin-top: 4px;
}

.master-filters {
  display: flex;
  gap: 10px;
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
  .master-filters {
    flex-wrap: wrap;
  }
}

@media (max-width: 720px) {
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
