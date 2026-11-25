<script setup>
import { formatDate } from '../../Support/dateFormat.js';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import Layout from '../Layout.vue';
import SalesApi from './SalesApi';
import RowActionMenu from '../../Components/Common/RowActionMenu.vue';
import { gstTypes, gstTypeForGstin } from '../../Components/Form/gstTypes';
import SearchSelect from '../../Components/Common/SearchSelect.vue';

defineProps({ page: { type: String, default: 'customers' }, title: { type: String, default: 'Customers' } });

const customers = ref([]);
const references = ref({ customer_types: [], price_types: [], states: [], cities: [], branches: [] });
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
const openActionMenuId = ref(null);
const errors = ref({});
const detail = ref(null);
const showForm = ref(false);
const notice = ref({ text: '', type: 'ok' });
let noticeTimer = null;
const notify = (text, type = 'ok') => { notice.value = { text, type }; clearTimeout(noticeTimer); noticeTimer = setTimeout(() => { notice.value = { text: '', type: 'ok' }; }, 4500); };
const ledger = ref([]);
const outstanding = ref([]);
let timer = null;

const form = reactive({
    id: null, customer_code: '', customer_name: '', customer_type: 'retail', contact_person: '',
    mobile: '', whatsapp_number: '', whatsapp_same_as_mobile: true, phone: '', email: '', gstin: '', gst_type: 'unregistered', pan: '', billing_address: '', shipping_address: '',
    state_id: '', city: '', pincode: '', opening_balance: 0, opening_balance_type: 'debit',
    credit_limit: '', credit_days: '', price_type: 'retail', status: 'active', blocked_reason: '',
});

const sameAsBilling = ref(false);
const queryParams = computed(() => ({ page: pagination.value.current_page, search: search.value, status: status.value, type: type.value, state_id: stateId.value, price_type: priceType.value, crm_status: crmStatus.value }));
const filteredCities = computed(() => {
    if (!form.state_id) {
        return references.value.cities || [];
    }

    return (references.value.cities || []).filter((city) => Number(city.state_id) === Number(form.state_id));
});

