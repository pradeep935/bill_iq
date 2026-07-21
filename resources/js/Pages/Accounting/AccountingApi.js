import axios from 'axios';

const AccountingApi = {
    async references() {
        const response = await axios.get('/app/accounting/references');
        return response.data;
    },
    async accounts(params = {}) {
        const response = await axios.get('/app/accounting/accounts/list', { params });
        return response.data;
    },
    async saveAccount(payload, id = null) {
        const response = id ? await axios.put(`/app/accounting/accounts/${id}`, payload) : await axios.post('/app/accounting/accounts', payload);
        return response.data;
    },
    async saveSettings(payload) {
        const response = await axios.post('/app/accounting/settings', payload);
        return response.data;
    },
    async journals(params = {}) {
        const response = await axios.get('/app/accounting/journals/list', { params });
        return response.data;
    },
    async saveJournal(payload) {
        const response = await axios.post('/app/accounting/journals', payload);
        return response.data;
    },
    async approveJournal(id) {
        const response = await axios.post(`/app/accounting/journals/${id}/approve`);
        return response.data;
    },
    async reverseJournal(id, remarks) {
        const response = await axios.post(`/app/accounting/journals/${id}/reverse`, { remarks });
        return response.data;
    },
    async receipts(params = {}) {
        const response = await axios.get('/app/accounting/receipts/list', { params });
        return response.data;
    },
    async saveReceipt(payload) {
        const response = await axios.post('/app/accounting/receipts', payload);
        return response.data;
    },
    async payments(params = {}) {
        const response = await axios.get('/app/accounting/payments/list', { params });
        return response.data;
    },
    async savePayment(payload) {
        const response = await axios.post('/app/accounting/payments', payload);
        return response.data;
    },
    async saveContra(payload) {
        const response = await axios.post('/app/accounting/contra', payload);
        return response.data;
    },
    async ledger(params = {}) {
        const response = await axios.get('/app/accounting/ledger', { params });
        return response.data;
    },
    async cashBankBook(params = {}) {
        const response = await axios.get('/app/accounting/cash-bank-book', { params });
        return response.data;
    },
    async customerOutstanding(params = {}) {
        const response = await axios.get('/app/accounting/customer-outstanding', { params });
        return response.data;
    },
    async supplierOutstanding(params = {}) {
        const response = await axios.get('/app/accounting/supplier-outstanding', { params });
        return response.data;
    },
    async expenseReferences() {
        const response = await axios.get('/app/accounting/expenses/references');
        return response.data;
    },
    async expenseCategories(params = {}) {
        const response = await axios.get('/app/accounting/expense-categories/list', { params });
        return response.data;
    },
    async saveExpenseCategory(payload, id = null) {
        const response = id ? await axios.put(`/app/accounting/expense-categories/${id}`, payload) : await axios.post('/app/accounting/expense-categories', payload);
        return response.data;
    },
    async incomeCategories(params = {}) {
        const response = await axios.get('/app/accounting/income-categories/list', { params });
        return response.data;
    },
    async saveIncomeCategory(payload, id = null) {
        const response = id ? await axios.put(`/app/accounting/income-categories/${id}`, payload) : await axios.post('/app/accounting/income-categories', payload);
        return response.data;
    },
    async expenses(params = {}) {
        const response = await axios.get('/app/accounting/expense-vouchers/list', { params });
        return response.data;
    },
    async saveExpense(payload, id = null) {
        const response = id ? await axios.put(`/app/accounting/expense-vouchers/${id}`, payload) : await axios.post('/app/accounting/expense-vouchers', payload);
        return response.data;
    },
    async postExpense(id) {
        const response = await axios.post(`/app/accounting/expense-vouchers/${id}/post`);
        return response.data;
    },
    async reverseExpense(id, remarks) {
        const response = await axios.post(`/app/accounting/expense-vouchers/${id}/reverse`, { remarks });
        return response.data;
    },
    async otherIncome(params = {}) {
        const response = await axios.get('/app/accounting/other-income/list', { params });
        return response.data;
    },
    async saveOtherIncome(payload, id = null) {
        const response = id ? await axios.put(`/app/accounting/other-income/${id}`, payload) : await axios.post('/app/accounting/other-income', payload);
        return response.data;
    },
    async postOtherIncome(id) {
        const response = await axios.post(`/app/accounting/other-income/${id}/post`);
        return response.data;
    },
    async reverseOtherIncome(id, remarks) {
        const response = await axios.post(`/app/accounting/other-income/${id}/reverse`, { remarks });
        return response.data;
    },
    async recurringExpenses(params = {}) {
        const response = await axios.get('/app/accounting/recurring-expenses/list', { params });
        return response.data;
    },
    async saveRecurringExpense(payload, id = null) {
        const response = id ? await axios.put(`/app/accounting/recurring-expenses/${id}`, payload) : await axios.post('/app/accounting/recurring-expenses', payload);
        return response.data;
    },
    async pettyCash(params = {}) {
        const response = await axios.get('/app/accounting/petty-cash/list', { params });
        return response.data;
    },
    async savePettyCash(payload) {
        const response = await axios.post('/app/accounting/petty-cash', payload);
        return response.data;
    },
    async bankStatementImports(params = {}) {
        const response = await axios.get('/app/accounting/bank-statement-imports/list', { params });
        return response.data;
    },
    async saveBankStatementImport(payload) {
        const response = await axios.post('/app/accounting/bank-statement-imports', payload);
        return response.data;
    },
    async bankStatementLines(params = {}) {
        const response = await axios.get('/app/accounting/bank-statement-lines', { params });
        return response.data;
    },
    async bankLedgerEntries(params = {}) {
        const response = await axios.get('/app/accounting/bank-ledger-entries', { params });
        return response.data;
    },
    async bankReconciliations(params = {}) {
        const response = await axios.get('/app/accounting/bank-reconciliations/list', { params });
        return response.data;
    },
    async saveBankReconciliation(payload) {
        const response = await axios.post('/app/accounting/bank-reconciliations', payload);
        return response.data;
    },
    async expenseReports(params = {}) {
        const response = await axios.get('/app/accounting/expense-reports', { params });
        return response.data;
    },
};

export default AccountingApi;
