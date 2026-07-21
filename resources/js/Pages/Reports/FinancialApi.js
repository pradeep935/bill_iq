import axios from 'axios';

const FinancialApi = {
    async references() { return (await axios.get('/app/reports/financial/references')).data; },
    async dashboard(params = {}) { return (await axios.get('/app/reports/financial/dashboard', { params })).data; },
    async dayBook(params = {}) { return (await axios.get('/app/reports/financial/day-book', { params })).data; },
    async journalRegister(params = {}) { return (await axios.get('/app/reports/financial/journal-register', { params })).data; },
    async ledger(params = {}) { return (await axios.get('/app/reports/financial/ledger', { params })).data; },
    async trialBalance(params = {}) { return (await axios.get('/app/reports/financial/trial-balance', { params })).data; },
    async profitAndLoss(params = {}) { return (await axios.get('/app/reports/financial/profit-and-loss', { params })).data; },
    async balanceSheet(params = {}) { return (await axios.get('/app/reports/financial/balance-sheet', { params })).data; },
    async cashFlow(params = {}) { return (await axios.get('/app/reports/financial/cash-flow', { params })).data; },
    async receivables(params = {}) { return (await axios.get('/app/reports/financial/receivables', { params })).data; },
    async payables(params = {}) { return (await axios.get('/app/reports/financial/payables', { params })).data; },
    async comparative(params = {}) { return (await axios.get('/app/reports/financial/comparative', { params })).data; },
    async branchFinancials(params = {}) { return (await axios.get('/app/reports/financial/branch-financials', { params })).data; },
    async schedule(params = {}) { return (await axios.get('/app/reports/financial/schedule', { params })).data; },
    async ratios(params = {}) { return (await axios.get('/app/reports/financial/ratios', { params })).data; },
    async exceptions(params = {}) { return (await axios.get('/app/reports/financial/exceptions', { params })).data; },
    async closingChecklist(payload) { return (await axios.post('/app/reports/financial/closing/checklist', payload)).data; },
    async closeYear(payload) { return (await axios.post('/app/reports/financial/closing', payload)).data; },
    async snapshot(payload) { return (await axios.post('/app/reports/financial/snapshots', payload)).data; },
};

export default FinancialApi;
