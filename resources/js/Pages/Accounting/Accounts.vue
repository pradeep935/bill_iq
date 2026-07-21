<script setup>
import { onMounted, reactive, ref } from 'vue';
import Layout from '../Layout.vue';
import AccountingApi from './AccountingApi';

defineProps({ page: { type: String, default: 'accounts' }, title: { type: String, default: 'Chart of Accounts' } });

const refs = ref({ accounts: [], groups: [], settings: {} });
const accounts = ref([]);
const pagination = ref({});
const saving = ref(false);
const filters = reactive({ search: '', type: '' });
const form = reactive({ id: null, account_group_id: '', parent_account_id: '', account_code: '', account_name: '', account_type: 'ledger', opening_balance: 0, opening_balance_type: 'debit', is_reconciliation_enabled: false, status: 'active', branch_id: '', bank_name: '', account_holder_name: '', account_number: '', bank_account_type: '', ifsc_code: '', bank_branch_name: '', upi_id: '', swift_code: '' });
const settings = reactive({});

const loadRefs = async () => { refs.value = await AccountingApi.references(); Object.assign(settings, refs.value.settings || {}); };
const loadAccounts = async (page = 1) => { const r = await AccountingApi.accounts({ ...filters, page }); accounts.value = r.accounts || []; pagination.value = r.pagination || {}; };
const reset = () => Object.assign(form, { id: null, account_group_id: '', parent_account_id: '', account_code: '', account_name: '', account_type: 'ledger', opening_balance: 0, opening_balance_type: 'debit', is_reconciliation_enabled: false, status: 'active', branch_id: '', bank_name: '', account_holder_name: '', account_number: '', bank_account_type: '', ifsc_code: '', bank_branch_name: '', upi_id: '', swift_code: '' });
const edit = (row) => Object.assign(form, { ...row, account_group_id: row.account_group_id || '', parent_account_id: row.parent_account_id || '' });
const save = async () => { saving.value = true; try { await AccountingApi.saveAccount({ ...form }, form.id); reset(); await loadRefs(); await loadAccounts(); } finally { saving.value = false; } };
const saveSettings = async () => { saving.value = true; try { const r = await AccountingApi.saveSettings({ ...settings }); alert(r.message); } finally { saving.value = false; } };
const money = (v) => Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
onMounted(async () => { await loadRefs(); await loadAccounts(); });
</script>

<template>
    <Layout :page="page" :title="title">
        <div class="acct-page">
            <div class="page-heading"><div><span>ACCOUNTING</span><h1>Chart of Accounts</h1><p>System accounts, cash/bank ledgers and default posting mappings.</p></div><button @click="reset">New Account</button></div>
            <section class="panel"><div class="form-grid"><select v-model="form.account_group_id"><option value="">Group</option><option v-for="g in refs.groups" :key="g.id" :value="g.id">{{ g.group_name }}</option></select><input v-model="form.account_code" placeholder="Code" /><input v-model="form.account_name" placeholder="Account Name" /><select v-model="form.account_type"><option value="ledger">Ledger</option><option value="cash">Cash</option><option value="bank">Bank</option><option value="customer">Customer</option><option value="supplier">Supplier</option><option value="tax">Tax</option><option value="income">Income</option><option value="expense">Expense</option><option value="inventory">Inventory</option><option value="adjustment">Adjustment</option></select><input v-model="form.opening_balance" type="number" /><select v-model="form.opening_balance_type"><option value="debit">Debit</option><option value="credit">Credit</option></select><select v-model="form.status"><option value="active">Active</option><option value="inactive">Inactive</option></select><button :disabled="saving" @click="save">Save Account</button></div><div v-if="['cash','bank'].includes(form.account_type)" class="form-grid"><select v-model="form.branch_id"><option value="">Branch</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select><input v-if="form.account_type === 'bank'" v-model="form.bank_name" placeholder="Bank Name" /><input v-if="form.account_type === 'bank'" v-model="form.account_holder_name" placeholder="Account Holder" /><input v-if="form.account_type === 'bank'" v-model="form.account_number" placeholder="Account Number" /><input v-if="form.account_type === 'bank'" v-model="form.bank_account_type" placeholder="Account Type" /><input v-if="form.account_type === 'bank'" v-model="form.ifsc_code" placeholder="IFSC" /><input v-if="form.account_type === 'bank'" v-model="form.bank_branch_name" placeholder="Bank Branch" /><input v-if="form.account_type === 'bank'" v-model="form.upi_id" placeholder="UPI ID" /></div></section>
            <section class="panel"><h3>Default Accounts</h3><div class="form-grid"><select v-for="(_, key) in settings" :key="key" v-model="settings[key]"><option value="">{{ key }}</option><option v-for="a in refs.accounts" :key="a.id" :value="a.id">{{ a.account_name }}</option></select></div><div class="actions"><button :disabled="saving" @click="saveSettings">Save Default Accounts</button></div></section>
            <section class="panel"><div class="toolbar"><input v-model="filters.search" placeholder="Search account" @input="loadAccounts(1)" /><select v-model="filters.type" @change="loadAccounts(1)"><option value="">All Types</option><option value="cash">Cash</option><option value="bank">Bank</option><option value="customer">Customer</option><option value="supplier">Supplier</option><option value="tax">Tax</option></select></div><div class="table-wrapper"><table><thead><tr><th>Code</th><th>Name</th><th>Group</th><th>Type</th><th>Balance</th><th>Status</th><th></th></tr></thead><tbody><tr v-for="a in accounts" :key="a.id"><td>{{ a.account_code }}</td><td>{{ a.account_name }}</td><td>{{ a.group?.group_name || '-' }}</td><td>{{ a.account_type }}</td><td>Rs. {{ money(a.current_balance) }}</td><td>{{ a.status }}</td><td><button @click="edit(a)">Edit</button></td></tr></tbody></table></div></section>
        </div>
    </Layout>
</template>

<style scoped>
.acct-page{padding:4px 0 28px}.page-heading,.toolbar,.actions{display:flex;align-items:center;justify-content:space-between;gap:12px}.page-heading{margin-bottom:18px}.page-heading span{color:#2457d6;font-size:10px;font-weight:800;letter-spacing:1.2px}.page-heading h1{margin:0;color:#142139}.page-heading p{margin:6px 0 0;color:#758197;font-size:13px}.panel{margin-bottom:18px;padding:18px;background:#fff;border:1px solid #dfe6ef;border-radius:8px}.form-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}input,select,button{min-height:38px;padding:8px 10px;color:#344159;background:#fff;border:1px solid #d8e0eb;border-radius:8px;font-size:12px}button{font-weight:750;cursor:pointer}.toolbar{justify-content:flex-start;margin-bottom:12px}.table-wrapper{overflow-x:auto}table{width:100%;border-collapse:collapse}th,td{padding:11px 10px;border-bottom:1px solid #edf1f5;text-align:left;white-space:nowrap;font-size:12px}th{color:#69758a;background:#f8fafc;font-size:10px;text-transform:uppercase}@media(max-width:900px){.form-grid{grid-template-columns:1fr}.page-heading,.toolbar{align-items:stretch;flex-direction:column}}
</style>
