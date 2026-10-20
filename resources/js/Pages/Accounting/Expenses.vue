<script setup>
import { formatDate } from '../../Support/dateFormat.js';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import Layout from '../Layout.vue';
import AccountingApi from './AccountingApi';
import { flattenErrors } from '../../Support/errorMessages';
import SearchSelect from '../../Components/Common/SearchSelect.vue';
import CategoryManager from './CategoryManager.vue';

defineProps({ page: { type: String, default: 'expenses' }, title: { type: String, default: 'Expenses, Income and Bank Reconciliation' } });

const activeTab = ref('expenses');
const loading = ref(false);
const saving = ref(false);
const errors = ref({});
const refs = ref({ expense_categories: [], income_categories: [], expense_accounts: [], income_accounts: [], cash_bank_accounts: [], branches: [], suppliers: [], customers: [] });
const expenses = ref([]);
const incomes = ref([]);
const recurring = ref([]);
const pettyCash = ref([]);
const imports = ref([]);
const statementLines = ref([]);
const reconciliations = ref([]);
const report = ref({ expense_summary: {}, income_summary: {} });
const filters = reactive({ search: '', status: '', category_id: '', date_from: '', date_to: '' });

const expenseForm = reactive({
    id: null, branch_id: '', expense_date: new Date().toISOString().slice(0, 10), expense_category_id: '', expense_account_id: '',
    paid_from_account_id: '', party_name: '', supplier_id: '', employee_id: '', invoice_number: '', invoice_date: '',
    payment_mode: 'cash', reference_number: '', tax_type: 'exclusive', tds_applicable: false, tds_rate: 0,
    payment_status: 'paid', status: 'draft', narration: '',
    items: [{ expense_category_id: '', expense_account_id: '', description: '', quantity: 1, rate: 0, discount_amount: 0, gst_rate: 0, cess_amount: 0 }],
});
const newIncomeForm = () => ({ id: null, branch_id: '', income_date: new Date().toISOString().slice(0, 10), income_category_id: '', income_account_id: '', received_into_account_id: '', customer_id: '', party_name: '', payment_mode: 'cash', reference_number: '', tax_type: 'exclusive', taxable_amount: 0, non_taxable_amount: 0, cgst_amount: 0, sgst_amount: 0, igst_amount: 0, cess_amount: 0, total_amount: 0, status: 'draft', narration: '' });
const incomeForm = reactive(newIncomeForm());
const incomeErrors = ref({});
const incomeMessage = ref('');
const incomePosted = computed(() => incomeForm.status === 'posted' && !!incomeForm.id);
const incomeErrorMessages = computed(() => flattenErrors(incomeErrors.value));
const newRecurringForm = () => ({ id: null, branch_id: '', template_name: '', expense_category_id: '', expense_account_id: '', paid_from_account_id: '', supplier_id: '', employee_id: '', frequency: 'monthly', interval_value: 1, start_date: new Date().toISOString().slice(0, 10), next_run_date: new Date().toISOString().slice(0, 10), end_date: '', amount: 0, tax_type: 'exclusive', gst_rate: 0, auto_create_draft: true, auto_post: false, approval_required: true, status: 'active', narration: '' });
const recurringForm = reactive(newRecurringForm());
const recurringErrors = ref({});
const recurringMessage = ref('');
const recurringSaving = ref(false);
const recurringPagination = ref({});
const recurringHeading = ref(null);
const recurringFields = [
    { key: 'template_name', label: 'Template Name', type: 'text', placeholder: 'e.g. Monthly Office Rent' },
    { key: 'expense_category_id', label: 'Expense Category', type: 'category' },
    { key: 'expense_account_id', label: 'Expense Account', type: 'account' },
    { key: 'frequency', label: 'Frequency', type: 'frequency' },
    { key: 'amount', label: 'Amount', type: 'number', help: 'Expense amount stored in this template.' },
    { key: 'next_run_date', label: 'Next Run Date', type: 'date', help: 'Next scheduled date recorded for this template.' },
    { key: 'status', label: 'Status', type: 'status' },
];
const recurringOptions = { frequency: ['daily', 'weekly', 'monthly', 'quarterly', 'half_yearly', 'yearly', 'custom'], status: ['active', 'paused', 'stopped'] };
const optionLabel = (value) => value.replaceAll('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase());
const recurringFieldError = (key) => flattenErrors({ [key]: recurringErrors.value[key] || [] })[0] || '';
const loadRecurring = async (page = 1) => {
    try { const result = await AccountingApi.recurringExpenses({ page }); recurring.value = result.recurring || []; recurringPagination.value = result.pagination || {}; }
    catch (error) { recurringErrors.value = { form: [error?.response?.data?.message || 'Unable to load recurring templates.'] }; }
};
const resetRecurring = () => { Object.assign(recurringForm, newRecurringForm()); recurringErrors.value = {}; recurringMessage.value = ''; };
const editRecurring = (row) => {
    resetRecurring();
    for (const key of Object.keys(recurringForm)) {
        if (row[key] !== undefined) recurringForm[key] = row[key];
    }
    for (const key of ['start_date', 'next_run_date', 'end_date']) {
        recurringForm[key] = row[key] ? formatDate(row[key], '').split('/').reverse().join('-') : '';
    }
    recurringHeading.value?.focus();
};

const newPettyForm = () => ({ id: null, branch_id: '', employee_id: '', cash_account_id: '', advance_date: new Date().toISOString().slice(0, 10), amount: 0, purpose: '', status: 'draft' });
const pettyForm = reactive(newPettyForm());
const pettyErrors = ref({});
const pettyMessage = ref('');
const pettySaving = ref(false);
const pettySaved = ref(false);
const pettyPagination = ref({});
const pettyFields = [
    { key: 'branch_id', label: 'Branch', type: 'branch', help: 'Optional branch for this advance.' },
    { key: 'employee_id', label: 'Employee', type: 'employee' },
    { key: 'cash_account_id', label: 'Cash Account', type: 'cash', help: 'Active cash accounts only. Bank accounts are not supported here.' },
    { key: 'advance_date', label: 'Advance Date', type: 'date' },
    { key: 'amount', label: 'Advance Amount', type: 'number', placeholder: 'Enter advance amount' },
    { key: 'purpose', label: 'Purpose', type: 'text', placeholder: 'e.g. Office supplies purchase' },
    { key: 'status', label: 'Status', type: 'status' },
];
const pettyEmployeeOptions = computed(() => (refs.value.petty_cash_employees || [])
    .filter(employee => !pettyForm.branch_id || Number(employee.branch_id) === Number(pettyForm.branch_id))
    .map(employee => ({ ...employee, label: `${employee.display_name} — ${employee.employee_code}` })));
watch(pettyEmployeeOptions, (employees) => {
    if (!pettySaved.value && pettyForm.employee_id && !employees.some(employee => Number(employee.id) === Number(pettyForm.employee_id))) pettyForm.employee_id = '';
});
const pettyFieldError = (key) => flattenErrors({ [key]: pettyErrors.value[key] || [] })[0] || '';
const loadPetty = async (page = 1) => {
    try { const result = await AccountingApi.pettyCash({ page }); pettyCash.value = result.advances || []; pettyPagination.value = result.pagination || {}; }
    catch (error) { pettyErrors.value = { form: [error?.response?.data?.message || 'Unable to load petty cash advances.'] }; }
};
const resetPetty = () => { Object.assign(pettyForm, newPettyForm()); pettyErrors.value = {}; pettyMessage.value = ''; pettySaved.value = false; };

const importForm = reactive({ bank_account_id: '', file_name: '', statement_start_date: '', statement_end_date: '', opening_balance: '', closing_balance: '', lines: [{ transaction_date: new Date().toISOString().slice(0, 10), description: '', reference_number: '', debit_amount: 0, credit_amount: 0, running_balance: '' }] });
const reconForm = reactive({ bank_account_id: '', statement_start_date: '', statement_end_date: '', statement_closing_balance: 0, items: [{ bank_statement_line_id: '', journal_entry_id: '', matched_amount: 0, match_type: 'manual', remarks: '' }] });

const tabs = ['expenses', 'income', 'categories', 'recurring', 'petty cash', 'bank import', 'reconciliation', 'reports'];
const money = (v) => Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const taxAmount = (row) => Math.round(((Number(row.quantity || 0) * Number(row.rate || 0) - Number(row.discount_amount || 0)) * Number(row.gst_rate || 0)) / 100);
const expenseTotals = computed(() => {
    let taxable = 0, tax = 0, total = 0;
    expenseForm.items.forEach((i) => { const base = Math.max(0, Number(i.quantity || 0) * Number(i.rate || 0) - Number(i.discount_amount || 0)); taxable += expenseForm.tax_type === 'non_taxable' ? 0 : base; tax += expenseForm.tax_type === 'non_taxable' ? 0 : taxAmount(i); total += base + (expenseForm.tax_type === 'non_taxable' ? 0 : taxAmount(i)) + Number(i.cess_amount || 0); });
    const tds = expenseForm.tds_applicable ? taxable * Number(expenseForm.tds_rate || 0) / 100 : 0;
    return { taxable, tax, total, tds, net: total - tds };
});

const capture = (error) => { errors.value = error?.response?.data?.errors || { form: [error?.response?.data?.message || 'Unable to save.'] }; };
const clearErrors = () => { errors.value = {}; };
const firstError = (field) => errors.value[field]?.[0] || '';
const run = async (fn) => { loading.value = true; try { await fn(); } finally { loading.value = false; } };
const loadRefs = async () => { refs.value = await AccountingApi.expenseReferences(); };
const loadAll = async () => run(async () => {
    await loadRefs();
    expenses.value = (await AccountingApi.expenses(filters)).expenses || [];
    incomes.value = (await AccountingApi.otherIncome()).income || [];
    await loadRecurring();
    await loadPetty();
    imports.value = (await AccountingApi.bankStatementImports()).imports || [];
    statementLines.value = (await AccountingApi.bankStatementLines({ bank_account_id: reconForm.bank_account_id || undefined })).lines || [];
    reconciliations.value = (await AccountingApi.bankReconciliations()).reconciliations || [];
    report.value = await AccountingApi.expenseReports(filters);
});
const selectExpenseCategory = () => { const c = refs.value.expense_categories.find((x) => x.id === Number(expenseForm.expense_category_id)); if (c) expenseForm.expense_account_id = c.expense_account_id; };
const selectIncomeCategory = () => { const c = refs.value.income_categories.find((x) => x.id === Number(incomeForm.income_category_id)); if (c) incomeForm.income_account_id = c.income_account_id; };
const addExpenseRow = () => expenseForm.items.push({ expense_category_id: expenseForm.expense_category_id, expense_account_id: expenseForm.expense_account_id, description: '', quantity: 1, rate: 0, discount_amount: 0, gst_rate: 0, cess_amount: 0 });
const addImportRow = () => importForm.lines.push({ transaction_date: new Date().toISOString().slice(0, 10), description: '', reference_number: '', debit_amount: 0, credit_amount: 0, running_balance: '' });
const addReconRow = () => reconForm.items.push({ bank_statement_line_id: '', journal_entry_id: '', matched_amount: 0, match_type: 'manual', remarks: '' });

const saveExpense = async (status = expenseForm.status) => { saving.value = true; clearErrors(); try { expenseForm.status = status; await AccountingApi.saveExpense({ ...expenseForm, items: expenseForm.items.map((i) => ({ ...i, expense_category_id: i.expense_category_id || expenseForm.expense_category_id, expense_account_id: i.expense_account_id || expenseForm.expense_account_id })) }, expenseForm.id); await loadAll(); } catch (e) { capture(e); } finally { saving.value = false; } };
const captureIncomeError = (error) => {
    const data = error?.response?.data;
    incomeErrors.value = data?.errors && Object.keys(data.errors).length
        ? data.errors
        : { form: [error?.response?.status === 419 ? 'Your session expired. Refresh the page and try again.' : data?.message || error?.message || 'Unable to save income. Please try again.'] };
};
const showSavedIncome = (income) => {
    incomes.value = [income, ...incomes.value.filter((row) => row.id !== income.id)];
    incomeMessage.value = income.status === 'posted'
        ? `Income ${income.voucher_number} approved and posted successfully.`
        : `Income ${income.voucher_number} saved as draft.`;
};
const resetIncome = () => { Object.assign(incomeForm, newIncomeForm()); incomeErrors.value = {}; incomeMessage.value = ''; };
const saveIncome = async (status = 'draft') => {
    if (saving.value || incomePosted.value) return;
    saving.value = true; incomeErrors.value = {}; incomeMessage.value = '';
    try {
        const result = await AccountingApi.saveOtherIncome({ ...incomeForm, status }, incomeForm.id);
        if (!result?.income?.id) throw new Error('The server did not return a saved income voucher. Refresh the list before retrying.');
        incomeForm.id = result.income.id;
        incomeForm.status = result.income.status;
        showSavedIncome(result.income);
    } catch (error) { captureIncomeError(error); }
    finally { saving.value = false; }
};
const saveRecurring = async () => {
    if (recurringSaving.value) return;
    recurringSaving.value = true; recurringErrors.value = {}; recurringMessage.value = '';
    try {
        const result = await AccountingApi.saveRecurringExpense({ ...recurringForm }, recurringForm.id);
        if (!result?.template?.id) throw new Error('The server did not return a saved template. Refresh the list before retrying.');
        recurringForm.id = result.template.id;
        recurring.value = [result.template, ...recurring.value.filter(row => row.id !== result.template.id)];
        recurringMessage.value = `Template “${result.template.template_name}” saved successfully.`;
    } catch (error) {
        recurringErrors.value = error?.response?.data?.errors || { form: [error?.response?.data?.message || error?.message || 'Unable to save template.'] };
    } finally { recurringSaving.value = false; }
};
const editPetty = (row) => {
    if (row.status !== 'draft' || pettySaving.value) return;
    resetPetty();
    for (const key of Object.keys(pettyForm)) if (row[key] !== undefined) pettyForm[key] = row[key];
    pettyForm.advance_date = formatDate(row.advance_date, '').split('/').reverse().join('-');
    document.getElementById('petty-branch_id')?.focus();
};
const pettyDraftAction = async (row, action) => {
    if (row.status !== 'draft' || pettySaving.value) return;
    const question = action === 'issue'
        ? `Issue ${row.voucher_number} for Rs. ${money(row.amount)}? This will post the advance and reduce its cash account balance.`
        : `Delete draft ${row.voucher_number}? This cannot be undone.`;
    if (!window.confirm(question)) return;
    pettySaving.value = true; pettyErrors.value = {}; pettyMessage.value = '';
    try {
        if (action === 'issue') {
            const result = await AccountingApi.issuePettyCash(row.id);
            pettyCash.value = pettyCash.value.map(item => item.id === row.id ? result.advance : item);
            if (pettyForm.id === row.id) resetPetty();
            pettyMessage.value = `Advance ${row.voucher_number} issued and posted successfully.`;
        } else {
            await AccountingApi.deletePettyCash(row.id);
            pettyCash.value = pettyCash.value.filter(item => item.id !== row.id);
            if (pettyForm.id === row.id) resetPetty();
            pettyMessage.value = `Draft ${row.voucher_number} deleted. No accounting entries were changed.`;
        }
    } catch (error) {
        pettyErrors.value = error?.response?.status >= 500
            ? { form: ['The server could not complete this action. Refresh the advance list before retrying, or ask your administrator to check the server logs.'] }
            : error?.response?.data?.errors || { form: [error?.response?.status === 419 ? 'Your session expired. Refresh this page before trying again.' : error?.response?.data?.message || error?.message || 'Unable to complete this action.'] };
    } finally { pettySaving.value = false; }
};
const savePetty = async () => {
    if (pettySaving.value || pettySaved.value) return;
    pettySaving.value = true; pettyErrors.value = {}; pettyMessage.value = '';
    try {
        const result = await AccountingApi.savePettyCash({ ...pettyForm }, pettyForm.id);
        if (!result?.advance?.id) throw new Error('The server did not return a saved advance. Refresh the list before retrying.');
        pettyCash.value = [result.advance, ...pettyCash.value.filter(row => row.id !== result.advance.id)];
        pettySaved.value = true;
        pettyMessage.value = result.advance.status === 'issued'
            ? `Advance ${result.advance.voucher_number} issued and posted successfully.`
            : `Advance ${result.advance.voucher_number} saved as draft. No accounting entries were posted.`;
    } catch (error) {
        pettyErrors.value = error?.response?.status >= 500
            ? { form: ['The server could not complete this advance. Refresh the advance list before retrying. If it continues, ask your administrator to check the petty cash account setup and server logs.'] }
            : error?.response?.data?.errors || { form: [error?.response?.status === 419 ? 'Your session expired. Refresh this page before trying again.' : error?.response?.data?.message || error?.message || 'Unable to save advance.'] };
    } finally { pettySaving.value = false; }
};
const saveImport = async () => { saving.value = true; clearErrors(); try { await AccountingApi.saveBankStatementImport({ ...importForm, lines: importForm.lines.map((i) => ({ ...i })) }); await loadAll(); } catch (e) { capture(e); } finally { saving.value = false; } };
const saveRecon = async () => { saving.value = true; clearErrors(); try { await AccountingApi.saveBankReconciliation({ ...reconForm, items: reconForm.items.map((i) => ({ ...i })) }); await loadAll(); } catch (e) { capture(e); } finally { saving.value = false; } };
const postExpense = async (row) => { saving.value = true; try { await AccountingApi.postExpense(row.id); await loadAll(); } finally { saving.value = false; } };
const postIncome = async (row) => {
    if (saving.value) return;
    saving.value = true; incomeErrors.value = {}; incomeMessage.value = '';
    try {
        const result = await AccountingApi.postOtherIncome(row.id);
        showSavedIncome({ ...row, ...result.income });
        if (incomeForm.id === row.id) incomeForm.status = result.income.status;
    } catch (error) { captureIncomeError(error); }
    finally { saving.value = false; }
};
onMounted(loadAll);
</script>

<template>
    <Layout :page="page" :title="title">
        <template #topbar-title>
            <div class="bill-page-title"><span>ACCOUNTING</span><h1>Expenses, Other Income and Bank</h1></div>
        </template>
        <div class="expense-page">
            <div class="page-toolbar"><button :disabled="loading" @click="loadAll">Refresh</button></div>
            <div class="tabs"><button v-for="tab in tabs" :key="tab" :class="{active: activeTab === tab}" @click="activeTab = tab">{{ tab }}</button></div>
            <div v-if="errors.form" class="alert">{{ errors.form[0] }}</div>

            <section v-if="activeTab === 'expenses'" class="panel">
                <div class="toolbar"><input v-model="filters.search" placeholder="Search voucher, party, invoice" @input="loadAll" /><select v-model="filters.status" @change="loadAll"><option value="">All statuses</option><option>draft</option><option>submitted</option><option>posted</option><option>reversed</option></select></div>
                <div class="form-grid"><select v-model="expenseForm.branch_id"><option value="">Branch</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select><input v-model="expenseForm.expense_date" type="date" /><select v-model="expenseForm.expense_category_id" @change="selectExpenseCategory"><option value="">Expense Category</option><option v-for="c in refs.expense_categories" :key="c.id" :value="c.id">{{ c.category_name }}</option></select><select v-model="expenseForm.expense_account_id"><option value="">Expense Account</option><option v-for="a in refs.expense_accounts" :key="a.id" :value="a.id">{{ a.account_name }}</option></select><select v-model="expenseForm.paid_from_account_id"><option value="">Paid From</option><option v-for="a in refs.cash_bank_accounts" :key="a.id" :value="a.id">{{ a.account_name }}</option></select><input v-model="expenseForm.party_name" placeholder="Party Name" /><select v-model="expenseForm.payment_mode"><option>cash</option><option>bank_transfer</option><option>UPI</option><option>card</option><option>cheque</option><option>wallet</option><option>employee_reimbursement</option><option>unpaid</option><option>mixed</option></select><select v-model="expenseForm.payment_status"><option>paid</option><option>partial</option><option>unpaid</option></select></div>
                <div class="line-grid header"><span>Category</span><span>Description</span><span>Qty</span><span>Rate</span><span>Disc</span><span>GST</span><span>Total</span><span></span></div>
                <div v-for="(item, i) in expenseForm.items" :key="i" class="line-grid"><select v-model="item.expense_category_id"><option value="">Category</option><option v-for="c in refs.expense_categories" :key="c.id" :value="c.id">{{ c.category_name }}</option></select><input v-model="item.description" placeholder="Description" /><input v-model.number="item.quantity" type="number" step="0.001" /><input v-model.number="item.rate" type="number" step="0.01" /><input v-model.number="item.discount_amount" type="number" step="0.01" /><input v-model.number="item.gst_rate" type="number" step="0.01" /><strong>Rs. {{ money((item.quantity * item.rate - item.discount_amount) + taxAmount(item)) }}</strong><button @click="expenseForm.items.splice(i, 1)" :disabled="expenseForm.items.length === 1">Remove</button></div>
                <div class="actions"><button @click="addExpenseRow">Add Row</button><span>Taxable Rs. {{ money(expenseTotals.taxable) }}</span><span>Tax Rs. {{ money(expenseTotals.tax) }}</span><span>TDS Rs. {{ money(expenseTotals.tds) }}</span><strong>Net Rs. {{ money(expenseTotals.net) }}</strong><button :disabled="saving" @click="saveExpense('draft')">Save Draft</button><button :disabled="saving" @click="saveExpense('posted')">Approve and Post</button></div>
                <p v-if="firstError('items')" class="field-error">{{ firstError('items') }}</p>
                <div class="table-wrapper"><table><thead><tr><th>No.</th><th>Date</th><th>Category</th><th>Party</th><th>Paid From</th><th>Total</th><th>Payment</th><th>Status</th><th></th></tr></thead><tbody><tr v-for="e in expenses" :key="e.id"><td>{{ e.voucher_number }}</td><td>{{ formatDate(e.expense_date) }}</td><td>{{ e.category?.category_name }}</td><td>{{ e.party_name || e.supplier?.name || '-' }}</td><td>{{ e.paid_from_account?.account_name || 'Payable' }}</td><td>Rs. {{ money(e.total_amount) }}</td><td>{{ e.payment_status }}</td><td>{{ e.status }}</td><td><button v-if="['draft','submitted'].includes(e.status)" @click="postExpense(e)">Post</button></td></tr></tbody></table></div>
            </section>

            <section v-if="activeTab === 'income'" class="panel">
                <div v-if="incomeErrorMessages.length" class="alert" role="alert"><p v-for="(message, index) in incomeErrorMessages" :key="index">{{ message }}</p></div>
                <p v-if="incomeMessage" class="income-success" role="status">{{ incomeMessage }}</p>
                <form @submit.prevent="saveIncome('posted')" novalidate>
                <fieldset :disabled="saving || incomePosted" class="form-grid income-form-grid">
                    <label class="income-field">Branch
                        <select v-model="incomeForm.branch_id"><option value="">Select branch</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select>
                    </label>
                    <label class="income-field">Income Date
                        <input v-model="incomeForm.income_date" type="date" />
                    </label>
                    <label class="income-field">Income Category
                        <select v-model="incomeForm.income_category_id" @change="selectIncomeCategory"><option value="">Select income category</option><option v-for="c in refs.income_categories" :key="c.id" :value="c.id">{{ c.category_name }}</option></select>
                    </label>
                    <label class="income-field">Income Account
                        <select v-model="incomeForm.income_account_id"><option value="">Select income account</option><option v-for="a in refs.income_accounts" :key="a.id" :value="a.id">{{ a.account_name }}</option></select>
                    </label>
                    <label class="income-field">Received Into (Cash / Bank)
                        <select v-model="incomeForm.received_into_account_id"><option value="">Select cash or bank account</option><option v-for="a in refs.cash_bank_accounts" :key="a.id" :value="a.id">{{ a.account_name }}</option></select>
                    </label>
                    <label class="income-field">Party Name
                        <input v-model="incomeForm.party_name" placeholder="Enter party name" />
                    </label>
                    <label class="income-field">Taxable Amount (Before Tax)
                        <input v-model.number="incomeForm.taxable_amount" type="number" placeholder="Enter taxable income amount" />
                    </label>
                    <label class="income-field">Total Amount Received
                        <input v-model.number="incomeForm.total_amount" type="number" placeholder="Enter total amount received" />
                    </label>
                </fieldset>
                <div class="actions"><button type="button" :disabled="saving || incomePosted" @click="saveIncome('draft')">Save Draft</button><button type="submit" :disabled="saving || incomePosted">{{ saving ? 'Saving…' : 'Approve and Post' }}</button><button v-if="incomeForm.id" type="button" :disabled="saving" @click="resetIncome">New Income</button></div>
                </form>
                <div class="table-wrapper"><table><thead><tr><th>No.</th><th>Date</th><th>Category</th><th>Party</th><th>Received Into</th><th>Total</th><th>Status</th><th></th></tr></thead><tbody><tr v-for="i in incomes" :key="i.id"><td>{{ i.voucher_number }}</td><td>{{ formatDate(i.income_date) }}</td><td>{{ i.category?.category_name }}</td><td>{{ i.party_name || i.customer?.customer_name || '-' }}</td><td>{{ i.received_into_account?.account_name }}</td><td>Rs. {{ money(i.total_amount) }}</td><td>{{ i.status }}</td><td><button v-if="['draft','submitted'].includes(i.status)" :disabled="saving" @click="postIncome(i)">Post</button></td></tr></tbody></table></div>
            </section>

            <CategoryManager v-if="activeTab === 'categories'" :refs="refs" @saved="loadRefs" />

            <section v-if="activeTab === 'recurring'" class="panel recurring-panel">
                <h3 ref="recurringHeading" tabindex="-1">{{ recurringForm.id ? 'Edit Recurring Template' : 'Recurring Expense Template' }}</h3>
                <p class="recurring-helper">Save a reusable expense template and its schedule details.</p>
                <div v-if="Object.keys(recurringErrors).length" role="alert" class="alert"><p v-for="(message, index) in flattenErrors(recurringErrors)" :key="index">{{ message }}</p></div>
                <p v-if="recurringMessage" role="status" class="income-success">{{ recurringMessage }}</p>
                <form @submit.prevent="saveRecurring" novalidate>
                    <fieldset :disabled="recurringSaving" class="form-grid recurring-grid">
                        <div v-for="field in recurringFields" :key="field.key" class="recurring-field">
                            <label :for="`recurring-${field.key}`">{{ field.label }}</label>
                            <select v-if="['category', 'account', 'frequency', 'status'].includes(field.type)" :id="`recurring-${field.key}`" v-model="recurringForm[field.key]" :aria-invalid="!!recurringFieldError(field.key)" :aria-describedby="recurringFieldError(field.key) ? `recurring-error-${field.key}` : undefined">
                                <template v-if="field.type === 'category'"><option value="">Select expense category</option><option v-for="c in refs.expense_categories" :key="c.id" :value="c.id">{{ c.category_name }}</option><option v-if="recurringForm.expense_category_id && !refs.expense_categories.some(c => c.id === Number(recurringForm.expense_category_id))" :value="recurringForm.expense_category_id">Current category (unavailable)</option></template>
                                <template v-else-if="field.type === 'account'"><option value="">Select expense account</option><option v-for="a in refs.expense_accounts" :key="a.id" :value="a.id">{{ a.account_name }}</option><option v-if="recurringForm.expense_account_id && !refs.expense_accounts.some(a => a.id === Number(recurringForm.expense_account_id))" :value="recurringForm.expense_account_id">Current account (unavailable)</option></template>
                                <template v-else><option v-for="option in recurringOptions[field.type]" :key="option" :value="option">{{ optionLabel(option) }}</option></template>
                            </select>
                            <input v-else :id="`recurring-${field.key}`" v-model="recurringForm[field.key]" :type="field.type" :placeholder="field.placeholder || (field.type === 'number' ? 'Enter expense amount' : undefined)" :step="field.type === 'number' ? '0.01' : undefined" :aria-invalid="!!recurringFieldError(field.key)" :aria-describedby="`recurring-help-${field.key} recurring-error-${field.key}`" />
                            <small v-if="field.help" :id="`recurring-help-${field.key}`" class="recurring-helper">{{ field.help }}</small>
                            <p v-if="recurringFieldError(field.key)" :id="`recurring-error-${field.key}`" class="recurring-error">{{ recurringFieldError(field.key) }}</p>
                        </div>
                        <div class="recurring-buttons"><button type="submit" class="recurring-primary">{{ recurringSaving ? 'Saving…' : 'Save Template' }}</button><button v-if="recurringForm.id" type="button" @click="resetRecurring">New Template</button></div>
                    </fieldset>
                </form>
                <h3 class="recurring-list-title">Existing Recurring Templates</h3>
                <div class="table-wrapper"><table><thead><tr><th>Template Name</th><th>Category</th><th>Expense Account</th><th>Amount</th><th>Frequency</th><th>Next Run Date</th><th>Status</th><th>Actions</th></tr></thead><tbody>
                    <tr v-for="r in recurring" :key="r.id"><td>{{ r.template_name }}</td><td>{{ r.category?.category_name || 'Category unavailable' }}</td><td>{{ r.account?.account_name || 'Account unavailable' }}</td><td>Rs. {{ money(r.amount) }}</td><td>{{ optionLabel(r.frequency) }}</td><td>{{ formatDate(r.next_run_date) }}</td><td>{{ optionLabel(r.status) }}</td><td><button :disabled="recurringSaving" @click="editRecurring(r)">Edit</button></td></tr>
                    <tr v-if="!recurring.length"><td colspan="8">No recurring templates yet.</td></tr>
                </tbody></table></div>
                <div v-if="recurringPagination.last_page > 1" class="actions"><button :disabled="recurringPagination.current_page <= 1 || recurringSaving" @click="loadRecurring(recurringPagination.current_page - 1)">Previous</button><span>Page {{ recurringPagination.current_page }} of {{ recurringPagination.last_page }}</span><button :disabled="recurringPagination.current_page >= recurringPagination.last_page || recurringSaving" @click="loadRecurring(recurringPagination.current_page + 1)">Next</button></div>
            </section>

            <section v-if="activeTab === 'petty cash'" class="panel petty-panel">
                <h3>{{ pettyForm.id ? 'Edit Draft Petty Cash Advance' : 'Petty Cash Advance' }}</h3>
                <p class="recurring-helper">Record a cash advance for an employee and choose whether to save a draft or issue it now.</p>
                <div v-if="Object.keys(pettyErrors).length" role="alert" class="alert"><p v-for="(message, index) in flattenErrors(pettyErrors)" :key="index">{{ message }}</p></div>
                <p v-if="pettyMessage" role="status" class="income-success">{{ pettyMessage }}</p>
                <form @submit.prevent="savePetty" novalidate>
                    <fieldset :disabled="pettySaving || pettySaved" class="form-grid recurring-grid">
                        <div v-for="field in pettyFields" :key="field.key" class="recurring-field">
                            <SearchSelect v-if="field.type === 'employee'" v-model="pettyForm.employee_id" label="Employee" :options="pettyEmployeeOptions" option-label-key="label" placeholder="Search employee name or code" :required="true" :disabled="pettySaving || pettySaved" :error="pettyFieldError('employee_id')" :hint="pettyEmployeeOptions.length ? 'Active employees in this business, filtered by the selected branch.' : 'No active employees available for this business and branch.'" />
                            <label v-else :for="`petty-${field.key}`">{{ field.label }}<span v-if="field.key !== 'branch_id'" class="petty-required" aria-hidden="true"> *</span></label>
                            <select v-if="['branch', 'cash', 'status'].includes(field.type)" :id="`petty-${field.key}`" :required="field.key !== 'branch_id'" :aria-required="field.key !== 'branch_id'" v-model="pettyForm[field.key]" :aria-invalid="!!pettyFieldError(field.key)" :aria-describedby="pettyFieldError(field.key) ? `petty-error-${field.key}` : undefined">
                                <template v-if="field.type === 'branch'"><option value="">Select branch (optional)</option><option v-for="b in refs.branches" :key="b.id" :value="b.id">{{ b.name }}</option></template>
                                <template v-else-if="field.type === 'cash'"><option value="">Select cash account</option><option v-for="a in refs.cash_bank_accounts.filter(a => a.account_type === 'cash')" :key="a.id" :value="a.id">{{ a.account_name }}</option></template>
                                <template v-else><option value="draft">Draft</option><option v-if="!pettyForm.id" value="issued">Issued</option></template>
                            </select>
                            <input v-else-if="field.type !== 'employee'" :id="`petty-${field.key}`" :required="field.key !== 'branch_id'" :aria-required="field.key !== 'branch_id'" v-model="pettyForm[field.key]" :type="field.type" :placeholder="field.placeholder" :inputmode="field.key === 'employee_id' ? 'numeric' : undefined" :step="field.type === 'number' ? '0.01' : undefined" :aria-invalid="!!pettyFieldError(field.key)" :aria-describedby="pettyFieldError(field.key) ? `petty-error-${field.key}` : undefined" />
                            <small v-if="field.help" class="recurring-helper">{{ field.help }}</small>
                            <p v-if="field.type !== 'employee' && pettyFieldError(field.key)" :id="`petty-error-${field.key}`" class="recurring-error">{{ pettyFieldError(field.key) }}</p>
                        </div>
                        <div class="recurring-buttons"><button type="submit" class="recurring-primary">{{ pettySaving ? 'Saving…' : 'Save Advance' }}</button></div>
                    </fieldset>
                    <p class="petty-workflow">{{ pettyForm.status === 'issued' ? 'Issued: Save Advance immediately posts the advance and reduces the selected cash account balance.' : 'Draft: Save Advance stores the record without posting accounting entries.' }}</p>
                    <p class="recurring-helper">Saved drafts can be edited, issued, or deleted using the actions below. Issued advances cannot use draft actions.</p>
                    <button v-if="pettySaved || pettyForm.id" type="button" :disabled="pettySaving" @click="resetPetty">{{ pettyForm.id && !pettySaved ? 'Cancel Edit' : 'New Advance' }}</button>
                </form>
                <h3 class="recurring-list-title">Existing Petty Cash Advances</h3>
                <div class="table-wrapper"><table><thead><tr><th>Advance No.</th><th>Date</th><th>Employee</th><th>Purpose</th><th>Cash Account</th><th>Amount</th><th>Status</th><th>Actions</th></tr></thead><tbody>
                    <tr v-for="p in pettyCash" :key="p.id"><td>{{ p.voucher_number }}</td><td>{{ formatDate(p.advance_date) }}</td><td>{{ p.employee ? `${p.employee.display_name} — ${p.employee.employee_code}` : 'Employee unavailable (legacy record)' }}</td><td>{{ p.purpose }}</td><td>{{ p.cash_account?.account_name || 'Account unavailable' }}</td><td>Rs. {{ money(p.amount) }}</td><td>{{ optionLabel(p.status) }}</td><td><div v-if="p.status === 'draft'" class="petty-row-actions"><button :disabled="pettySaving" @click="editPetty(p)">Edit</button><button :disabled="pettySaving" @click="pettyDraftAction(p, 'issue')">Issue</button><button :disabled="pettySaving" @click="pettyDraftAction(p, 'delete')">Delete</button></div><span v-else>—</span></td></tr>
                    <tr v-if="!pettyCash.length"><td colspan="8">No petty cash advances yet.</td></tr>
                </tbody></table></div>
                <div v-if="pettyPagination.last_page > 1" class="actions"><button :disabled="pettyPagination.current_page <= 1 || pettySaving" @click="loadPetty(pettyPagination.current_page - 1)">Previous</button><span>Page {{ pettyPagination.current_page }} of {{ pettyPagination.last_page }}</span><button :disabled="pettyPagination.current_page >= pettyPagination.last_page || pettySaving" @click="loadPetty(pettyPagination.current_page + 1)">Next</button></div>
            </section>

            <section v-if="activeTab === 'bank import'" class="panel"><div class="form-grid"><select v-model="importForm.bank_account_id"><option value="">Bank Account</option><option v-for="a in refs.cash_bank_accounts.filter(a => a.account_type === 'bank')" :key="a.id" :value="a.id">{{ a.account_name }} {{ a.masked_account_number || '' }}</option></select><input v-model="importForm.file_name" placeholder="Statement File Name" /><input v-model="importForm.statement_start_date" type="date" /><input v-model="importForm.statement_end_date" type="date" /></div><div v-for="(line, i) in importForm.lines" :key="i" class="line-grid import"><input v-model="line.transaction_date" type="date" /><input v-model="line.description" placeholder="Description" /><input v-model="line.reference_number" placeholder="Reference" /><input v-model.number="line.debit_amount" type="number" placeholder="Debit" /><input v-model.number="line.credit_amount" type="number" placeholder="Credit" /><button @click="importForm.lines.splice(i, 1)" :disabled="importForm.lines.length === 1">Remove</button></div><div class="actions"><button @click="addImportRow">Add Line</button><button :disabled="saving" @click="saveImport">Import Lines</button></div><div class="table-wrapper"><table><tbody><tr v-for="i in imports" :key="i.id"><td>{{ i.file_name }}</td><td>{{ i.bank_account?.account_name }}</td><td>{{ i.imported_rows }}/{{ i.total_rows }}</td><td>{{ i.duplicate_rows }} duplicates</td></tr></tbody></table></div></section>

            <section v-if="activeTab === 'reconciliation'" class="panel"><div class="form-grid"><select v-model="reconForm.bank_account_id" @change="loadAll"><option value="">Bank Account</option><option v-for="a in refs.cash_bank_accounts.filter(a => a.account_type === 'bank')" :key="a.id" :value="a.id">{{ a.account_name }}</option></select><input v-model="reconForm.statement_start_date" type="date" /><input v-model="reconForm.statement_end_date" type="date" /><input v-model.number="reconForm.statement_closing_balance" type="number" placeholder="Statement Closing" /></div><div v-for="(item, i) in reconForm.items" :key="i" class="line-grid recon"><select v-model="item.bank_statement_line_id"><option value="">Statement Line</option><option v-for="l in statementLines" :key="l.id" :value="l.id">{{ formatDate(l.transaction_date) }} {{ l.description }} Dr {{ l.debit_amount }} Cr {{ l.credit_amount }}</option></select><input v-model="item.journal_entry_id" placeholder="Journal Entry ID" /><input v-model.number="item.matched_amount" type="number" placeholder="Matched Amount" /><select v-model="item.match_type"><option>manual</option><option>auto</option><option>partial</option><option>one_to_many</option><option>many_to_one</option></select><button @click="reconForm.items.splice(i, 1)" :disabled="reconForm.items.length === 1">Remove</button></div><div class="actions"><button @click="addReconRow">Add Match</button><button :disabled="saving" @click="saveRecon">Save Reconciliation</button></div><div class="table-wrapper"><table><tbody><tr v-for="r in reconciliations" :key="r.id"><td>{{ r.bank_account?.account_name }}</td><td>{{ formatDate(r.statement_end_date) }}</td><td>Statement Rs. {{ money(r.statement_closing_balance) }}</td><td>Ledger Rs. {{ money(r.ledger_closing_balance) }}</td><td>Diff Rs. {{ money(r.difference_amount) }}</td></tr></tbody></table></div></section>

            <section v-if="activeTab === 'reports'" class="panel split"><div><h3>Expense Summary</h3><p>Total: Rs. {{ money(report.expense_summary.total) }}</p><p>Taxable: Rs. {{ money(report.expense_summary.taxable) }}</p><p>Input GST: Rs. {{ money(report.expense_summary.tax) }}</p><p>Unpaid: Rs. {{ money(report.expense_summary.unpaid) }}</p></div><div><h3>Other Income Summary</h3><p>Total: Rs. {{ money(report.income_summary.total) }}</p><p>Taxable: Rs. {{ money(report.income_summary.taxable) }}</p><p>Output GST: Rs. {{ money(report.income_summary.tax) }}</p></div></section>
        </div>
    </Layout>
</template>

<style scoped>
.petty-row-actions{display:flex;gap:8px}

.petty-required{color:#dc2626}

.petty-panel h3{font-size:16px;color:#142139}.petty-workflow{font-size:13px;color:#344159;padding:12px;background:#f8fafc;border:1px solid #dfe6ef;border-radius:8px}

.recurring-grid{padding:0;border:0;min-width:0;gap:16px}.recurring-field{display:flex;flex-direction:column;gap:7px;min-width:0}.recurring-field label{font-size:12px;font-weight:650;color:#344159}.recurring-field input,.recurring-field select{width:100%;min-width:0}.recurring-helper{font-size:12px;color:#69758a;line-height:1.5}.recurring-error{margin:0;font-size:12px;color:#b42318}.recurring-buttons{display:flex;align-items:flex-start;flex-wrap:wrap;gap:8px;padding-top:24px}.recurring-primary{background:#173b77;border-color:#173b77;color:#fff}.recurring-panel h3{font-size:16px;color:#142139}.recurring-list-title{border-top:1px solid #dfe6ef;padding-top:22px;margin-top:22px}@media(max-width:1000px){.recurring-buttons{padding-top:0}}

.income-form-grid{padding:0;border:0;min-width:0}.income-success{padding:10px 12px;border:1px solid #bde4cd;border-radius:8px;background:#edf9f2;color:#16704a;font-size:13px}

.income-field{display:flex;flex-direction:column;gap:7px;min-width:0;color:#344159;font-size:12px;font-weight:650}.income-field input,.income-field select{width:100%;min-width:0;font-weight:400}

.expense-page{padding:4px 0 28px}.page-heading,.toolbar,.actions,.tabs{display:flex;align-items:center;gap:12px}.page-heading{justify-content:space-between;margin-bottom:18px}.page-heading span{color:#2457d6;font-size:10px;font-weight:800;letter-spacing:1.2px}.page-heading h1{margin:0;color:#142139}.tabs{flex-wrap:wrap;margin-bottom:14px}.tabs button.active{background:#173b77;color:#fff;border-color:#173b77}.panel{margin-bottom:18px;padding:18px;background:#fff;border:1px solid #dfe6ef;border-radius:8px}.split{display:grid;grid-template-columns:1fr 1fr;gap:18px}.form-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:14px}.form-grid.two{grid-template-columns:repeat(2,minmax(0,1fr))}.line-grid{display:grid;grid-template-columns:1.1fr 1.6fr .7fr .8fr .8fr .7fr .9fr .7fr;gap:8px;align-items:center;margin-bottom:8px}.line-grid.import{grid-template-columns:1fr 2fr 1fr 1fr 1fr .8fr}.line-grid.recon{grid-template-columns:2fr 1fr 1fr 1fr .8fr}.line-grid.header{color:#69758a;font-size:10px;font-weight:800;text-transform:uppercase}.actions{justify-content:flex-end;flex-wrap:wrap;margin:12px 0}input,select,button{min-height:38px;padding:8px 10px;color:#344159;background:#fff;border:1px solid #d8e0eb;border-radius:8px;font-size:12px}button{font-weight:750;cursor:pointer}button:disabled{cursor:not-allowed;opacity:.55}.alert,.field-error{padding:10px 12px;margin-bottom:12px;border-radius:8px;background:#fff4f4;color:#b42318;border:1px solid #ffd5d5;font-size:12px}.table-wrapper{overflow-x:auto}table{width:100%;border-collapse:collapse;margin-top:12px}th,td{padding:11px 10px;border-bottom:1px solid #edf1f5;text-align:left;white-space:nowrap;font-size:12px}th{color:#69758a;background:#f8fafc;font-size:10px;text-transform:uppercase}@media(max-width:1000px){.form-grid,.form-grid.two,.split{grid-template-columns:1fr}.line-grid,.line-grid.import,.line-grid.recon{grid-template-columns:1fr}.page-heading,.toolbar{align-items:stretch;flex-direction:column}}
</style>
