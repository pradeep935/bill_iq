<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import Layout from '../Layout.vue';
import SalesApi from './SalesApi';

defineProps({ page: { type: String, default: 'customers' }, title: { type: String, default: 'Customers' } });

const customers = ref([]);
const references = ref({ customer_types: [], price_types: [], states: [], branches: [] });
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });
const search = ref('');
const status = ref('');
const type = ref('');
const stateId = ref('');
const priceType = ref('');
const crmStatus = ref('');
const loading = ref(false);
const saving = ref(false);
const importing = ref(false);
const errors = ref({});
const detail = ref(null);
const ledger = ref([]);
const outstanding = ref([]);
let timer = null;

const form = reactive({
    id: null, customer_code: '', customer_name: '', customer_type: 'retail', contact_person: '',
    mobile: '', whatsapp_number: '', whatsapp_same_as_mobile: true, phone: '', email: '', gstin: '', pan: '', billing_address: '', shipping_address: '',
    state_id: '', city: '', pincode: '', opening_balance: 0, opening_balance_type: 'debit',
    credit_limit: '', credit_days: '', price_type: 'retail', status: 'active', blocked_reason: '',
});

const sameAsBilling = ref(false);
const queryParams = computed(() => ({ page: pagination.value.current_page, search: search.value, status: status.value, type: type.value, state_id: stateId.value, price_type: priceType.value, crm_status: crmStatus.value }));

const reset = () => {
    Object.assign(form, {
        id: null, customer_code: '', customer_name: '', customer_type: 'retail', contact_person: '',
        mobile: '', whatsapp_number: '', whatsapp_same_as_mobile: true, phone: '', email: '', gstin: '', pan: '', billing_address: '', shipping_address: '',
        state_id: '', city: '', pincode: '', opening_balance: 0, opening_balance_type: 'debit',
        credit_limit: '', credit_days: '', price_type: 'retail', status: 'active', blocked_reason: '',
    });
    sameAsBilling.value = false; errors.value = {}; detail.value = null; ledger.value = []; outstanding.value = [];
};

const loadReferences = async () => { references.value = await SalesApi.customerReferences(); };
const loadCustomers = async (page = 1) => {
    loading.value = true;
    try {
        const response = await SalesApi.customers({ ...queryParams.value, page });
        customers.value = response.customers || [];
        pagination.value = response.pagination || pagination.value;
    } finally {
        loading.value = false;
    }
};

const editCustomer = (customer) => {
    Object.assign(form, customer);
    sameAsBilling.value = !!customer.billing_address && customer.billing_address === customer.shipping_address;
    errors.value = {};
};

const viewCustomer = async (customer) => {
    const response = await SalesApi.getCustomer(customer.id);
    detail.value = response.customer;
    ledger.value = response.customer?.ledger_preview || [];
    outstanding.value = [];
};

const viewLedger = async (customer) => {
    const response = await SalesApi.customerLedger(customer.id);
    detail.value = response.customer;
    ledger.value = response.entries || [];
    outstanding.value = [];
};

const viewOutstanding = async (customer) => {
    const response = await SalesApi.customerOutstanding(customer.id);
    detail.value = response.customer;
    outstanding.value = response.items || [];
    ledger.value = [];
};

