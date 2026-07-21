<script setup>
import { onMounted, reactive, ref } from 'vue';
import Layout from '../Layout.vue';
import FinancialApi from './FinancialApi';

defineProps({ page: { type: String, default: 'reports' }, title: { type: String, default: 'Financial Reports' } });

const tab = ref('dashboard');
const loading = ref(false);
const saving = ref(false);
const refs = ref({ accounts: [], groups: [], branches: [], periods: [] });
const data = ref({});
const errors = ref({});
const filters = reactive({ date_from: '', date_to: new Date().toISOString().slice(0, 10), branch_id: '', account_id: '', section: '', include_zero: false });
const closing = reactive({ financial_year: '', closing_date: new Date().toISOString().slice(0, 10), retained_earnings_account_id: '', status: 'under_review' });
const tabs = ['dashboard', 'day book', 'journal', 'ledger', 'trial balance', 'profit and loss', 'balance sheet', 'cash flow', 'receivables', 'payables', 'schedules', 'comparative', 'branches', 'ratios', 'exceptions', 'closing'];

const money = (v) => Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const capture = (e) => { errors.value = e?.response?.data?.errors || { form: [e?.response?.data?.message || 'Unable to load report.'] }; };
const clearErrors = () => { errors.value = {}; };
const params = () => ({ ...filters, include_zero: filters.include_zero ? 1 : 0 });

