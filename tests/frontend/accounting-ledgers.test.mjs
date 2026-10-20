import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ref, reactive, computed } from 'vue';
const script = readFileSync(new URL('../../resources/js/Pages/Accounting/Ledgers.vue', import.meta.url), 'utf8').split('<script setup>')[1].split('</script>')[0].replace(/^import .*;\n/gm, '');
function setup(api = {}) {
    return new Function('ref','reactive','computed','onMounted','onBeforeUnmount','defineProps','AccountingApi', `${script}\nreturn { tab, rows, filters, load, changeTab, emptyPrompt, ageing, balance };`)(ref,reactive,computed,()=>{},()=>{},()=>{},api);
}
test('party ledgers require selection; hidden filters are not sent', async () => {
    const calls = [];
    const s = setup({ ledger: async params => { calls.push(params); return []; } });
    s.changeTab('supplier');
    assert.equal(calls.length, 0);
    assert.equal(s.emptyPrompt.value, 'Select a supplier to view ledger');
    Object.assign(s.filters, { supplier_id: 2, customer_id: 4, account_id: 9, branch_id: 1 });
    await s.load();
    assert.deepEqual(calls[0], { supplier_id: 2, branch_id: 1, date_from: '', date_to: '' });
    s.changeTab('customer');
    assert.equal(calls.length, 1);
    assert.equal(s.rows.value.length, 0);
});
test('late account responses cannot populate supplier ledger', async () => {
    let resolve;
    const s = setup({ ledger: () => new Promise(r => { resolve = r; }) });
    const request = s.load();
    s.changeTab('supplier');
    resolve([{ voucher_type: 'sales' }]);
    await request;
    assert.deepEqual(s.rows.value, []);
});
test('outstanding passes branch and omits unsupported date filters', async () => {
    let params;
    const s = setup({ supplierOutstanding: async p => { params = p; return []; } });
    s.tab.value = 'supplier_outstanding';
    Object.assign(s.filters, { supplier_id: 2, branch_id: 3, date_from: '2026-01-01' });
    await s.load();
    assert.deepEqual(params, { supplier_id: 2, branch_id: 3 });
});
test('ageing and debit/credit labels are readable', () => {
    const s = setup();
    assert.equal(s.ageing(0.9059), '0 Days');
    assert.equal(s.ageing(1), '1 Day');
    assert.equal(s.ageing(12), '12 Days');
    assert.equal(s.ageing(-3), '0 Days');
    assert.equal(s.balance(-1050), '1,050.00 Cr');
    assert.equal(s.balance(1050), '1,050.00 Dr');
    assert.equal(s.balance(0), '0.00');
});

test('both outstanding tabs hide settled bills and retain partial balances; ledger keeps history', async () => {
    const records = [{ reference_number: 'PUR-2026-00002', outstanding_amount: 0 }, { reference_number: 'PARTIAL', outstanding_amount: '450.00' }];
    const s = setup({ supplierOutstanding: async () => records, customerOutstanding: async () => records, ledger: async () => records });
    for (const tab of ['supplier_outstanding', 'customer_outstanding']) {
        s.tab.value = tab;
        await s.load();
        assert.deepEqual(s.rows.value.map(r => r.reference_number), ['PARTIAL']);
    }
    s.tab.value = 'supplier'; s.filters.supplier_id = 2;
    await s.load();
    assert.equal(s.rows.value.length, 2);
});

test('cash book requests selected account without supplier filter and retains payment credits', async () => {
    let requested;
    const result = [{ voucher_type: 'sales', debit_amount: 100 }, { voucher_type: 'payment', reference_number: 'PAY-2026-00001', credit_amount: 600 }, { voucher_type: 'payment', reference_number: 'PAY-2026-00002', credit_amount: 450 }];
    const s = setup({ cashBankBook: async params => { requested = params; return result; } });
    s.tab.value = 'cashbank';
    Object.assign(s.filters, { account_id: 1, supplier_id: 2 });
    await s.load();
    assert.deepEqual(requested, { account_id: 1, branch_id: '', date_from: '', date_to: '' });
    assert.deepEqual(s.rows.value.filter(r => r.voucher_type === 'payment').map(r => r.credit_amount), [600, 450]);
});
