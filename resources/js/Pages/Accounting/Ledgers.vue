<script setup>
import { formatDate, formatDateField } from '../../Support/dateFormat.js';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import Layout from '../Layout.vue';
import AccountingApi from './AccountingApi';

defineProps({ page: { type: String, default: 'ledgers' }, title: { type: String, default: 'Ledgers and Books' } });
const refs = ref({ accounts: [], cash_bank_accounts: [], customers: [], suppliers: [], branches: [] });
const tab = ref('account');
const rows = ref([]);
const ledgerTable = ref(null);
const showLatestEntries = () => {
    if (ledgerTable.value) ledgerTable.value.scrollTop = ledgerTable.value.scrollHeight;
};
const filters = reactive({ account_id: '', customer_id: '', supplier_id: '', branch_id: '', date_from: '', date_to: '' });
const loadRefs = async () => { refs.value = await AccountingApi.references(); };
const loading = ref(false);
const loadError = ref('');
let requestVersion = 0;
const outstanding = computed(() => tab.value.endsWith('_outstanding'));
const emptyPrompt = computed(() => {
    if (tab.value === 'supplier' && !filters.supplier_id) return 'Select a supplier to view ledger';
    if (tab.value === 'customer' && !filters.customer_id) return 'Select a customer to view ledger';
    if (tab.value === 'cashbank' && !filters.account_id) return 'Select a cash/bank account to view book';
    return '';
});
const load = async () => {
    const version = ++requestVersion;
    rows.value = [];
    loadError.value = '';
    loading.value = false;
    if (emptyPrompt.value) return;
    const params = { branch_id: filters.branch_id };
    if (!outstanding.value) Object.assign(params, { date_from: filters.date_from, date_to: filters.date_to });
    if (['account', 'cashbank'].includes(tab.value)) params.account_id = filters.account_id;
    if (['customer', 'customer_outstanding'].includes(tab.value)) params.customer_id = filters.customer_id;
    if (['supplier', 'supplier_outstanding'].includes(tab.value)) params.supplier_id = filters.supplier_id;
    loading.value = true;
    try {
        let result;
        if (tab.value === 'cashbank') result = await AccountingApi.cashBankBook(params);
        else if (tab.value === 'customer_outstanding') result = await AccountingApi.customerOutstanding(params);
        else if (tab.value === 'supplier_outstanding') result = await AccountingApi.supplierOutstanding(params);
        else result = await AccountingApi.ledger(params);
        if (version === requestVersion) rows.value = outstanding.value
            ? result.filter(row => Number(row.outstanding_amount) > 0)
            : result;
    } catch (error) {
        if (version === requestVersion) loadError.value = 'Unable to load ledger. Please try again.';
    } finally {
        if (version === requestVersion) loading.value = false;
    }
};
const changeTab = (next) => {
    if (tab.value === next) return;
    tab.value = next;
    Object.assign(filters, { account_id: '', customer_id: '', supplier_id: '' });
    load();
};
onBeforeUnmount(() => { requestVersion++; });
const ageing = (value) => {
    const days = Math.max(0, Math.floor(Number(value) || 0));
    return `${days} ${days === 1 ? 'Day' : 'Days'}`;
};
const balance = (value) => {
    const amount = Number(value || 0);
    return `${money(Math.abs(amount))}${amount < 0 ? ' Cr' : amount > 0 ? ' Dr' : ''}`;
};
const exportRows = () => {
    const csv = rows.value.map((row) => Object.entries(row).map(([key, value]) => `"${String(formatDateField(key, value) ?? '').replace(/"/g, '""')}"`).join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv' }); const url = URL.createObjectURL(blob); const a = document.createElement('a'); a.href = url; a.download = `${tab.value}.csv`; a.click(); URL.revokeObjectURL(url);
};
const money = (v) => Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
onMounted(async () => { await loadRefs(); await load(); });
</script>

<template>
    <Layout :page="page" :title="title">
        <template #topbar-title>
            <div class="bill-page-title"><span>ACCOUNTING</span><h1>Ledgers and Outstanding</h1><p>Customer, supplier, account, cash and bank running balances.</p></div>
        </template>
        <div class="acct-page">
            <div class="page-toolbar"><button @click="exportRows">Export</button></div>
            <section class="tabs"><button :class="{active:tab==='account'}" @click="changeTab('account')">Account Ledger</button><button :class="{active:tab==='customer'}" @click="changeTab('customer')">Customer Ledger</button><button :class="{active:tab==='supplier'}" @click="changeTab('supplier')">Supplier Ledger</button><button :class="{active:tab==='cashbank'}" @click="changeTab('cashbank')">Cash / Bank Book</button><button :class="{active:tab==='customer_outstanding'}" @click="changeTab('customer_outstanding')">Customer Outstanding</button><button :class="{active:tab==='supplier_outstanding'}" @click="changeTab('supplier_outstanding')">Supplier Outstanding</button></section>
            <section class="panel"><div class="form-grid">
                <label v-if="['account','cashbank'].includes(tab)">{{ tab === 'cashbank' ? 'Cash/Bank Account' : 'Account' }}<select v-model="filters.account_id"><option value="">{{ tab === 'cashbank' ? 'Select cash/bank account' : 'All accounts' }}</option><option v-for="a in (tab === 'cashbank' ? refs.cash_bank_accounts : refs.accounts)" :key="a.id" :value="a.id">{{ a.account_name }}</option></select></label>
                <label v-if="['customer','customer_outstanding'].includes(tab)">Customer<select v-model="filters.customer_id"><option value="">{{ outstanding ? 'All customers' : 'Select customer' }}</option><option v-for="c in refs.customers" :key="c.id" :value="c.id">{{ c.customer_name }}</option></select></label>
                <label v-if="['supplier','supplier_outstanding'].includes(tab)">Supplier<select v-model="filters.supplier_id"><option value="">{{ outstanding ? 'All suppliers' : 'Select supplier' }}</option><option v-for="s in refs.suppliers" :key="s.id" :value="s.id">{{ s.supplier_name || s.name }}</option></select></label>
                <label>Branch<select v-model="filters.branch_id"><option value="">All branches</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select></label>
                <label v-if="!outstanding">Date From<input v-model="filters.date_from" type="date" /></label>
                <label v-if="!outstanding">Date To<input v-model="filters.date_to" type="date" /></label>
                <button @click="load">Apply</button>
            </div></section>
            <p v-if="loading" role="status">Loading…</p>
            <p v-else-if="loadError" role="alert">{{ loadError }}</p>
            <p v-else-if="!rows.length" role="status">{{ emptyPrompt || 'No entries found for these filters.' }}</p>
            <div v-if="tab === 'cashbank' && rows.length" class="cashbook-navigation"><span>{{ rows.length }} entries · Oldest first. Scroll inside the table to see later transactions.</span><button type="button" @click="showLatestEntries">Latest entries ↓</button></div>
            <section class="panel"><div ref="ledgerTable" class="table-wrapper"><table v-if="!tab.includes('outstanding')"><thead><tr><th>Date</th><th>Type</th><th>Accounting Voucher</th><th>Source Document / Reference</th><th>Particulars</th><th>Debit</th><th>Credit</th><th>Running Balance</th><th>Due</th></tr></thead><tbody><tr v-for="(r,i) in rows" :key="i"><td>{{ formatDate(r.voucher_date) }}</td><td>{{ r.voucher_type }}</td><td>{{ r.voucher_number }}</td><td>{{ r.reference_number || '-' }}</td><td>{{ r.narration || r.account_name }}</td><td>Rs. {{ money(r.debit_amount) }}</td><td>Rs. {{ money(r.credit_amount) }}</td><td>Rs. {{ balance(r.running_balance) }}</td><td>{{ formatDate(r.due_date) }}</td></tr></tbody></table><table v-else-if="tab==='customer_outstanding'"><thead><tr><th>Invoice</th><th>Date</th><th>Due</th><th>Amount</th><th>Received</th><th>Credit Note</th><th>Outstanding</th><th>Ageing</th></tr></thead><tbody><tr v-for="(r,i) in rows" :key="i"><td>{{ r.invoice_number }}</td><td>{{ formatDate(r.invoice_date) }}</td><td>{{ formatDate(r.due_date) }}</td><td>Rs. {{ money(r.invoice_amount) }}</td><td>Rs. {{ money(r.received_amount) }}</td><td>Rs. {{ money(r.credit_note_amount) }}</td><td>Rs. {{ money(r.outstanding_amount) }}</td><td>{{ ageing(r.ageing_days) }}</td></tr></tbody></table><table v-else><thead><tr><th>Purchase</th><th>Date</th><th>Due</th><th>Amount</th><th>Paid</th><th>Outstanding</th><th>Ageing</th></tr></thead><tbody><tr v-for="(r,i) in rows" :key="i"><td>{{ r.purchase_number }}</td><td>{{ formatDate(r.purchase_date) }}</td><td>{{ formatDate(r.due_date) }}</td><td>Rs. {{ money(r.purchase_amount) }}</td><td>Rs. {{ money(r.paid_amount) }}</td><td>Rs. {{ money(r.outstanding_amount) }}</td><td>{{ ageing(r.ageing_days) }}</td></tr></tbody></table></div></section>
        </div>
    </Layout>
</template>

<style scoped>
.acct-page{padding:4px 0 28px}.page-heading,.tabs{display:flex;align-items:center;justify-content:space-between;gap:10px}.page-heading{margin-bottom:18px}.page-heading span{color:#2457d6;font-size:10px;font-weight:800;letter-spacing:1.2px}.page-heading h1{margin:0;color:#142139}.page-heading p{margin:6px 0 0;color:#758197;font-size:13px}.tabs{justify-content:flex-start;flex-wrap:wrap;margin-bottom:12px}.tabs .active{color:#fff;background:#2457d6;border-color:#2457d6}.panel{margin-bottom:18px;padding:18px;background:#fff;border:1px solid #dfe6ef;border-radius:8px}.form-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}input,select,button{min-height:38px;padding:8px 10px;color:#344159;background:#fff;border:1px solid #d8e0eb;border-radius:8px;font-size:12px}button{font-weight:750;cursor:pointer}.table-wrapper{overflow-x:auto}table{width:100%;border-collapse:collapse}th,td{padding:11px 10px;border-bottom:1px solid #edf1f5;text-align:left;white-space:nowrap;font-size:12px}th{color:#69758a;background:#f8fafc;font-size:10px;text-transform:uppercase}@media(max-width:900px){.form-grid{grid-template-columns:1fr}.page-heading{align-items:stretch;flex-direction:column}}
.form-grid{align-items:end}.form-grid label{display:grid;gap:6px;font-size:12px;color:#667085}.form-grid select,.form-grid input{width:100%;min-width:0}
.cashbook-navigation{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:12px 0;font-size:12px;color:#667085;flex-wrap:wrap}
</style>