const load = async () => {
    loading.value = true; clearErrors();
    try {
        if (tab.value === 'dashboard') data.value = await FinancialApi.dashboard(params());
        else if (tab.value === 'day book') data.value = await FinancialApi.dayBook(params());
        else if (tab.value === 'journal') data.value = await FinancialApi.journalRegister(params());
        else if (tab.value === 'ledger') data.value = filters.account_id ? await FinancialApi.ledger(params()) : { transactions: [] };
        else if (tab.value === 'trial balance') data.value = await FinancialApi.trialBalance(params());
        else if (tab.value === 'profit and loss') data.value = await FinancialApi.profitAndLoss(params());
        else if (tab.value === 'balance sheet') data.value = await FinancialApi.balanceSheet(params());
        else if (tab.value === 'cash flow') data.value = await FinancialApi.cashFlow(params());
        else if (tab.value === 'receivables') data.value = await FinancialApi.receivables(params());
        else if (tab.value === 'payables') data.value = await FinancialApi.payables(params());
        else if (tab.value === 'schedules') data.value = filters.section ? await FinancialApi.schedule(params()) : { rows: [] };
        else if (tab.value === 'comparative') data.value = await FinancialApi.comparative(params());
        else if (tab.value === 'branches') data.value = await FinancialApi.branchFinancials(params());
        else if (tab.value === 'ratios') data.value = await FinancialApi.ratios(params());
        else if (tab.value === 'exceptions') data.value = await FinancialApi.exceptions(params());
    } catch (e) { capture(e); } finally { loading.value = false; }
};
const loadRefs = async () => { refs.value = await FinancialApi.references(); };
const switchTab = async (next) => { tab.value = next; await load(); };
const exportCsv = () => {
    const source = data.value.items || data.value.rows || data.value.transactions || data.value.sections || data.value;
    const rows = Array.isArray(source) ? source : Object.entries(source).map(([key, value]) => ({ key, value: typeof value === 'object' ? JSON.stringify(value) : value }));
    const headers = Object.keys(rows[0] || { report: '', value: '' });
    const csv = [headers, ...rows.map((row) => headers.map((h) => `"${String(row[h] ?? '').replace(/"/g, '""')}"`))].map((line) => line.join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv' }); const url = URL.createObjectURL(blob); const a = document.createElement('a'); a.href = url; a.download = `${tab.value.replaceAll(' ', '-')}.csv`; a.click(); URL.revokeObjectURL(url);
};
const printReport = () => window.print();
const snapshot = async (type) => { saving.value = true; try { await FinancialApi.snapshot({ report_type: type, period_start: filters.date_from || null, period_end: filters.date_to || null, branch_id: filters.branch_id || null }); alert('Snapshot created.'); } finally { saving.value = false; } };
const closeYear = async () => { saving.value = true; clearErrors(); try { const result = await FinancialApi.closeYear({ ...closing }); data.value = result.closure; alert(result.message); } catch (e) { capture(e); } finally { saving.value = false; } };
onMounted(async () => { await loadRefs(); await load(); });
</script>

<template>
    <Layout :page="page" :title="title">
        <div class="financial-page">
            <div class="page-heading"><div><span>FINANCIAL REPORTS</span><h1>Reports and Statements</h1><p>Posted journal based statements, schedules, exceptions, snapshots and closing controls.</p></div><div class="actions"><button @click="printReport">Print</button><button @click="exportCsv">Export</button></div></div>
            <section class="tabs"><button v-for="t in tabs" :key="t" :class="{active:tab===t}" @click="switchTab(t)">{{ t }}</button></section>
            <section class="panel filters"><select v-model="filters.branch_id"><option value="">All Branches</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select><select v-model="filters.account_id"><option value="">Account</option><option v-for="a in refs.accounts" :key="a.id" :value="a.id">{{ a.account_name }}</option></select><select v-model="filters.section"><option value="">Schedule Section</option><option v-for="g in refs.groups" :key="g.id" :value="g.financial_statement_section">{{ g.financial_statement_section || g.group_name }}</option></select><input v-model="filters.date_from" type="date" /><input v-model="filters.date_to" type="date" /><label><input v-model="filters.include_zero" type="checkbox" /> Zero rows</label><button :disabled="loading" @click="load">Apply</button></section>
            <div v-if="errors.form" class="alert">{{ errors.form[0] }}</div>

            <section v-if="tab==='dashboard'" class="panel cards"><div><span>Total Revenue</span><strong>Rs. {{ money(data.total_revenue) }}</strong></div><div><span>Gross Profit</span><strong>Rs. {{ money(data.gross_profit) }}</strong></div><div><span>Net Profit</span><strong>Rs. {{ money(data.net_profit) }}</strong></div><div><span>Cash</span><strong>Rs. {{ money(data.cash_balance) }}</strong></div><div><span>Bank</span><strong>Rs. {{ money(data.bank_balance) }}</strong></div><div><span>Receivables</span><strong>Rs. {{ money(data.receivables) }}</strong></div><div><span>Payables</span><strong>Rs. {{ money(data.payables) }}</strong></div><div><span>Inventory</span><strong>Rs. {{ money(data.inventory_value) }}</strong></div></section>

            <section v-if="tab==='day book'" class="panel"><h3>Day Book</h3><p>Debit Rs. {{ money(data.totals?.debit) }} | Credit Rs. {{ money(data.totals?.credit) }}</p><div class="table-wrapper"><table><thead><tr><th>Date</th><th>Type</th><th>Voucher</th><th>Reference</th><th>Narration</th><th>Debit</th><th>Credit</th><th>Status</th></tr></thead><tbody><tr v-for="r in data.items" :key="r.id"><td>{{ r.voucher_date }}</td><td>{{ r.voucher_type }}</td><td>{{ r.voucher_number }}</td><td>{{ r.reference_number }}</td><td>{{ r.narration }}</td><td>Rs. {{ money(r.total_debit) }}</td><td>Rs. {{ money(r.total_credit) }}</td><td>{{ r.status }}</td></tr></tbody></table></div></section>

            <section v-if="tab==='journal'" class="panel"><h3>Journal Register</h3><div class="table-wrapper"><table><thead><tr><th>Date</th><th>Voucher</th><th>Account</th><th>Debit</th><th>Credit</th><th>Narration</th></tr></thead><tbody><tr v-for="r in data.items" :key="r.id"><td>{{ r.voucher_date }}</td><td>{{ r.voucher_number }}</td><td>{{ r.account_name }}</td><td>Rs. {{ money(r.debit_amount) }}</td><td>Rs. {{ money(r.credit_amount) }}</td><td>{{ r.narration }}</td></tr></tbody></table></div></section>

            <section v-if="tab==='ledger'" class="panel"><h3>{{ data.account?.account_name || 'General Ledger' }}</h3><p>Opening Rs. {{ money(data.opening_balance) }} | Debit Rs. {{ money(data.period_debit) }} | Credit Rs. {{ money(data.period_credit) }} | Closing Rs. {{ money(data.closing_balance) }}</p><div class="table-wrapper"><table><thead><tr><th>Date</th><th>Voucher</th><th>Particulars</th><th>Debit</th><th>Credit</th><th>Running</th></tr></thead><tbody><tr v-for="r in data.transactions" :key="r.id"><td>{{ r.voucher_date }}</td><td>{{ r.voucher_number }}</td><td>{{ r.narration || r.account_name }}</td><td>Rs. {{ money(r.debit_amount) }}</td><td>Rs. {{ money(r.credit_amount) }}</td><td>Rs. {{ money(r.running_balance) }}</td></tr></tbody></table></div></section>

            <section v-if="tab==='trial balance'" class="panel"><div class="statement-head"><h3>Trial Balance</h3><button @click="snapshot('trial_balance')" :disabled="saving">Snapshot</button></div><div v-if="data.is_balanced===false" class="alert">Trial Balance mismatch: debit Rs. {{ money(data.totals?.closing_debit) }}, credit Rs. {{ money(data.totals?.closing_credit) }}</div><div class="table-wrapper"><table><thead><tr><th>Group</th><th>Code</th><th>Account</th><th>Opening Dr</th><th>Opening Cr</th><th>Period Dr</th><th>Period Cr</th><th>Closing Dr</th><th>Closing Cr</th></tr></thead><tbody><tr v-for="r in data.rows" :key="r.account_id"><td>{{ r.group_name }}</td><td>{{ r.account_code }}</td><td>{{ r.account_name }}</td><td>{{ money(r.opening_debit) }}</td><td>{{ money(r.opening_credit) }}</td><td>{{ money(r.period_debit) }}</td><td>{{ money(r.period_credit) }}</td><td>{{ money(r.closing_debit) }}</td><td>{{ money(r.closing_credit) }}</td></tr></tbody></table></div></section>

            <section v-if="tab==='profit and loss'" class="panel"><div class="statement-head"><h3>Profit and Loss</h3><button @click="snapshot('profit_and_loss')" :disabled="saving">Snapshot</button></div><div v-for="s in data.sections" :key="s.section" class="section-row"><strong>{{ s.section }}</strong><span>Rs. {{ money(s.total) }}</span></div><div class="total-row"><strong>Gross Profit</strong><span>Rs. {{ money(data.gross_profit) }}</span></div><div class="total-row"><strong>Net Profit</strong><span>Rs. {{ money(data.net_profit) }}</span></div></section>

            <section v-if="tab==='balance sheet'" class="panel"><div class="statement-head"><h3>Balance Sheet</h3><button @click="snapshot('balance_sheet')" :disabled="saving">Snapshot</button></div><div v-if="data.is_balanced===false" class="alert">Balance Sheet difference Rs. {{ money(data.difference) }}</div><div v-for="s in data.sections" :key="s.section" class="section-row"><strong>{{ s.section }}</strong><span>Rs. {{ money(s.total) }}</span></div><div class="total-row"><strong>Assets</strong><span>Rs. {{ money(data.assets_total) }}</span></div><div class="total-row"><strong>Liabilities + Equity</strong><span>Rs. {{ money(data.liabilities_equity_total) }}</span></div></section>

            <section v-if="tab==='cash flow'" class="panel"><div class="statement-head"><h3>Cash Flow</h3><button @click="snapshot('cash_flow')" :disabled="saving">Snapshot</button></div><div v-if="data.reconciles===false" class="alert">Cash flow does not reconcile with cash and bank balances.</div><div class="section-row"><strong>Opening Cash</strong><span>Rs. {{ money(data.opening_cash_equivalents) }}</span></div><div class="section-row"><strong>Operating</strong><span>Rs. {{ money(data.operating_activities) }}</span></div><div class="section-row"><strong>Investing</strong><span>Rs. {{ money(data.investing_activities) }}</span></div><div class="section-row"><strong>Financing</strong><span>Rs. {{ money(data.financing_activities) }}</span></div><div class="total-row"><strong>Closing Cash</strong><span>Rs. {{ money(data.closing_cash_equivalents) }}</span></div></section>

            <section v-if="['receivables','payables'].includes(tab)" class="panel"><h3>{{ tab }}</h3><p>Closing Rs. {{ money(data.totals?.closing_balance) }} | Overdue Rs. {{ money(data.totals?.overdue_amount) }} | Advance Rs. {{ money(data.totals?.advance_amount) }}</p><div class="table-wrapper"><table><thead><tr><th>Party</th><th>Debit</th><th>Credit</th><th>Closing</th><th>Overdue</th><th>Advance</th></tr></thead><tbody><tr v-for="r in data.rows" :key="r.party_id"><td>#{{ r.party_id }}</td><td>{{ money(r.debit) }}</td><td>{{ money(r.credit) }}</td><td>{{ money(r.closing_balance) }}</td><td>{{ money(r.overdue_amount) }}</td><td>{{ money(r.advance_amount) }}</td></tr></tbody></table></div></section>

            <section v-if="tab==='schedules'" class="panel"><h3>{{ data.section || 'Account Schedule' }}</h3><div class="table-wrapper"><table><thead><tr><th>Code</th><th>Account</th><th>Opening</th><th>Debit</th><th>Credit</th><th>Closing</th></tr></thead><tbody><tr v-for="r in data.rows" :key="r.account_id"><td>{{ r.account_code }}</td><td>{{ r.account_name }}</td><td>{{ money(r.opening_balance) }}</td><td>{{ money(r.movement?.debit) }}</td><td>{{ money(r.movement?.credit) }}</td><td>{{ money(r.closing_balance) }}</td></tr></tbody></table></div></section>

            <section v-if="tab==='comparative'" class="panel"><h3>Comparative Report</h3><div class="section-row"><strong>Current Net Profit</strong><span>Rs. {{ money(data.current?.net_profit) }}</span></div><div class="section-row"><strong>Comparative Net Profit</strong><span>Rs. {{ money(data.comparison?.net_profit) }}</span></div><div class="total-row"><strong>Difference</strong><span>Rs. {{ money(data.difference) }} ({{ data.percent_change ?? 'NA' }}%)</span></div></section>

            <section v-if="tab==='branches'" class="panel"><h3>Branch Financials</h3><div class="table-wrapper"><table><thead><tr><th>Branch</th><th>TB Dr</th><th>TB Cr</th><th>Net Profit</th><th>Closing Cash</th></tr></thead><tbody><tr v-for="r in data" :key="r.branch_id"><td>{{ r.branch_name }}</td><td>{{ money(r.trial_balance?.closing_debit) }}</td><td>{{ money(r.trial_balance?.closing_credit) }}</td><td>{{ money(r.profit_and_loss?.net_profit) }}</td><td>{{ money(r.cash_flow?.closing_cash_equivalents) }}</td></tr></tbody></table></div></section>

            <section v-if="tab==='ratios'" class="panel"><h3>Ratio Analysis</h3><div v-for="(r,key) in data" :key="key" class="section-row"><strong>{{ key.replaceAll('_',' ') }}</strong><span>{{ r.value ?? 'Not available' }} <small>{{ r.formula }}</small></span></div></section>

            <section v-if="tab==='exceptions'" class="panel"><h3>Exception Center</h3><div class="table-wrapper"><table><thead><tr><th>Severity</th><th>Type</th><th>Source</th><th>Message</th><th>Action</th></tr></thead><tbody><tr v-for="(r,i) in data" :key="i"><td>{{ r.severity }}</td><td>{{ r.exception_type }}</td><td>{{ r.source_number || r.source_id }}</td><td>{{ r.message }}</td><td>{{ r.suggested_action }}</td></tr></tbody></table></div></section>

            <section v-if="tab==='closing'" class="panel"><h3>Year-End Closing</h3><div class="filters"><input v-model="closing.financial_year" placeholder="Financial Year" /><input v-model="closing.closing_date" type="date" /><select v-model="closing.retained_earnings_account_id"><option value="">Retained Earnings Account</option><option v-for="a in refs.accounts" :key="a.id" :value="a.id">{{ a.account_name }}</option></select><select v-model="closing.status"><option>under_review</option><option>closed</option></select><button :disabled="saving" @click="closeYear">Save Closing</button></div></section>
        </div>
    </Layout>
</template>

<style scoped>
.financial-page{padding:4px 0 28px}.page-heading,.tabs,.actions,.statement-head,.section-row,.total-row{display:flex;align-items:center;gap:12px}.page-heading{justify-content:space-between;margin-bottom:18px}.page-heading span{color:#2457d6;font-size:10px;font-weight:800;letter-spacing:1.2px}.page-heading h1{margin:0;color:#142139}.page-heading p{margin:6px 0 0;color:#758197;font-size:13px}.tabs{justify-content:flex-start;flex-wrap:wrap;margin-bottom:12px}.tabs .active{color:#fff;background:#2457d6;border-color:#2457d6}.panel{margin-bottom:18px;padding:18px;background:#fff;border:1px solid #dfe6ef;border-radius:8px}.filters{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:10px}.cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.cards div{padding:14px;border:1px solid #edf1f5;border-radius:8px}.cards span{display:block;color:#69758a;font-size:11px}.cards strong{display:block;margin-top:6px;color:#142139;font-size:18px}.statement-head,.section-row,.total-row{justify-content:space-between}.section-row,.total-row{padding:10px 0;border-bottom:1px solid #edf1f5}.total-row{font-size:15px}input,select,button{min-height:38px;padding:8px 10px;color:#344159;background:#fff;border:1px solid #d8e0eb;border-radius:8px;font-size:12px}button{font-weight:750;cursor:pointer}.alert{padding:10px 12px;margin-bottom:12px;border-radius:8px;background:#fff4f4;color:#b42318;border:1px solid #ffd5d5;font-size:12px}.table-wrapper{overflow-x:auto}table{width:100%;border-collapse:collapse}th,td{padding:11px 10px;border-bottom:1px solid #edf1f5;text-align:left;white-space:nowrap;font-size:12px}th{color:#69758a;background:#f8fafc;font-size:10px;text-transform:uppercase}@media print{.tabs,.filters,.actions{display:none}.panel{border:0}}@media(max-width:1000px){.filters,.cards{grid-template-columns:1fr}.page-heading{align-items:stretch;flex-direction:column}}
</style>
