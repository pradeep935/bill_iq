import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ref, reactive, computed } from 'vue';
const script = readFileSync(new URL('../../resources/js/Pages/Accounting/Vouchers.vue', import.meta.url), 'utf8').split('<script setup>')[1].split('</script>')[0].replace(/^import .*;\n/gm, '');
const setup = api => new Function('ref','reactive','computed','watch','onMounted','onBeforeUnmount','defineProps','AccountingApi', `${script}\nreturn { payment, openBills, loadBills, invalidPayment, remainingCents, savePayment, allocatedBranches, differentBranch, receipt, openInvoices, loadInvoices, invalidReceipt, receiptBranches, canReverse };`)(ref,reactive,computed,()=>{},()=>{},()=>{},()=>{},api);
test('partial and multiple bill allocations must equal payment and stay within bill balances', () => {
 const s = setup({});
 Object.assign(s.payment, { supplier_id: 2, cash_bank_account_id: 1, amount: 600 });
 s.openBills.value = [{ outstanding_amount: 1050, allocate: 600 }];
 assert.equal(s.invalidPayment.value, false);
 s.payment.amount = 700; assert.equal(s.invalidPayment.value, true);
 s.openBills.value.push({ outstanding_amount: 200, allocate: 100 });
 assert.equal(s.invalidPayment.value, false);
 s.openBills.value[1].allocate = 300; assert.equal(s.invalidPayment.value, true);
});
test('open bills filter settled purchases and stale supplier responses', async () => {
 const resolves = [];
 const s = setup({ supplierOutstanding: () => new Promise(r => resolves.push(r)) });
 s.payment.supplier_id = 2; const old = s.loadBills();
 s.payment.supplier_id = 3; const current = s.loadBills();
 resolves[1]([{ purchase_id: 3, outstanding_amount: 100 }, { purchase_id: 4, outstanding_amount: 0 }]); await current;
 resolves[0]([{ purchase_id: 2, outstanding_amount: 1050 }]); await old;
 assert.equal(s.openBills.value.length, 1); assert.equal(s.openBills.value[0].purchase_id, 3);
});

test('allocations resolve one branch and block mixed branches', () => {
 const s = setup({});
 Object.assign(s.payment, { supplier_id: 2, cash_bank_account_id: 1, amount: 450 });
 s.openBills.value = [{ branch_id: 1, outstanding_amount: 450, allocate: 450 }, { branch_id: 2, outstanding_amount: 100, allocate: 0 }];
 assert.deepEqual(s.allocatedBranches.value, [1]);
 assert.equal(s.invalidPayment.value, false);
 assert.equal(s.differentBranch(s.openBills.value[1]), true);
 s.openBills.value[1].allocate = 10;
 assert.equal(s.invalidPayment.value, true);
});

test('receipt allocations support partial amounts, reject mixed branches and exclude settled invoices', async () => {
 const s = setup({ customerOutstanding: async () => [{ invoice_id: 25, branch_id: 1, outstanding_amount: 360 }, { invoice_id: 26, branch_id: 1, outstanding_amount: 0 }] });
 Object.assign(s.receipt, { customer_id: 2, cash_bank_account_id: 1, amount: 200 });
 await s.loadInvoices();
 assert.equal(s.openInvoices.value.length, 1);
 s.openInvoices.value[0].allocate = 200;
 assert.equal(s.invalidReceipt.value, false);
 assert.deepEqual(s.receiptBranches.value, [1]);
 s.openInvoices.value.push({ invoice_id: 27, branch_id: 2, outstanding_amount: 100, allocate: 10 });
 s.receipt.amount = 210;
 assert.equal(s.invalidReceipt.value, true);
});

test('reverse action is limited to unreversed original journals', () => {
 const s = setup({});
 for (const voucher_type of ['receipt', 'payment', 'sales', 'journal', 'contra']) {
  assert.equal(s.canReverse({ voucher_type, status: 'approved' }), true);
 }
 for (const row of [
  { voucher_type: 'receipt', status: 'reversed' },
  { voucher_type: 'reversal', status: 'approved' },
  { voucher_type: 'receipt', status: 'approved', reversed_by_id: 66 },
  { voucher_type: 'receipt', status: 'approved', reversal_of_id: 65 },
  { voucher_type: 'receipt', status: 'draft' },
 ]) assert.equal(s.canReverse(row), false);
});