const reset = () => {
    Object.assign(form, {
        id: null, customer_code: '', customer_name: '', customer_type: 'retail', contact_person: '',
        mobile: '', whatsapp_number: '', whatsapp_same_as_mobile: true, phone: '', email: '', gstin: '', gst_type: 'unregistered', pan: '', billing_address: '', shipping_address: '',
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

const uppercaseGstin = () => {
    form.gstin = (form.gstin || '').toUpperCase();
};

const newCustomer = () => { reset(); showForm.value = true; };
const cancelForm = () => { reset(); showForm.value = false; };
watch(() => form.gstin, (gstin) => { form.gst_type = gstTypeForGstin(form.gst_type, gstin); });
const editCustomer = (customer) => {
    Object.assign(form, customer);
    sameAsBilling.value = !!customer.billing_address && customer.billing_address === customer.shipping_address;
    errors.value = {};
    detail.value = null;
    showForm.value = true;
};

const viewCustomer = async (customer) => {
    const response = await SalesApi.getCustomer(customer.id);
    detail.value = response.customer;
    ledger.value = response.customer?.ledger_preview || [];
    outstanding.value = [];
    showForm.value = false;
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
        notify(response.message || 'Customer saved.');
        reset();
        showForm.value = false;
        await loadCustomers(pagination.value.current_page || 1);
    } catch (error) {
        if (error.response?.status === 422) {
            errors.value = error.response.data.errors || {};
            notify(Object.values(errors.value)?.[0]?.[0] || 'Please check the customer fields.', 'error');
            return;
        }
        notify(error.response?.data?.message || 'Could not save the customer. Please try again.', 'error');
    } finally {
        saving.value = false;
    }
};

const deleteCustomer = async (customer) => {
    if (!window.confirm(`Delete customer "${customer.customer_name}"? You can restore it later from the Deleted filter.`)) return;
    await SalesApi.deleteCustomer(customer.id);
    await loadCustomers(pagination.value.current_page || 1);
};

const restoreCustomer = async (customer) => {
    await SalesApi.restoreCustomer(customer.id);
    await loadCustomers(pagination.value.current_page || 1);
};

const toggleStatus = async (customer) => {
    const response = customer.status === 'active' ? await SalesApi.deactivateCustomer(customer.id) : await SalesApi.activateCustomer(customer.id);
    notify(response.message || 'Status updated.');
    await loadCustomers(pagination.value.current_page || 1);
};

const importCustomers = async (event) => {
    const file = event.target.files?.[0];
    if (!file) return;
    importing.value = true;
    try {
        const response = await SalesApi.importCustomers(file);
        notify(`${response.message} Created: ${response.created || 0}`);
        await loadCustomers(1);
    } catch (error) {
        notify(error.response?.data?.message || 'Customer import failed. Please check the file.', 'error');
    } finally {
        importing.value = false;
        event.target.value = '';
    }
};

const exportCustomers = () => { window.location.href = SalesApi.customerExportUrl(queryParams.value); };
const closeDetail = () => { detail.value = null; ledger.value = []; outstanding.value = []; };
const clearFilters = () => { search.value = ''; status.value = ''; type.value = ''; stateId.value = ''; priceType.value = ''; crmStatus.value = ''; loadCustomers(1); };
const actionMenuKey = (prefix, id) => `${prefix}-${id}`;
const toggleActionMenu = (target) => {
    const id = typeof target === 'object' ? target.id : target;
    openActionMenuId.value = openActionMenuId.value === id ? null : id;
};
const closeActionMenu = () => { openActionMenuId.value = null; };
const createInvoice = (customer) => { window.location.href = `/app/sales/invoices/create?customer_id=${customer.id}`; };
const createReturn = (customer) => { window.location.href = `/app/sales/returns/create?customer_id=${customer.id}`; };
const receivePayment = (customer) => { window.location.href = `/app/accounting/vouchers?type=receipt&customer_id=${customer.id}`; };
const openInvoicePrint = (id) => { window.open(`/app/sales/invoices/${id}/print`, '_blank', 'noopener'); };
const initials = (name) => String(name || '?').replace(/\(.*?\)/g, '').trim().split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase();
const formatMoney = (value) => `Rs. ${Number(value || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

watch(sameAsBilling, (checked) => { if (checked) form.shipping_address = form.billing_address; });
watch(() => form.billing_address, (value) => { if (sameAsBilling.value) form.shipping_address = value; });
watch(() => form.whatsapp_same_as_mobile, (checked) => { if (checked) form.whatsapp_number = form.mobile; });
watch(() => form.mobile, (value) => { if (form.whatsapp_same_as_mobile) form.whatsapp_number = value; });
watch(() => form.state_id, () => {
    if (form.city && !filteredCities.value.some((city) => city.name === form.city)) {
        form.city = '';
    }
});
watch([search, status, type, stateId, priceType, crmStatus], () => {
    clearTimeout(timer);
    timer = setTimeout(() => loadCustomers(1), 300);
});

const onEscape = (event) => {
    if (event.key !== 'Escape') return;
    if (showForm.value) cancelForm();
    else if (detail.value) closeDetail();
};

onMounted(async () => { window.addEventListener('keydown', onEscape); await loadReferences(); await loadCustomers(); if (new URLSearchParams(window.location.search).get('action') === 'create') newCustomer(); });
onBeforeUnmount(() => window.removeEventListener('keydown', onEscape));
</script>

<template>
    <Layout :page="page" :title="title">
        <template #topbar-title>
            <div class="bill-page-title"><span>SALES MANAGEMENT</span><h1>Customer Master</h1><p>Maintain customer GST, credit and ledger-ready billing details.</p></div>
        </template>
        <div class="sales-page">
            <div v-if="notice.text" class="toast" :class="notice.type" role="status">{{ notice.text }}<button type="button" aria-label="Dismiss" @click="notice.text = ''">x</button></div>

            <div class="page-toolbar">
                <button type="button" class="primary" @click="newCustomer">+ New Customer</button>
                <div class="toolbar-actions">
                    <span class="count-chip">{{ pagination.total || 0 }} customers</span>
                    <a :href="SalesApi.customerImportTemplateUrl()">Import Template</a>
                    <label class="import-button">{{ importing ? 'Importing...' : 'Import' }}<input type="file" accept=".csv,text/csv" :disabled="importing" @change="importCustomers" /></label>
                    <button type="button" @click="exportCustomers">Export</button>
                </div>
            </div>

            <div v-if="showForm" class="drawer-backdrop form-backdrop"></div>
            <aside v-if="showForm" id="customer-form" class="detail-drawer form-drawer" role="dialog" aria-label="Customer form">
                <div class="form-head"><h2>{{ form.id ? 'Edit customer' : 'New customer' }}</h2><button type="button" @click="cancelForm">Close</button></div>
                <div class="form-body">
                <h3 class="group-title">Basic details</h3>
                <div class="form-grid">
                    <label class="span2"><span>Customer Name <span class="required-mark">*</span></span><input v-model="form.customer_name" placeholder="Name as it should appear on invoices" /></label>
                    <label><span>Mobile</span><input v-model="form.mobile" inputmode="numeric" placeholder="10-digit mobile" /></label>
                    <label><span>WhatsApp</span><input v-model="form.whatsapp_number" inputmode="numeric" :disabled="form.whatsapp_same_as_mobile" placeholder="Same as mobile" /></label>
                    <label class="check span2"><input v-model="form.whatsapp_same_as_mobile" type="checkbox" /> WhatsApp number is the same as mobile</label>
                    <label><span>Email</span><input v-model="form.email" type="email" placeholder="name@example.com" /></label>
                    <label><span>Contact Person</span><input v-model="form.contact_person" placeholder="Contact person" /></label>
                    <SearchSelect v-model="form.customer_type" label="Customer Type" :options="references.customer_types" option-value-key="value" option-label-key="label" select-placeholder="Select Type" required />
                    <SearchSelect v-model="form.status" label="Status" :options="[{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }, { value: 'blocked', label: 'Blocked' }]" option-value-key="value" option-label-key="label" select-placeholder="Select Status" required />
                    <label v-if="form.status === 'blocked'" class="span4">Block Reason<textarea v-model="form.blocked_reason" placeholder="Reason for blocking this customer"></textarea></label>
                </div>
                <h3 class="group-title">Tax and address</h3>
                <div class="form-grid">
                    <label><span>GST Type</span><select v-model="form.gst_type"><option v-for="type in gstTypes" :key="type.value" :value="type.value">{{ type.label }}</option></select></label>
                    <label><span>GSTIN</span><input v-model="form.gstin" maxlength="15" placeholder="15-character GSTIN (if registered)" @input="uppercaseGstin" /></label>
                    <label><span>PAN</span><input v-model="form.pan" maxlength="10" placeholder="PAN" /></label>
                    <SearchSelect v-model="form.state_id" label="State" :options="references.states" option-value-key="id" option-label-key="name" select-placeholder="Select State" />
                    <SearchSelect v-model="form.city" label="City" :options="filteredCities" option-value-key="name" option-label-key="name" select-placeholder="Search City" allow-custom />
                    <label class="span2">Billing Address<textarea v-model="form.billing_address" placeholder="Billing address"></textarea></label>
                    <label class="span2">Shipping Address<textarea v-model="form.shipping_address" placeholder="Shipping address" :disabled="sameAsBilling"></textarea><span class="check inline"><input v-model="sameAsBilling" type="checkbox" /> Same as billing address</span></label>
                    <label><span>Pincode</span><input v-model="form.pincode" maxlength="6" inputmode="numeric" placeholder="Pincode" /></label>
                </div>
                <h3 class="group-title">Credit and pricing</h3>
                <div class="form-grid">
                    <label><span>Opening Balance</span><input v-model="form.opening_balance" type="number" min="0" step="0.01" /></label>
                    <label><span>Balance Type</span><select v-model="form.opening_balance_type"><option value="debit">Debit (customer owes us)</option><option value="credit">Credit (we owe customer)</option></select></label>
                    <label><span>Credit Limit</span><input v-model="form.credit_limit" type="number" min="0" step="0.01" placeholder="No limit" /></label>
                    <label><span>Credit Days</span><input v-model="form.credit_days" type="number" min="0" placeholder="e.g. 30" /></label>
                    <label><span>Price List</span><select v-model="form.price_type"><option v-for="item in references.price_types" :key="item.value" :value="item.value">{{ item.label }}</option></select></label>
                    <label><span>Customer Code</span><input v-model="form.customer_code" placeholder="Auto if blank" /></label>
                </div>
                <div v-if="Object.keys(errors).length" class="error-box"><span v-for="(messages, field) in errors" :key="field">{{ messages[0] }}</span></div>
                </div>
                <div class="actions drawer-footer"><button type="button" @click="cancelForm">Cancel</button><button type="button" class="primary" :disabled="saving" @click="saveCustomer">{{ saving ? 'Saving...' : (form.id ? 'Update Customer' : 'Save Customer') }}</button></div>
            </aside>

            <section class="panel">
                <div class="toolbar">
                    <input v-model="search" class="search-box" placeholder="Search name, mobile, code, email or GSTIN" />
                    <select v-model="status"><option value="">All Status</option><option value="active">Active</option><option value="inactive">Inactive</option><option value="blocked">Blocked</option><option value="deleted">Deleted</option></select>
                    <select v-model="crmStatus"><option value="">All CRM Status</option><option value="new">New</option><option value="repeat">Repeat</option><option value="regular">Regular</option><option value="inactive">Inactive</option></select>
                    <select v-model="type"><option value="">All Types</option><option v-for="item in references.customer_types" :key="item.value" :value="item.value">{{ item.label }}</option></select>
                    <select v-model="stateId"><option value="">All States</option><option v-for="item in references.states" :key="item.id" :value="item.id">{{ item.name }}</option></select>
                    <button v-if="search || status || crmStatus || type || stateId || priceType" type="button" @click="clearFilters">Clear Filters</button>
                </div>
                <div class="table-wrapper" :class="{ 'is-refreshing': loading }">
                    <table>
                        <thead><tr><th>Customer</th><th>GSTIN</th><th>CRM</th><th class="num">Orders</th><th class="num">Outstanding</th><th>Last Purchase</th><th>Status</th><th class="sticky-col">Actions</th></tr></thead>
                        <tbody>
                            <tr v-for="customer in customers" :key="customer.id">
                                <td class="name-cell"><button type="button" class="name-link" @click="viewCustomer(customer)"><span class="avatar">{{ initials(customer.customer_name) }}</span><span class="name-text"><b>{{ customer.customer_name }}</b><small>{{ customer.customer_code }}<template v-if="customer.mobile || customer.phone"> &middot; {{ customer.mobile || customer.phone }}</template><template v-else> &middot; no mobile</template></small></span></button></td>
                                <td>{{ customer.gstin || '-' }}</td>
                                <td><span class="badge crm" :class="customer.crm_summary?.customer_status">{{ customer.crm_summary?.customer_status_label || '-' }}</span></td>
                                <td class="num">{{ customer.crm_summary?.total_orders || 0 }}</td>
                                <td class="num" :class="{ due: Number(customer.current_outstanding) > 0, adv: Number(customer.current_outstanding) < 0 }">{{ formatMoney(customer.current_outstanding) }}</td>
                                <td>{{ formatDate(customer.crm_summary?.last_purchase_date) }}</td>
                                <td><span class="badge" :class="customer.deleted_at ? 'deleted' : customer.status">{{ customer.deleted_at ? 'deleted' : customer.status }}</span></td>
                                <td class="sticky-col">
                                    <div class="row-actions">
                                        <RowActionMenu
                                            v-if="!customer.deleted_at"
                                            :open="openActionMenuId === customer.id"
                                            view-title="View customer details"
                                            @view="viewCustomer(customer); closeActionMenu()"
                                            @toggle="toggleActionMenu(customer)"
                                            @close="closeActionMenu"
                                        >
                                            <button type="button" @click="editCustomer(customer); closeActionMenu()">Edit</button>
                                            <button type="button" @click="viewLedger(customer); closeActionMenu()">Ledger</button>
                                            <button type="button" @click="viewOutstanding(customer); closeActionMenu()">Outstanding</button>
                                            <button type="button" @click="receivePayment(customer); closeActionMenu()">Receive</button>
                                            <button type="button" @click="createInvoice(customer); closeActionMenu()">Invoice</button>
                                            <button type="button" @click="createReturn(customer); closeActionMenu()">Return</button>
                                            <button type="button" @click="toggleStatus(customer); closeActionMenu()">{{ customer.status === 'active' ? 'Deactivate' : 'Activate' }}</button>
                                            <button v-if="customer.customer_type !== 'walk_in'" type="button" class="danger" @click="deleteCustomer(customer); closeActionMenu()">Delete</button>
                                        </RowActionMenu>
                                        <button v-else type="button" @click="restoreCustomer(customer)">Restore</button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="loading && !customers.length"><td colspan="8" class="empty">Loading customers...</td></tr>
                            <tr v-else-if="!customers.length"><td colspan="8" class="empty">No customers match these filters. <button type="button" class="linklike" @click="clearFilters">Clear filters</button> or <button type="button" class="linklike" @click="newCustomer">add a new customer</button>.</td></tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="pagination.total > 0 && pagination.last_page > 1" class="pagination"><button :disabled="pagination.current_page <= 1 || loading" @click="loadCustomers(pagination.current_page - 1)">Previous</button><span>{{ loading ? 'Loading…' : `${pagination.from || 0}-${pagination.to || 0} of ${pagination.total || 0}` }}</span><button :disabled="pagination.current_page >= pagination.last_page || loading" @click="loadCustomers(pagination.current_page + 1)">Next</button></div>
            </section>

            <div v-if="detail" class="drawer-backdrop" @click="closeDetail"></div>
            <aside v-if="detail" class="detail-drawer" role="dialog" aria-label="Customer detail">
                <div class="detail-head"><div><span>CUSTOMER DETAIL</span><h2>{{ detail.customer_name }}</h2><p>{{ detail.customer_code }} | {{ detail.mobile || 'no mobile' }} | {{ detail.gstin || 'no GSTIN' }}</p></div><button type="button" @click="closeDetail">Close</button></div>
                <div class="drawer-actions"><button type="button" @click="createInvoice(detail)">New Invoice</button><button type="button" @click="receivePayment(detail)">Receive Payment</button><button type="button" @click="viewLedger(detail)">Ledger</button><button type="button" @click="viewOutstanding(detail)">Outstanding</button><button type="button" @click="editCustomer(detail)">Edit</button></div>
                <div class="summary-grid"><span>CRM Status <b>{{ detail.crm_summary?.customer_status_label || '-' }}</b></span><span>Total Orders <b>{{ detail.crm_summary?.total_orders || 0 }}</b></span><span>Lifetime Sales <b>{{ formatMoney(detail.crm_summary?.lifetime_sales) }}</b></span><span>Average Order <b>{{ formatMoney(detail.crm_summary?.average_order_value) }}</b></span><span>Outstanding <b>{{ formatMoney(detail.current_outstanding) }}</b></span><span>Total Paid <b>{{ formatMoney(detail.crm_summary?.total_paid) }}</b></span><span>First Purchase <b>{{ formatDate(detail.crm_summary?.first_purchase_date) }}</b></span><span>Last Purchase <b>{{ formatDate(detail.crm_summary?.last_purchase_date) }}</b></span></div>
                <h3 v-if="detail.recent_sales?.length" class="group-title">Recent invoices</h3>
                <div v-if="detail.recent_sales?.length" class="table-wrapper"><table><thead><tr><th>Invoice</th><th>Date</th><th class="num">Total</th><th>Payment</th><th>Status</th><th>Actions</th></tr></thead><tbody><tr v-for="row in detail.recent_sales" :key="row.id"><td>{{ row.invoice_number }}</td><td>{{ formatDate(row.invoice_date) }}</td><td class="num">{{ formatMoney(row.grand_total) }}</td><td>{{ row.payment_status }}</td><td>{{ row.status }}</td><td><div class="row-actions"><RowActionMenu :open="openActionMenuId === actionMenuKey('invoice', row.id)" :show-view="false" more-label="Actions" more-title="Invoice actions" @toggle="toggleActionMenu(actionMenuKey('invoice', row.id))" @close="closeActionMenu"><button type="button" @click="openInvoicePrint(row.id); closeActionMenu()">View</button><a :href="`/app/sales/invoices/${row.id}/print`" target="_blank" @click="closeActionMenu">Print</a></RowActionMenu></div></td></tr></tbody></table></div>
                <h3 v-if="detail.product_history?.length" class="group-title">Products bought</h3>
                <div v-if="detail.product_history?.length" class="table-wrapper"><table><thead><tr><th>Product</th><th class="num">Qty</th><th class="num">Times</th><th>Last Purchase</th><th class="num">Last Price</th><th class="num">Avg Price</th></tr></thead><tbody><tr v-for="row in detail.product_history" :key="row.product_id"><td>{{ row.product }}</td><td class="num">{{ row.total_quantity }}</td><td class="num">{{ row.purchase_count }}</td><td>{{ formatDate(row.last_purchase_date) }}</td><td class="num">{{ formatMoney(row.last_selling_price) }}</td><td class="num">{{ formatMoney(row.average_selling_price) }}</td></tr></tbody></table></div>
                <h3 v-if="ledger.length" class="group-title">Ledger</h3>
                <div v-if="ledger.length" class="table-wrapper"><table><thead><tr><th>Date</th><th>Voucher</th><th>Reference</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Running</th><th>Narration</th></tr></thead><tbody><tr v-for="(row,index) in ledger" :key="index"><td>{{ formatDate(row.date) }}</td><td>{{ row.voucher_type }}</td><td>{{ row.reference }}</td><td class="num">{{ formatMoney(row.debit) }}</td><td class="num">{{ formatMoney(row.credit) }}</td><td class="num">{{ formatMoney(row.running_balance) }}</td><td>{{ row.narration || '-' }}</td></tr></tbody></table></div>
                <h3 v-if="outstanding.length" class="group-title">Outstanding invoices</h3>
                <div v-if="outstanding.length" class="table-wrapper"><table><thead><tr><th>Invoice</th><th>Date</th><th>Due Date</th><th class="num">Total</th><th class="num">Paid</th><th class="num">Balance</th><th>Overdue</th><th>Ageing</th></tr></thead><tbody><tr v-for="row in outstanding" :key="row.invoice_number"><td>{{ row.invoice_number }}</td><td>{{ formatDate(row.invoice_date) }}</td><td>{{ formatDate(row.due_date) }}</td><td class="num">{{ formatMoney(row.invoice_total) }}</td><td class="num">{{ formatMoney(row.paid) }}</td><td class="num">{{ formatMoney(row.balance) }}</td><td>{{ row.days_overdue }}</td><td>{{ row.ageing_bucket }}</td></tr></tbody></table></div>
                <p v-if="!ledger.length && !outstanding.length" class="empty">Use the buttons above to open this customer's Ledger or Outstanding invoices.</p>
            </aside>
        </div>
    </Layout>
</template>

<style scoped>
.sales-page{padding:4px 0 28px}.page-toolbar,.toolbar,.actions,.pagination,.toolbar-actions,.row-actions,.detail-head,.form-head{display:flex;align-items:center;justify-content:space-between;gap:12px}.page-toolbar{margin-bottom:14px;flex-wrap:wrap}.toolbar-actions{flex-wrap:wrap;justify-content:flex-end}
.panel{margin-bottom:18px;padding:18px;background:#fff;border:1px solid #dfe6ef;border-radius:10px}
.form-head h2,.detail-head h2{margin:0;color:#142139;font-weight:800;font-size:18px}.detail-head span{color:#2457d6;font-size:10px;font-weight:800;letter-spacing:1.2px}.detail-head p{margin:6px 0 0;color:#758197;font-size:13px}
.group-title{margin:18px 0 8px;padding-bottom:6px;color:#344159;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;border-bottom:1px solid #edf1f5}
.form-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;align-items:start}.form-grid>label{display:grid;gap:5px;color:#667085;font-size:12px;font-weight:700;align-content:start}.form-grid :deep(.search-select){display:block}
.span2{grid-column:span 2}.span4{grid-column:span 4}.required-mark{color:#dc2626;font-weight:900;margin-left:3px}.check{display:flex!important;align-items:center;gap:8px;grid-template-columns:none}.check input{min-height:auto;width:auto}.check.inline{margin-top:6px;font-weight:600}
input,select,textarea,button,.toolbar-actions a,.import-button{min-height:38px;padding:8px 10px;color:#344159;background:#fff;border:1px solid #d8e0eb;border-radius:8px;font-size:13px;text-decoration:none;font-family:inherit}input:focus,select:focus,textarea:focus{outline:2px solid #bfd3ff;border-color:#7ea3f5}input:disabled,textarea:disabled{background:#f4f6f9;color:#98a2b3}
textarea{min-height:72px;resize:vertical}button,.import-button{font-weight:750;cursor:pointer}button.primary{color:#fff;background:#2457d6;border-color:#2457d6}button.primary:disabled{opacity:.6;cursor:wait}.import-button{position:relative;overflow:hidden}.import-button input{position:absolute;inset:0;opacity:0;cursor:pointer}
.count-chip{padding:7px 12px;color:#344159;background:#eef2f9;border-radius:999px;font-size:12px;font-weight:800}.danger{color:#d23f49;background:#fff3f4;border-color:#ffd6da}.actions,.pagination{justify-content:flex-end;margin-top:14px}
.toolbar{justify-content:flex-start;margin-bottom:12px;flex-wrap:wrap}.search-box{flex:1 1 280px;min-width:240px}
.table-wrapper{overflow-x:auto}table{width:100%;border-collapse:collapse}th{padding:11px 10px;color:#69758a;background:#f8fafc;border-bottom:1px solid #e7ecf2;text-align:left;white-space:nowrap;font-size:11px;font-weight:800;text-transform:uppercase;position:sticky;top:0}td{padding:10px;color:#27344c;border-bottom:1px solid #edf1f5;white-space:nowrap;font-size:13px;vertical-align:middle}tbody tr:hover td{background:#f9fbff}
.num{text-align:right;font-variant-numeric:tabular-nums}.due{color:#b42318;font-weight:800}.adv{color:#067647;font-weight:800}
.sticky-col{position:sticky;right:0;background:#fff;box-shadow:-6px 0 8px -8px rgba(15,23,42,.25)}th.sticky-col{background:#f8fafc}tbody tr:hover td.sticky-col{background:#f9fbff}.row-actions{justify-content:flex-start;flex-wrap:nowrap;gap:6px}.row-actions button{min-height:30px;padding:5px 9px;white-space:nowrap}
.name-cell{max-width:360px;white-space:normal}.name-link{display:flex;align-items:center;gap:10px;min-height:0;padding:0;border:0;background:transparent;text-align:left;font-weight:400;cursor:pointer}.avatar{display:grid;place-items:center;flex:0 0 34px;width:34px;height:34px;border-radius:50%;background:#e8eefc;color:#2457d6;font-size:12px;font-weight:800}.name-text{display:grid;min-width:0}.name-text b{color:#142139;font-size:13px;white-space:normal;line-height:1.25}.name-text small{color:#7b8799;font-size:11px}
.badge{padding:5px 8px;border-radius:7px;background:#edf2ff;color:#2457d6;font-size:10px;font-weight:800;text-transform:capitalize}.badge.active{color:#168757;background:#eaf8f1}.badge.blocked{color:#b54708;background:#fff4e5}.badge.inactive,.badge.deleted{color:#69758a;background:#f0f2f5}
.empty{padding:28px!important;color:#8490a2;text-align:center;white-space:normal}.linklike{min-height:0;padding:0;border:0;background:none;color:#2457d6;text-decoration:underline;font-size:inherit}
.error-box{display:grid;gap:4px;margin-top:12px;padding:10px;color:#96333a;background:#fff3f4;border:1px solid #ffd4d8;border-radius:8px;font-size:12px}
.toast{position:fixed;top:16px;right:16px;z-index:60;display:flex;align-items:center;gap:12px;max-width:420px;padding:12px 14px;color:#065f46;background:#ecfdf3;border:1px solid #a6f4c5;border-radius:10px;box-shadow:0 10px 30px rgba(15,23,42,.18);font-size:13px;font-weight:700}.toast.error{color:#912018;background:#fef3f2;border-color:#fecdca}.toast button{min-height:24px;padding:0 8px;border:0;background:transparent;color:inherit}
.drawer-backdrop{position:fixed;inset:0;z-index:40;background:rgba(15,23,42,.35)}.detail-drawer{position:fixed;top:0;right:0;bottom:0;z-index:50;width:min(820px,100vw);padding:20px;overflow-y:auto;background:#fff;box-shadow:-12px 0 40px rgba(15,23,42,.25)}
.form-drawer{display:flex;flex-direction:column;width:min(720px,100vw);padding:0;overflow:hidden}.form-drawer .form-head{flex:0 0 auto;padding:16px 20px;border-bottom:1px solid #e7ecf2}.form-drawer .form-body{flex:1 1 auto;overflow-y:auto;padding:4px 20px 20px}.form-drawer .form-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.form-drawer .span4{grid-column:span 2}.form-drawer .group-title:first-child{margin-top:12px}.drawer-footer{flex:0 0 auto;margin:0!important;padding:12px 20px;background:#fff;border-top:1px solid #e7ecf2;box-shadow:0 -6px 12px -10px rgba(15,23,42,.25)}.form-backdrop{z-index:44}.form-drawer{z-index:50}
.drawer-actions{display:flex;flex-wrap:wrap;gap:8px;margin:14px 0}.summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin:14px 0}.summary-grid span{padding:10px;background:#f8fafc;border:1px solid #e7ecf2;border-radius:8px;color:#69758a;font-size:11px}.summary-grid b{display:block;color:#142139;font-size:13px}
@media(max-width:1000px){.form-grid,.summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.span4{grid-column:span 2}}
@media(max-width:700px){.form-drawer .form-grid{grid-template-columns:1fr}.form-drawer .span2,.form-drawer .span4{grid-column:span 1}.form-drawer .form-body{padding:4px 14px 16px}.page-toolbar,.toolbar,.toolbar-actions,.detail-head{align-items:stretch;flex-direction:column}.form-grid,.summary-grid{grid-template-columns:1fr}.span2,.span4{grid-column:span 1}.search-box{min-width:0;width:100%}.detail-drawer{padding:14px}.actions{flex-direction:column-reverse}.actions button{width:100%}}
</style>