const saveCustomer = async () => {
    if (saving.value) return;
    saving.value = true;
    errors.value = {};
    try {
        const response = await SalesApi.saveCustomer({ ...form, shipping_address: sameAsBilling.value ? form.billing_address : form.shipping_address }, form.id);
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

const toggleStatus = async (customer) => {
    const response = customer.status === 'active' ? await SalesApi.deactivateCustomer(customer.id) : await SalesApi.activateCustomer(customer.id);
    alert(response.message || 'Status updated.');
    await loadCustomers(pagination.value.current_page || 1);
};

const importCustomers = async (event) => {
    const file = event.target.files?.[0];
    if (!file) return;
    importing.value = true;
    try {
        const response = await SalesApi.importCustomers(file);
        alert(`${response.message} Created: ${response.created || 0}`);
        await loadCustomers(1);
    } catch (error) {
        alert(error.response?.data?.message || 'Customer import nahi ho saka.');
    } finally {
        importing.value = false;
        event.target.value = '';
    }
};

const exportCustomers = () => { window.location.href = SalesApi.customerExportUrl(queryParams.value); };
const clearFilters = () => { search.value = ''; status.value = ''; type.value = ''; stateId.value = ''; priceType.value = ''; crmStatus.value = ''; loadCustomers(1); };
const createInvoice = (customer) => { window.location.href = `/app/sales/invoices/create?customer_id=${customer.id}`; };
const createReturn = (customer) => { window.location.href = `/app/sales/returns/create?customer_id=${customer.id}`; };
const receivePayment = (customer) => { window.location.href = `/app/accounting/vouchers?type=receipt&customer_id=${customer.id}`; };
const openInvoicePrint = (id) => { window.open(`/app/sales/invoices/${id}/print`, '_blank', 'noopener'); };
const formatMoney = (value) => `Rs. ${Number(value || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

watch(sameAsBilling, (checked) => { if (checked) form.shipping_address = form.billing_address; });
watch(() => form.billing_address, (value) => { if (sameAsBilling.value) form.shipping_address = value; });
watch(() => form.whatsapp_same_as_mobile, (checked) => { if (checked) form.whatsapp_number = form.mobile; });
watch(() => form.mobile, (value) => { if (form.whatsapp_same_as_mobile) form.whatsapp_number = value; });
watch([search, status, type, stateId, priceType, crmStatus], () => {
    clearTimeout(timer);
    timer = setTimeout(() => loadCustomers(1), 300);
});

onMounted(async () => { await loadReferences(); await loadCustomers(); });
</script>

<template>
    <Layout :page="page" :title="title">
        <template #topbar-title>
            <div class="bill-page-title"><span>SALES MANAGEMENT</span><h1>Customer Master</h1><p>Maintain customer GST, credit and ledger-ready billing details.</p></div>
        </template>
        <div class="sales-page">
            <div class="page-toolbar">
                <button type="button" @click="reset">New Customer</button>
                <div class="toolbar-actions">
                    <a :href="SalesApi.customerImportTemplateUrl()">Import Template</a>
                    <label class="import-button">{{ importing ? 'Importing...' : 'Import' }}<input type="file" accept=".csv,text/csv" :disabled="importing" @change="importCustomers" /></label>
                    <button type="button" @click="exportCustomers">Export</button>
                </div>
            </div>

            <section class="panel">
                <div class="form-grid">
                    <label>Customer Code<input v-model="form.customer_code" placeholder="Auto if blank" /></label>
                    <label>Customer Name *<input v-model="form.customer_name" placeholder="Customer Name" /></label>
                    <label>Customer Type *<select v-model="form.customer_type"><option v-for="item in references.customer_types" :key="item.value" :value="item.value">{{ item.label }}</option></select></label>
                    <label>Contact Person<input v-model="form.contact_person" placeholder="Contact Person" /></label>
                    <label>Mobile<input v-model="form.mobile" placeholder="9876543210" /></label>
                    <label class="check"><input v-model="form.whatsapp_same_as_mobile" type="checkbox" /> WhatsApp same as mobile</label>
                    <label v-if="!form.whatsapp_same_as_mobile">WhatsApp<input v-model="form.whatsapp_number" placeholder="9876543210" /></label>
                    <label>Phone<input v-model="form.phone" placeholder="Phone" /></label>
                    <label>Email<input v-model="form.email" placeholder="Email" /></label>
                    <label>GSTIN<input v-model="form.gstin" maxlength="15" placeholder="15-character GSTIN" /></label>
                    <label>PAN<input v-model="form.pan" maxlength="10" placeholder="PAN" /></label>
                    <label>City<input v-model="form.city" placeholder="City" /></label>
                    <label>Pincode<input v-model="form.pincode" maxlength="6" placeholder="Pincode" /></label>
                    <label>State<select v-model="form.state_id"><option value="">Select State</option><option v-for="state in references.states" :key="state.id" :value="state.id">{{ state.name }}</option></select></label>
                    <label>Opening Balance<input v-model="form.opening_balance" type="number" min="0" step="0.01" placeholder="Opening Balance" /></label>
                    <label>Balance Type<select v-model="form.opening_balance_type"><option value="debit">Debit</option><option value="credit">Credit</option></select></label>
                    <label>Credit Limit<input v-model="form.credit_limit" type="number" min="0" step="0.01" placeholder="Credit Limit" /></label>
                    <label>Credit Days<input v-model="form.credit_days" type="number" min="0" placeholder="Credit Days" /></label>
                    <label>Price List<select v-model="form.price_type"><option v-for="item in references.price_types" :key="item.value" :value="item.value">{{ item.label }}</option></select></label>
                    <label>Status<select v-model="form.status"><option value="active">Active</option><option value="inactive">Inactive</option><option value="blocked">Blocked</option></select></label>
                    <label v-if="form.status === 'blocked'" class="wide">Block Reason<textarea v-model="form.blocked_reason" placeholder="Reason for blocking this customer"></textarea></label>
                    <label class="wide">Billing Address<textarea v-model="form.billing_address" placeholder="Billing Address"></textarea></label>
                    <label class="wide check"><input v-model="sameAsBilling" type="checkbox" /> Same as billing address</label>
                    <label class="wide">Shipping Address<textarea v-model="form.shipping_address" placeholder="Shipping Address" :disabled="sameAsBilling"></textarea></label>
                </div>
                <div v-if="Object.keys(errors).length" class="error-box"><span v-for="(messages, field) in errors" :key="field">{{ messages[0] }}</span></div>
                <div class="actions"><button type="button" :disabled="saving" @click="saveCustomer">{{ saving ? 'Saving Customer...' : 'Save Customer' }}</button></div>
            </section>

            <section class="panel">
                <div class="toolbar">
                    <input v-model="search" placeholder="Search name, code, phone, email, GSTIN" />
                    <select v-model="type"><option value="">All Types</option><option v-for="item in references.customer_types" :key="item.value" :value="item.value">{{ item.label }}</option></select>
                    <select v-model="status"><option value="">All Status</option><option value="active">Active</option><option value="inactive">Inactive</option><option value="blocked">Blocked</option><option value="deleted">Deleted</option></select>
                    <select v-model="crmStatus"><option value="">All CRM Status</option><option value="new">New</option><option value="repeat">Repeat</option><option value="regular">Regular</option><option value="inactive">Inactive</option></select>
                    <select v-model="stateId"><option value="">All States</option><option v-for="state in references.states" :key="state.id" :value="state.id">{{ state.name }}</option></select>
                    <select v-model="priceType"><option value="">All Price Lists</option><option v-for="item in references.price_types" :key="item.value" :value="item.value">{{ item.label }}</option></select>
                    <button type="button" @click="clearFilters">Clear Filters</button>
                </div>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>Code</th><th>Name</th><th>Type</th><th>GSTIN</th><th>Mobile</th><th>WhatsApp</th><th>CRM</th><th>Orders</th><th>Lifetime</th><th>Last Purchase</th><th>Outstanding</th><th>Status</th><th>Actions</th></tr></thead>
                        <tbody>
                            <tr v-for="customer in customers" :key="customer.id">
                                <td>{{ customer.customer_code }}</td><td>{{ customer.customer_name }}</td><td>{{ customer.customer_type }}</td><td>{{ customer.gstin || '-' }}</td><td>{{ customer.mobile || customer.phone || '-' }}</td><td>{{ customer.whatsapp_number || customer.mobile || '-' }}</td><td><span class="badge crm" :class="customer.crm_summary?.customer_status">{{ customer.crm_summary?.customer_status_label || '-' }}</span></td><td>{{ customer.crm_summary?.total_orders || 0 }}</td><td>{{ formatMoney(customer.crm_summary?.lifetime_sales) }}</td><td>{{ customer.crm_summary?.last_purchase_date || '-' }}</td><td>{{ formatMoney(customer.current_outstanding) }}</td><td><span class="badge" :class="customer.deleted_at ? 'deleted' : customer.status">{{ customer.deleted_at ? 'deleted' : customer.status }}</span></td>
                                <td><div class="row-actions"><button v-if="!customer.deleted_at" @click="viewCustomer(customer)">View</button><button v-if="!customer.deleted_at" @click="editCustomer(customer)">Edit</button><button v-if="!customer.deleted_at" @click="viewLedger(customer)">Ledger</button><button v-if="!customer.deleted_at" @click="viewOutstanding(customer)">Outstanding</button><button v-if="!customer.deleted_at" @click="receivePayment(customer)">Receive</button><button v-if="!customer.deleted_at" @click="createInvoice(customer)">Invoice</button><button v-if="!customer.deleted_at" @click="createReturn(customer)">Return</button><button v-if="!customer.deleted_at" @click="toggleStatus(customer)">{{ customer.status === 'active' ? 'Deactivate' : 'Activate' }}</button><button v-if="!customer.deleted_at && customer.customer_type !== 'walk_in'" class="danger" @click="deleteCustomer(customer)">Delete</button><button v-if="customer.deleted_at" @click="restoreCustomer(customer)">Restore</button></div></td>
                            </tr>
                            <tr v-if="!customers.length && !loading"><td colspan="13" class="empty">No customers found for the selected filters.</td></tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="pagination.total > 0 && pagination.last_page > 1" class="pagination"><button :disabled="pagination.current_page <= 1" @click="loadCustomers(pagination.current_page - 1)">Previous</button><span>{{ pagination.from || 0 }}-{{ pagination.to || 0 }} of {{ pagination.total || 0 }}</span><button :disabled="pagination.current_page >= pagination.last_page" @click="loadCustomers(pagination.current_page + 1)">Next</button></div>
            </section>

            <section v-if="detail" class="panel detail-panel">
                <div class="detail-head"><div><span>CUSTOMER DETAIL</span><h2>{{ detail.customer_name }}</h2><p>{{ detail.customer_code }} | {{ detail.mobile || '-' }} | WhatsApp {{ detail.whatsapp_number || detail.mobile || '-' }} | {{ detail.gstin || '-' }}</p></div><button type="button" @click="detail = null; ledger = []; outstanding = []">Close</button></div>
                <div class="summary-grid"><span>CRM Status <b>{{ detail.crm_summary?.customer_status_label || '-' }}</b></span><span>Total Orders <b>{{ detail.crm_summary?.total_orders || 0 }}</b></span><span>Lifetime Sales <b>{{ formatMoney(detail.crm_summary?.lifetime_sales) }}</b></span><span>Average Order <b>{{ formatMoney(detail.crm_summary?.average_order_value) }}</b></span><span>Outstanding <b>{{ formatMoney(detail.current_outstanding) }}</b></span><span>Total Paid <b>{{ formatMoney(detail.crm_summary?.total_paid) }}</b></span><span>First Purchase <b>{{ detail.crm_summary?.first_purchase_date || '-' }}</b></span><span>Last Purchase <b>{{ detail.crm_summary?.last_purchase_date || '-' }}</b></span></div>
                <div v-if="detail.recent_sales?.length" class="table-wrapper"><table><thead><tr><th>Invoice</th><th>Date</th><th>Total</th><th>Payment</th><th>Status</th><th>Actions</th></tr></thead><tbody><tr v-for="row in detail.recent_sales" :key="row.id"><td>{{ row.invoice_number }}</td><td>{{ row.invoice_date }}</td><td>{{ formatMoney(row.grand_total) }}</td><td>{{ row.payment_status }}</td><td>{{ row.status }}</td><td><div class="row-actions"><a :href="`/app/sales/invoices/${row.id}/print`" target="_blank">Print</a><button @click="openInvoicePrint(row.id)">View</button></div></td></tr></tbody></table></div>
                <div v-if="detail.product_history?.length" class="table-wrapper"><table><thead><tr><th>Product</th><th>Total Qty</th><th>Purchases</th><th>Last Purchase</th><th>Last Price</th><th>Avg Price</th></tr></thead><tbody><tr v-for="row in detail.product_history" :key="row.product_id"><td>{{ row.product }}</td><td>{{ row.total_quantity }}</td><td>{{ row.purchase_count }}</td><td>{{ row.last_purchase_date || '-' }}</td><td>{{ formatMoney(row.last_selling_price) }}</td><td>{{ formatMoney(row.average_selling_price) }}</td></tr></tbody></table></div>
                <div v-if="ledger.length" class="table-wrapper"><table><thead><tr><th>Date</th><th>Voucher</th><th>Reference</th><th>Debit</th><th>Credit</th><th>Running</th><th>Branch</th><th>Narration</th></tr></thead><tbody><tr v-for="(row,index) in ledger" :key="index"><td>{{ row.date }}</td><td>{{ row.voucher_type }}</td><td>{{ row.reference }}</td><td>{{ formatMoney(row.debit) }}</td><td>{{ formatMoney(row.credit) }}</td><td>{{ formatMoney(row.running_balance) }}</td><td>{{ row.branch || '-' }}</td><td>{{ row.narration || '-' }}</td></tr></tbody></table></div>
                <div v-if="outstanding.length" class="table-wrapper"><table><thead><tr><th>Invoice</th><th>Date</th><th>Due Date</th><th>Total</th><th>Paid</th><th>Balance</th><th>Overdue</th><th>Ageing</th></tr></thead><tbody><tr v-for="row in outstanding" :key="row.invoice_number"><td>{{ row.invoice_number }}</td><td>{{ row.invoice_date }}</td><td>{{ row.due_date }}</td><td>{{ formatMoney(row.invoice_total) }}</td><td>{{ formatMoney(row.paid) }}</td><td>{{ formatMoney(row.balance) }}</td><td>{{ row.days_overdue }}</td><td>{{ row.ageing_bucket }}</td></tr></tbody></table></div>
                <p v-if="!ledger.length && !outstanding.length" class="empty">Select Ledger or Outstanding to view financial details.</p>
            </section>
        </div>
    </Layout>
</template>

<style scoped>
.sales-page{padding:4px 0 28px}.page-heading,.toolbar,.actions,.pagination,.page-toolbar,.toolbar-actions,.row-actions,.detail-head{display:flex;align-items:center;justify-content:space-between;gap:12px}.page-heading{margin-bottom:18px}.page-heading span,.detail-head span{color:#2457d6;font-size:10px;font-weight:800;letter-spacing:1.2px}.page-heading h1,.detail-head h2{margin:0;color:#142139;font-weight:800}.page-heading p,.detail-head p{margin:6px 0 0;color:#758197;font-size:13px}.panel{margin-bottom:18px;padding:18px;background:#fff;border:1px solid #dfe6ef;border-radius:8px}.form-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.form-grid label{display:grid;gap:5px;color:#667085;font-size:11px;font-weight:800}.wide{grid-column:span 2}.check{display:flex!important;align-items:center;justify-content:flex-start}.check input{min-height:auto;width:auto}input,select,textarea,button,.toolbar-actions a,.import-button{min-height:38px;padding:8px 10px;color:#344159;background:#fff;border:1px solid #d8e0eb;border-radius:8px;font-size:12px;text-decoration:none}textarea{min-height:72px;resize:vertical}button,.import-button{font-weight:750;cursor:pointer}.import-button{position:relative;overflow:hidden}.import-button input{position:absolute;inset:0;opacity:0;cursor:pointer}.danger{color:#d23f49;background:#fff3f4;border-color:#ffd6da}.actions,.pagination{justify-content:flex-end;margin-top:12px}.toolbar{justify-content:flex-start;margin-bottom:12px;flex-wrap:wrap}.toolbar input{min-width:280px}.table-wrapper{overflow-x:auto}.row-actions{justify-content:flex-start;flex-wrap:wrap}.row-actions button{min-height:30px;padding:5px 8px}table{width:100%;border-collapse:collapse}th{padding:12px 10px;color:#69758a;background:#f8fafc;border-bottom:1px solid #e7ecf2;text-align:left;white-space:nowrap;font-size:10px;font-weight:800;text-transform:uppercase}td{padding:12px 10px;color:#27344c;border-bottom:1px solid #edf1f5;white-space:nowrap;font-size:12px}.badge{padding:5px 8px;border-radius:7px;background:#edf2ff;color:#2457d6;font-size:10px;font-weight:800;text-transform:capitalize}.badge.active{color:#168757;background:#eaf8f1}.badge.blocked{color:#b54708;background:#fff4e5}.badge.inactive,.badge.deleted{color:#69758a;background:#f0f2f5}.empty{padding:28px!important;color:#8490a2;text-align:center}.error-box{display:grid;gap:4px;margin-top:12px;padding:10px;color:#96333a;background:#fff3f4;border:1px solid #ffd4d8;border-radius:8px;font-size:11px}.summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin:14px 0}.summary-grid span{padding:10px;background:#f8fafc;border:1px solid #e7ecf2;border-radius:8px;color:#69758a;font-size:11px}.summary-grid b{display:block;color:#142139;font-size:13px}@media(max-width:1000px){.form-grid,.summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.wide{grid-column:span 2}}@media(max-width:700px){.page-heading,.toolbar,.page-toolbar,.toolbar-actions,.detail-head{align-items:stretch;flex-direction:column}.form-grid,.summary-grid{grid-template-columns:1fr}.wide{grid-column:span 1}.toolbar input{min-width:0;width:100%}}
</style>
