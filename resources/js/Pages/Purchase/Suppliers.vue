<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import Layout from '../Layout.vue';
import PurchaseApi from './PurchaseApi';
import RowActionMenu from '../../Components/Common/RowActionMenu.vue';
import SearchSelect from '../../Components/Common/SearchSelect.vue';

defineProps({
    page: { type: String, default: 'suppliers' },
    title: { type: String, default: 'Suppliers' },
});

const suppliers = ref([]);
const references = ref({ states: [], cities: [] });
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });
const search = ref('');
const status = ref('');
const loading = ref(false);
const saving = ref(false);
const errors = ref({});
const openActionMenuId = ref(null);
const showSupplierDrawer = ref(false);
const statusFilters = [{ value: '', label: 'All Status' }, { value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }, { value: 'deleted', label: 'Deleted' }];
let timer = null;

const form = reactive({
    id: null,
    supplier_code: '',
    supplier_name: '',
    contact_person: '',
    mobile: '',
    phone: '',
    email: '',
    gstin: '',
    pan: '',
    billing_address: '',
    shipping_address: '',
    state_id: '',
    city: '',
    pincode: '',
    opening_balance: 0,
    opening_balance_type: 'credit',
    credit_limit: '',
    credit_days: '',
    status: 'active',
});

const reset = () => {
    Object.assign(form, {
        id: null,
        supplier_code: '',
        supplier_name: '',
        contact_person: '',
        mobile: '',
        phone: '',
        email: '',
        gstin: '',
        pan: '',
        billing_address: '',
        shipping_address: '',
        state_id: '',
        city: '',
        pincode: '',
        opening_balance: 0,
        opening_balance_type: 'credit',
        credit_limit: '',
        credit_days: '',
        status: 'active',
    });
    errors.value = {};
};

const openSupplierCreate = () => {
    reset();
    showSupplierDrawer.value = true;
};

const closeSupplierDrawer = () => {
    showSupplierDrawer.value = false;
    errors.value = {};
};

const filteredCities = computed(() => {
    if (!form.state_id) {
        return references.value.cities || [];
    }

    return (references.value.cities || []).filter((city) => Number(city.state_id) === Number(form.state_id));
});

const loadReferences = async () => {
    const response = await PurchaseApi.references();
    references.value = {
        states: response.states || [],
        cities: response.cities || [],
    };
};

const loadSuppliers = async (page = 1) => {
    loading.value = true;
    try {
        const response = await PurchaseApi.suppliers({ page, search: search.value, status: status.value });
        suppliers.value = response.suppliers || [];
        pagination.value = response.pagination || pagination.value;
    } finally {
        loading.value = false;
    }
};

const editSupplier = (supplier) => {
    Object.assign(form, supplier);
    errors.value = {};
    showSupplierDrawer.value = true;
};

const saveSupplier = async () => {
    saving.value = true;
    errors.value = {};
    try {
        const response = await PurchaseApi.saveSupplier({ ...form }, form.id);
        alert(response.message || 'Supplier saved.');
        reset();
        closeSupplierDrawer();
        await loadSuppliers();
    } catch (error) {
        if (error.response?.status === 422) {
            errors.value = error.response.data.errors || {};
            alert(Object.values(errors.value)?.[0]?.[0] || 'Please check supplier fields.');
            return;
        }
        const data = error.response?.data || {};
        alert(data.message || data.error || error.message || 'Supplier save nahi ho saka.');
    } finally {
        saving.value = false;
    }
};

const deleteSupplier = async (supplier) => {
    if (!window.confirm(`${supplier.supplier_name} delete karna hai?`)) return;
    await PurchaseApi.deleteSupplier(supplier.id);
    await loadSuppliers(pagination.value.current_page || 1);
};

