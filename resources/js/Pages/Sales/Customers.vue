<script setup>
import { onMounted, reactive, ref, watch } from 'vue';
import Layout from '../Layout.vue';
import SalesApi from './SalesApi';

defineProps({ page: { type: String, default: 'customers' }, title: { type: String, default: 'Customers' } });

const customers = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });
const search = ref('');
const status = ref('');
const type = ref('');
const loading = ref(false);
const saving = ref(false);
const errors = ref({});
let timer = null;

const form = reactive({
    id: null, customer_code: '', customer_name: '', customer_type: 'retail', contact_person: '',
    mobile: '', phone: '', email: '', gstin: '', pan: '', billing_address: '', shipping_address: '',
    state_id: '', city: '', pincode: '', opening_balance: 0, opening_balance_type: 'debit',
    credit_limit: '', credit_days: '', price_type: 'retail', status: 'active',
});

const reset = () => {
    Object.assign(form, {
        id: null, customer_code: '', customer_name: '', customer_type: 'retail', contact_person: '',
        mobile: '', phone: '', email: '', gstin: '', pan: '', billing_address: '', shipping_address: '',
        state_id: '', city: '', pincode: '', opening_balance: 0, opening_balance_type: 'debit',
        credit_limit: '', credit_days: '', price_type: 'retail', status: 'active',
    });
    errors.value = {};
};

const loadCustomers = async (page = 1) => {
    loading.value = true;
    try {
        const response = await SalesApi.customers({ page, search: search.value, status: status.value, type: type.value });
        customers.value = response.customers || [];
        pagination.value = response.pagination || pagination.value;
    } finally {
        loading.value = false;
    }
};

const editCustomer = (customer) => {
    Object.assign(form, customer);
    errors.value = {};
};

const saveCustomer = async () => {
    if (saving.value) return;
    saving.value = true;
    errors.value = {};
    try {
        const response = await SalesApi.saveCustomer({ ...form }, form.id);
        alert(response.message || 'Customer saved.');
        reset();
        await loadCustomers();
    } catch (error) {
        if (error.response?.status === 422) {
            errors.value = error.response.data.errors || {};
            alert(Object.values(errors.value)?.[0]?.[0] || 'Please check customer fields.');
            return;
        }
        alert(error.response?.data?.message || 'Customer save nahi ho saka.');
    } finally {
        saving.value = false;
    }
};

const deleteCustomer = async (customer) => {
    if (!window.confirm(`${customer.customer_name} delete karna hai?`)) return;
    await SalesApi.deleteCustomer(customer.id);
    await loadCustomers(pagination.value.current_page || 1);
};

const restoreCustomer = async (customer) => {
    await SalesApi.restoreCustomer(customer.id);
    await loadCustomers(pagination.value.current_page || 1);
};

watch([search, status, type], () => {
    clearTimeout(timer);
    timer = setTimeout(() => loadCustomers(1), 300);
});

onMounted(() => loadCustomers());
</script>

