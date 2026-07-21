<script setup>
import { onMounted, reactive, ref, watch } from 'vue';
import Layout from '../Layout.vue';
import PurchaseApi from './PurchaseApi';

defineProps({
    page: { type: String, default: 'suppliers' },
    title: { type: String, default: 'Suppliers' },
});

const suppliers = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });
const search = ref('');
const status = ref('');
const loading = ref(false);
const saving = ref(false);
const errors = ref({});
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
};

const saveSupplier = async () => {
    saving.value = true;
    errors.value = {};
    try {
        const response = await PurchaseApi.saveSupplier({ ...form }, form.id);
        alert(response.message || 'Supplier saved.');
        reset();
        await loadSuppliers();
    } catch (error) {
        if (error.response?.status === 422) {
            errors.value = error.response.data.errors || {};
            alert(Object.values(errors.value)?.[0]?.[0] || 'Please check supplier fields.');
            return;
        }
        alert(error.response?.data?.message || 'Supplier save nahi ho saka.');
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

watch([search, status], () => {
    clearTimeout(timer);
    timer = setTimeout(() => loadSuppliers(1), 300);
});

onMounted(() => loadSuppliers());
</script>

<template>
    <Layout :page="page" :title="title">
        <div class="purchase-page">
            <div class="page-heading">
                <div>
                    <span>PURCHASE MANAGEMENT</span>
                    <h1>Supplier Master</h1>
                    <p>Maintain GST, credit and payable-ready supplier information.</p>
                </div>
                <button type="button" @click="reset">New Supplier</button>
            </div>

            <section class="panel">
                <div class="form-grid">
                    <input v-model="form.supplier_code" placeholder="Supplier Code" />
                    <input v-model="form.supplier_name" placeholder="Supplier Name" />
                    <input v-model="form.contact_person" placeholder="Contact Person" />
                    <input v-model="form.mobile" placeholder="Mobile" />
                    <input v-model="form.phone" placeholder="Phone" />
                    <input v-model="form.email" placeholder="Email" />
                    <input v-model="form.gstin" placeholder="GSTIN" />
                    <input v-model="form.pan" placeholder="PAN" />
                    <input v-model="form.city" placeholder="City" />
                    <input v-model="form.pincode" placeholder="Pincode" />
                    <input v-model="form.opening_balance" type="number" placeholder="Opening Balance" />
                    <select v-model="form.opening_balance_type">
                        <option value="credit">Credit</option>
                        <option value="debit">Debit</option>
                    </select>
                    <input v-model="form.credit_limit" type="number" placeholder="Credit Limit" />
                    <input v-model="form.credit_days" type="number" placeholder="Credit Days" />
                    <select v-model="form.status">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                    <input v-model="form.state_id" type="number" placeholder="State ID" />
                    <textarea v-model="form.billing_address" placeholder="Billing Address"></textarea>
                    <textarea v-model="form.shipping_address" placeholder="Shipping Address"></textarea>
                </div>
                <div v-if="Object.keys(errors).length" class="error-box">
                    <span v-for="(messages, field) in errors" :key="field">{{ messages[0] }}</span>
                </div>
                <div class="actions">
                    <button type="button" :disabled="saving" @click="saveSupplier">{{ saving ? 'Saving...' : 'Save Supplier' }}</button>
                </div>
            </section>

            <section class="panel">
                <div class="toolbar">
                    <input v-model="search" placeholder="Search name, code, GSTIN, phone" />
                    <select v-model="status">
                        <option value="">All Status</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="deleted">Deleted</option>
                    </select>
                </div>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Code</th><th>Name</th><th>GSTIN</th><th>Mobile</th><th>City</th><th>Opening</th><th>Status</th><th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="supplier in suppliers" :key="supplier.id">
                                <td>{{ supplier.supplier_code || '-' }}</td>
                                <td>{{ supplier.supplier_name }}</td>
                                <td>{{ supplier.gstin || '-' }}</td>
                                <td>{{ supplier.mobile || '-' }}</td>
                                <td>{{ supplier.city || '-' }}</td>
                                <td>{{ supplier.opening_balance }} {{ supplier.opening_balance_type }}</td>
                                <td><span class="badge" :class="supplier.status">{{ supplier.deleted_at ? 'deleted' : supplier.status }}</span></td>
                                <td>
                                    <button v-if="!supplier.deleted_at" type="button" @click="editSupplier(supplier)">Edit</button>
                                    <button v-if="!supplier.deleted_at" type="button" class="danger" @click="deleteSupplier(supplier)">Delete</button>
                                    <button v-else type="button" @click="restoreSupplier(supplier)">Restore</button>
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
.page-heading, .toolbar, .actions, .pagination { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.page-heading { margin-bottom: 18px; }
.page-heading span { color: #2457d6; font-size: 10px; font-weight: 800; letter-spacing: 1.2px; }
.page-heading h1 { margin: 0; color: #142139; font-weight: 800; }
.page-heading p { margin: 6px 0 0; color: #758197; font-size: 13px; }
.panel { margin-bottom: 18px; padding: 18px; background: #fff; border: 1px solid #dfe6ef; border-radius: 14px; }
.form-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
input, select, textarea, button { min-height: 38px; padding: 8px 10px; color: #344159; background: #fff; border: 1px solid #d8e0eb; border-radius: 8px; font-size: 12px; }
textarea { min-height: 72px; resize: vertical; }
button { font-weight: 750; cursor: pointer; }
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
@media (max-width: 1000px) { .form-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 700px) { .page-heading, .toolbar { align-items: stretch; flex-direction: column; } .form-grid { grid-template-columns: 1fr; } .toolbar input { min-width: 0; width: 100%; } }
</style>