const restoreSupplier = async (supplier) => {
    await PurchaseApi.restoreSupplier(supplier.id);
    await loadSuppliers(pagination.value.current_page || 1);
};
const toggleActionMenu = (supplier) => { openActionMenuId.value = openActionMenuId.value === supplier.id ? null : supplier.id; };
const closeActionMenu = () => { openActionMenuId.value = null; };

watch([search, status], () => {
    clearTimeout(timer);
    timer = setTimeout(() => loadSuppliers(1), 300);
});

watch(() => form.state_id, () => {
    if (form.city && !filteredCities.value.some((city) => city.name === form.city)) {
        form.city = '';
    }
});

onMounted(async () => { await loadReferences(); await loadSuppliers(); });
</script>

<template>
    <Layout :page="page" :title="title">
        <template #topbar-title>
            <div class="bill-page-title"><span>PURCHASE MANAGEMENT</span><h1>Supplier Master</h1><p>Maintain GST, credit and payable-ready supplier information.</p></div>
        </template>
        <div class="purchase-page">
            <div class="page-toolbar"><button type="button" class="primary" @click="openSupplierCreate">New Supplier</button></div>

            <div v-if="showSupplierDrawer" class="drawer-backdrop" @click.self="closeSupplierDrawer">
                <aside class="drawer-panel">
                    <div class="drawer-heading">
                        <div><span>PURCHASE MANAGEMENT</span><h2>{{ form.id ? 'Edit Supplier' : 'New Supplier' }}</h2></div>
                        <button type="button" @click="closeSupplierDrawer">Close</button>
                    </div>

                    <div class="form-grid">
                        <label>Supplier Code<input v-model="form.supplier_code" placeholder="Auto if blank" /></label>
                        <label><span>Supplier Name <span class="required-mark">*</span></span><input v-model="form.supplier_name" placeholder="Supplier Name" /></label>
                        <label>Contact Person<input v-model="form.contact_person" placeholder="Contact Person" /></label>
                        <label><span>Mobile <span class="required-mark">*</span></span><input v-model="form.mobile" placeholder="9876543210" /></label>
                        <label>Phone<input v-model="form.phone" placeholder="Phone" /></label>
                        <label>Email<input v-model="form.email" placeholder="Email" /></label>
                        <label>GSTIN<input v-model="form.gstin" maxlength="15" placeholder="15-character GSTIN" /></label>
                        <label>PAN<input v-model="form.pan" maxlength="10" placeholder="PAN" /></label>
                        <SearchSelect v-model="form.state_id" label="State" :options="references.states" option-value-key="id" option-label-key="name" select-placeholder="Select State" />
                        <SearchSelect v-model="form.city" label="City" :options="filteredCities" option-value-key="name" option-label-key="name" select-placeholder="Search City" required allow-custom />
                        <label>Pincode<input v-model="form.pincode" maxlength="12" placeholder="Pincode (optional)" /></label>
                        <label>Opening Balance<input v-model="form.opening_balance" type="number" min="0" step="0.01" placeholder="Opening Balance" /></label>
                        <SearchSelect v-model="form.opening_balance_type" label="Balance Type" :options="[{ value: 'credit', label: 'Credit' }, { value: 'debit', label: 'Debit' }]" option-value-key="value" option-label-key="label" select-placeholder="Select Balance Type" required />
                        <label>Credit Limit<input v-model="form.credit_limit" type="number" min="0" step="0.01" placeholder="Credit Limit" /></label>
                        <label>Credit Days<input v-model="form.credit_days" type="number" min="0" placeholder="Credit Days" /></label>
                        <SearchSelect v-model="form.status" label="Status" :options="[{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }]" option-value-key="value" option-label-key="label" select-placeholder="Select Status" required />
                        <label class="wide"><span>Billing Address <span class="required-mark">*</span></span><textarea v-model="form.billing_address" placeholder="Billing Address"></textarea></label>
                        <label class="wide">Shipping Address<textarea v-model="form.shipping_address" placeholder="Shipping Address"></textarea></label>
                    </div>
                    <div v-if="Object.keys(errors).length" class="error-box">
                        <span v-for="(messages, field) in errors" :key="field">{{ messages[0] }}</span>
                    </div>
                    <div class="actions">
                        <button type="button" @click="closeSupplierDrawer">Cancel</button>
                        <button type="button" class="primary" :disabled="saving" @click="saveSupplier">{{ saving ? 'Saving...' : 'Save Supplier' }}</button>
                    </div>
                </aside>
            </div>

            <section class="panel">
                <div class="toolbar">
                    <input v-model="search" placeholder="Search name, code, GSTIN, phone" />
                    <SearchSelect v-model="status" label="Status" :options="statusFilters" option-value-key="value" option-label-key="label" select-placeholder="All Status" />
                </div>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Code</th><th>Name</th><th>GSTIN</th><th>Mobile</th><th>City</th><th>Opening</th><th>Status</th><th>Actions</th>
                            </tr>
                        </thead>
                        <tbody :class="{ 'is-loading': loading }">
                            <template v-if="loading">
                                <tr v-for="index in 4" :key="`supplier-loading-${index}`" class="loading-row">
                                    <td v-for="cell in 8" :key="cell"><span class="skeleton-line" :class="`w-${(cell % 4) + 1}`"></span></td>
                                </tr>
                            </template>
                            <tr v-for="supplier in suppliers" :key="supplier.id">
                                <td>{{ supplier.supplier_code || '-' }}</td>
                                <td>{{ supplier.supplier_name }}</td>
                                <td>{{ supplier.gstin || '-' }}</td>
                                <td>{{ supplier.mobile || '-' }}</td>
                                <td>{{ supplier.city || '-' }}</td>
                                <td>{{ supplier.opening_balance }} {{ supplier.opening_balance_type }}</td>
                                <td><span class="badge" :class="supplier.status">{{ supplier.deleted_at ? 'deleted' : supplier.status }}</span></td>
                                <td>
                                    <div class="row-actions">
                                        <RowActionMenu :open="openActionMenuId === supplier.id" :show-view="false" more-label="Actions" more-title="Supplier actions" placement="top" @toggle="toggleActionMenu(supplier)">
                                            <button v-if="!supplier.deleted_at" type="button" @click="editSupplier(supplier); closeActionMenu()">Edit</button>
                                            <button v-if="!supplier.deleted_at" type="button" class="danger" @click="deleteSupplier(supplier); closeActionMenu()">Delete</button>
                                            <button v-else type="button" @click="restoreSupplier(supplier); closeActionMenu()">Restore</button>
                                        </RowActionMenu>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!suppliers.length && !loading"><td colspan="8" class="empty">No suppliers found.</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="pagination">
                    <button :disabled="pagination.current_page <= 1" @click="loadSuppliers(pagination.current_page - 1)">Previous</button>
                    <span>{{ pagination.from || 0 }}-{{ pagination.to || 0 }} of {{ pagination.total || 0 }}</span>
                    <button :disabled="pagination.current_page >= pagination.last_page" @click="loadSuppliers(pagination.current_page + 1)">Next</button>
                </div>
            </section>
        </div>
    </Layout>