<template>
    <Layout :page="page" :title="title">
        <div class="sales-page">
            <div class="page-heading">
                <div><span>SALES MANAGEMENT</span><h1>Customer Master</h1><p>Maintain customer GST, credit and ledger-ready billing details.</p></div>
                <button type="button" @click="reset">New Customer</button>
            </div>

            <section class="panel">
                <div class="form-grid">
                    <input v-model="form.customer_code" placeholder="Customer Code" />
                    <input v-model="form.customer_name" placeholder="Customer Name" />
                    <select v-model="form.customer_type"><option value="retail">Retail</option><option value="wholesale">Wholesale</option><option value="dealer">Dealer</option><option value="distributor">Distributor</option><option value="corporate">Corporate</option><option value="walk_in">Walk-in</option></select>
                    <input v-model="form.contact_person" placeholder="Contact Person" />
                    <input v-model="form.mobile" placeholder="Mobile" />
                    <input v-model="form.phone" placeholder="Phone" />
                    <input v-model="form.email" placeholder="Email" />
                    <input v-model="form.gstin" placeholder="GSTIN" />
                    <input v-model="form.pan" placeholder="PAN" />
                    <input v-model="form.city" placeholder="City" />
                    <input v-model="form.pincode" placeholder="Pincode" />
                    <input v-model="form.state_id" type="number" placeholder="State ID" />
                    <input v-model="form.opening_balance" type="number" placeholder="Opening Balance" />
                    <select v-model="form.opening_balance_type"><option value="debit">Debit</option><option value="credit">Credit</option></select>
                    <input v-model="form.credit_limit" type="number" placeholder="Credit Limit" />
                    <input v-model="form.credit_days" type="number" placeholder="Credit Days" />
                    <select v-model="form.price_type"><option value="retail">Retail Price</option><option value="wholesale">Wholesale Price</option><option value="dealer">Dealer Price</option><option value="online">Online Price</option></select>
                    <select v-model="form.status"><option value="active">Active</option><option value="inactive">Inactive</option></select>
                    <textarea v-model="form.billing_address" placeholder="Billing Address"></textarea>
                    <textarea v-model="form.shipping_address" placeholder="Shipping Address"></textarea>
                </div>
                <div v-if="Object.keys(errors).length" class="error-box"><span v-for="(messages, field) in errors" :key="field">{{ messages[0] }}</span></div>
                <div class="actions"><button type="button" :disabled="saving" @click="saveCustomer">{{ saving ? 'Saving...' : 'Save Customer' }}</button></div>
            </section>

            <section class="panel">
                <div class="toolbar">
                    <input v-model="search" placeholder="Search name, code, phone, email, GSTIN" />
                    <select v-model="type"><option value="">All Types</option><option value="retail">Retail</option><option value="wholesale">Wholesale</option><option value="dealer">Dealer</option><option value="corporate">Corporate</option><option value="walk_in">Walk-in</option></select>
                    <select v-model="status"><option value="">All Status</option><option value="active">Active</option><option value="inactive">Inactive</option><option value="deleted">Deleted</option></select>
                </div>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>Code</th><th>Name</th><th>Type</th><th>GSTIN</th><th>Mobile</th><th>Credit Limit</th><th>Price</th><th>Status</th><th>Actions</th></tr></thead>
                        <tbody>
                            <tr v-for="customer in customers" :key="customer.id">
                                <td>{{ customer.customer_code }}</td><td>{{ customer.customer_name }}</td><td>{{ customer.customer_type }}</td><td>{{ customer.gstin || '-' }}</td><td>{{ customer.mobile || customer.phone || '-' }}</td><td>{{ customer.credit_limit || '-' }}</td><td>{{ customer.price_type || '-' }}</td><td><span class="badge" :class="customer.deleted_at ? 'deleted' : customer.status">{{ customer.deleted_at ? 'deleted' : customer.status }}</span></td>
                                <td><button v-if="!customer.deleted_at" @click="editCustomer(customer)">Edit</button><button v-if="!customer.deleted_at && customer.customer_type !== 'walk_in'" class="danger" @click="deleteCustomer(customer)">Delete</button><button v-if="customer.deleted_at" @click="restoreCustomer(customer)">Restore</button></td>
                            </tr>
                            <tr v-if="!customers.length && !loading"><td colspan="9" class="empty">No customers found.</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="pagination"><button :disabled="pagination.current_page <= 1" @click="loadCustomers(pagination.current_page - 1)">Previous</button><span>{{ pagination.from || 0 }}-{{ pagination.to || 0 }} of {{ pagination.total || 0 }}</span><button :disabled="pagination.current_page >= pagination.last_page" @click="loadCustomers(pagination.current_page + 1)">Next</button></div>
            </section>
        </div>
    </Layout>
</template>

<style scoped>
.sales-page{padding:4px 0 28px}.page-heading,.toolbar,.actions,.pagination{display:flex;align-items:center;justify-content:space-between;gap:12px}.page-heading{margin-bottom:18px}.page-heading span{color:#2457d6;font-size:10px;font-weight:800;letter-spacing:1.2px}.page-heading h1{margin:0;color:#142139;font-weight:800}.page-heading p{margin:6px 0 0;color:#758197;font-size:13px}.panel{margin-bottom:18px;padding:18px;background:#fff;border:1px solid #dfe6ef;border-radius:8px}.form-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}input,select,textarea,button{min-height:38px;padding:8px 10px;color:#344159;background:#fff;border:1px solid #d8e0eb;border-radius:8px;font-size:12px}textarea{min-height:72px;resize:vertical}button{font-weight:750;cursor:pointer}.danger{color:#d23f49;background:#fff3f4;border-color:#ffd6da}.actions,.pagination{justify-content:flex-end;margin-top:12px}.toolbar{justify-content:flex-start;margin-bottom:12px}.toolbar input{min-width:280px}.table-wrapper{overflow-x:auto}table{width:100%;border-collapse:collapse}th{padding:12px 10px;color:#69758a;background:#f8fafc;border-bottom:1px solid #e7ecf2;text-align:left;white-space:nowrap;font-size:10px;font-weight:800;text-transform:uppercase}td{padding:12px 10px;color:#27344c;border-bottom:1px solid #edf1f5;white-space:nowrap;font-size:12px}.badge{padding:5px 8px;border-radius:7px;background:#edf2ff;color:#2457d6;font-size:10px;font-weight:800;text-transform:capitalize}.badge.active{color:#168757;background:#eaf8f1}.badge.inactive,.badge.deleted{color:#69758a;background:#f0f2f5}.empty{padding:28px!important;color:#8490a2;text-align:center}.error-box{display:grid;gap:4px;margin-top:12px;padding:10px;color:#96333a;background:#fff3f4;border:1px solid #ffd4d8;border-radius:8px;font-size:11px}@media(max-width:1000px){.form-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:700px){.page-heading,.toolbar{align-items:stretch;flex-direction:column}.form-grid{grid-template-columns:1fr}.toolbar input{min-width:0;width:100%}}
</style>
