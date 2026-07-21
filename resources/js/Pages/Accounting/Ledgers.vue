<script setup>
import { onMounted, reactive, ref } from 'vue';
import Layout from '../Layout.vue';
import AccountingApi from './AccountingApi';

defineProps({ page: { type: String, default: 'ledgers' }, title: { type: String, default: 'Ledgers and Books' } });
const refs = ref({ accounts: [], cash_bank_accounts: [], customers: [], suppliers: [], branches: [] });
const tab = ref('account');
const rows = ref([]);
const filters = reactive({ account_id: '', customer_id: '', supplier_id: '', branch_id: '', date_from: '', date_to: '' });
const loadRefs = async () => { refs.value = await AccountingApi.references(); };
const load = async () => {
    if (tab.value === 'cashbank') rows.value = await AccountingApi.cashBankBook(filters);
    else if (tab.value === 'customer_outstanding') rows.value = await AccountingApi.customerOutstanding({ customer_id: filters.customer_id });
    else if (tab.value === 'supplier_outstanding') rows.value = await AccountingApi.supplierOutstanding({ supplier_id: filters.supplier_id });
    else rows.value = await AccountingApi.ledger(filters);
};
const exportRows = () => {
    const csv = rows.value.map((row) => Object.values(row).map((v) => `"${String(v ?? '').replace(/"/g, '""')}"`).join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv' }); const url = URL.createObjectURL(blob); const a = document.createElement('a'); a.href = url; a.download = `${tab.value}.csv`; a.click(); URL.revokeObjectURL(url);
};
const money = (v) => Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
onMounted(async () => { await loadRefs(); await load(); });
</script>

<template>
    <Layout :page="page" :title="title">
        <div class="acct-page">
            <div class="page-heading"><div><span>ACCOUNTING</span><h1>Ledgers and Outstanding</h1><p>Customer, supplier, account, cash and bank running balances.</p></div><button @click="exportRows">Export</button></div>
            <section class="tabs"><button :class="{active:tab==='account'}" @click="tab='account';load()">Account Ledger</button><button :class="{active:tab==='customer'}" @click="tab='customer';load()">Customer Ledger</button><button :class="{active:tab==='supplier'}" @click="tab='supplier';load()">Supplier Ledger</button><button :class="{active:tab==='cashbank'}" @click="tab='cashbank';load()">Cash / Bank Book</button><button :class="{active:tab==='customer_outstanding'}" @click="tab='customer_outstanding';load()">Customer Outstanding</button><button :class="{active:tab==='supplier_outstanding'}" @click="tab='supplier_outstanding';load()">Supplier Outstanding</button></section>
            <section class="panel"><div class="form-grid"><select v-model="filters.account_id"><option value="">Account</option><option v-for="a in (tab==='cashbank' ? refs.cash_bank_accounts : refs.accounts)" :key="a.id" :value="a.id">{{ a.account_name }}</option></select><select v-model="filters.customer_id"><option value="">Customer</option><option v-for="c in refs.customers" :key="c.id" :value="c.id">{{ c.customer_name }}</option></select><select v-model="filters.supplier_id"><option value="">Supplier</option><option v-for="s in refs.suppliers" :key="s.id" :value="s.id">{{ s.supplier_name || s.name }}</option></select><select v-model="filters.branch_id"><option value="">Branch</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select><input v-model="filters.date_from" type="date" /><input v-model="filters.date_to" type="date" /><button @click="load">Apply</button></div></section>
            <section class="panel"><div class="table-wrapper"><table v-if="!tab.includes('outstanding')"><thead><tr><th>Date</th><th>Type</th><th>Voucher</th><th>Reference</th><th>Particulars</th><th>Debit</th><th>Credit</th><th>Running</th><th>Due</th></tr></thead><tbody><tr v-for="(r,i) in rows" :key="i"><td>{{ r.voucher_date }}</td><td>{{ r.voucher_type }}</td><td>{{ r.voucher_number }}</td><td>{{ r.reference_number || '-' }}</td><td>{{ r.narration || r.account_name }}</td><td>Rs. {{ money(r.debit_amount) }}</td><td>Rs. {{ money(r.credit_amount) }}</td><td>Rs. {{ money(r.running_balance) }}</td><td>{{ r.due_date || '-' }}</td></tr></tbody></table><table v-else-if="tab==='customer_outstanding'"><thead><tr><th>Invoice</th><th>Date</th><th>Due</th><th>Amount</th><th>Received</th><th>Outstanding</th><th>Ageing</th></tr></thead><tbody><tr v-for="(r,i) in rows" :key="i"><td>{{ r.invoice_number }}</td><td>{{ r.invoice_date }}</td><td>{{ r.due_date || '-' }}</td><td>Rs. {{ money(r.invoice_amount) }}</td><td>Rs. {{ money(r.received_amount) }}</td><td>Rs. {{ money(r.outstanding_amount) }}</td><td>{{ r.ageing_days }}</td></tr></tbody></table><table v-else><thead><tr><th>Purchase</th><th>Date</th><th>Due</th><th>Amount</th><th>Paid</th><th>Outstanding</th><th>Ageing</th></tr></thead><tbody><tr v-for="(r,i) in rows" :key="i"><td>{{ r.purchase_number }}</td><td>{{ r.purchase_date }}</td><td>{{ r.due_date || '-' }}</td><td>Rs. {{ money(r.purchase_amount) }}</td><td>Rs. {{ money(r.paid_amount) }}</td><td>Rs. {{ money(r.outstanding_amount) }}</td><td>{{ r.ageing_days }}</td></tr></tbody></table></div></section>
        </div>
    </Layout>
</template>

<style scoped>
.acct-page{padding:4px 0 28px}.page-heading,.tabs{display:flex;align-items:center;justify-content:space-between;gap:10px}.page-heading{margin-bottom:18px}.page-heading span{color:#2457d6;font-size:10px;font-weight:800;letter-spacing:1.2px}.page-heading h1{margin:0;color:#142139}.page-heading p{margin:6px 0 0;color:#758197;font-size:13px}.tabs{justify-content:flex-start;flex-wrap:wrap;margin-bottom:12px}.tabs .active{color:#fff;background:#2457d6;border-color:#2457d6}.panel{margin-bottom:18px;padding:18px;background:#fff;border:1px solid #dfe6ef;border-radius:8px}.form-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}input,select,button{min-height:38px;padding:8px 10px;color:#344159;background:#fff;border:1px solid #d8e0eb;border-radius:8px;font-size:12px}button{font-weight:750;cursor:pointer}.table-wrapper{overflow-x:auto}table{width:100%;border-collapse:collapse}th,td{padding:11px 10px;border-bottom:1px solid #edf1f5;text-align:left;white-space:nowrap;font-size:12px}th{color:#69758a;background:#f8fafc;font-size:10px;text-transform:uppercase}@media(max-width:900px){.form-grid{grid-template-columns:1fr}.page-heading{align-items:stretch;flex-direction:column}}
</style>