</template>

<style scoped>
.purchase-page { padding: 4px 0 28px; }
.page-heading, .toolbar, .actions, .pagination, .row-actions, .drawer-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.page-toolbar { display: flex; justify-content: flex-end; margin-bottom: 14px; }
.page-heading { margin-bottom: 18px; }
.page-heading span, .drawer-heading span { color: #2457d6; font-size: 10px; font-weight: 800; letter-spacing: 1.2px; }
.page-heading h1 { margin: 0; color: #142139; font-weight: 800; }
.page-heading p { margin: 6px 0 0; color: #758197; font-size: 13px; }
.panel { margin-bottom: 18px; padding: 18px; background: #fff; border: 1px solid #dfe6ef; border-radius: 8px; }
.form-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
.form-grid label { display: grid; gap: 5px; color: #667085; font-size: 11px; font-weight: 800; }
.form-grid :deep(.search-select) { display: block; }
.toolbar :deep(.search-select) { min-width: 180px; }
.wide { grid-column: span 2; }
.required-mark { color: #dc2626; font-weight: 900; margin-left: 3px; }
input, select, textarea, button { min-height: 38px; padding: 8px 10px; color: #344159; background: #fff; border: 1px solid #d8e0eb; border-radius: 8px; font-size: 12px; }
textarea { min-height: 72px; resize: vertical; }
button { font-weight: 750; cursor: pointer; }
button.primary { color: #fff; background: #2457d6; border-color: #2457d6; box-shadow: 0 6px 14px rgba(36, 87, 214, .16); }
button.danger { color: #d23f49; background: #fff3f4; border-color: #ffd6da; }
.actions, .pagination { justify-content: flex-end; margin-top: 12px; }
.toolbar { margin-bottom: 12px; justify-content: flex-start; }
.toolbar input { min-width: 280px; }
.table-wrapper { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; }
th { padding: 12px 10px; color: #69758a; background: #f8fafc; border-bottom: 1px solid #e7ecf2; text-align: left; white-space: nowrap; font-size: 10px; font-weight: 800; text-transform: uppercase; }
td { padding: 12px 10px; color: #27344c; border-bottom: 1px solid #edf1f5; white-space: nowrap; font-size: 12px; }
.badge { padding: 5px 8px; border-radius: 7px; background: #edf2ff; color: #2457d6; font-size: 10px; font-weight: 800; text-transform: capitalize; }
.badge.active { color: #168757; background: #eaf8f1; }
.badge.inactive { color: #69758a; background: #f0f2f5; }
.empty { padding: 28px !important; color: #8490a2; text-align: center; }
.error-box { display: grid; gap: 4px; margin-top: 12px; padding: 10px; color: #96333a; background: #fff3f4; border: 1px solid #ffd4d8; border-radius: 8px; font-size: 11px; }
.is-loading tr:not(.loading-row) { opacity: .38; }
.loading-row td { padding: 14px 10px; }
.skeleton-line { display: block; width: 100%; height: 12px; border-radius: 999px; background: linear-gradient(90deg, #eef3fb 25%, #f8fbff 38%, #eef3fb 63%); background-size: 240% 100%; animation: shimmer 1.05s ease-in-out infinite; }
.skeleton-line.w-1 { width: 46%; }
.skeleton-line.w-2 { width: 64%; }
.skeleton-line.w-3 { width: 78%; }
.skeleton-line.w-4 { width: 92%; }
@keyframes shimmer { 0% { background-position: 120% 0; } 100% { background-position: -120% 0; } }
.drawer-backdrop { position: fixed; z-index: 950; inset: 0; display: flex; justify-content: flex-end; background: rgba(15, 23, 42, .38); }
.drawer-panel { width: min(980px, calc(100vw - 28px)); height: 100vh; overflow: auto; padding: 18px; background: #fff; border-left: 1px solid #dfe6ef; box-shadow: -24px 0 70px rgba(15, 23, 42, .2); }
.drawer-heading { position: sticky; z-index: 30; top: 0; margin: -18px -18px 16px; padding: 16px 18px; background: #fff; border-bottom: 1px solid #edf1f5; }
.drawer-heading h2 { margin: 2px 0 0; color: #142139; font-size: 20px; }
@media (max-width: 1000px) { .form-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } .wide { grid-column: span 2; } }
@media (max-width: 700px) { .page-heading, .toolbar, .drawer-heading { align-items: stretch; flex-direction: column; } .form-grid { grid-template-columns: 1fr; } .wide { grid-column: span 1; } .toolbar input, .toolbar :deep(.search-select) { min-width: 0; width: 100%; } .drawer-panel { width: 100vw; } }
</style>
