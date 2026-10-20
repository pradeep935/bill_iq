import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ref, reactive, computed } from 'vue';
import { flattenErrors } from '../../resources/js/Support/errorMessages.js';
const script = readFileSync(new URL('../../resources/js/Pages/Accounting/Expenses.vue', import.meta.url), 'utf8').split('<script setup>')[1].split('</script>')[0].replace(/^import .*;\n/gm, '');
const setup = api => new Function('ref','reactive','computed','onMounted','watch','defineProps','AccountingApi','flattenErrors', `${script}\nreturn { incomeForm, incomes, saving, incomePosted, incomeMessage, incomeErrorMessages, saveIncome, resetIncome, postIncome };`)(ref,reactive,computed,()=>{},()=>{},()=>{},api,flattenErrors);
const posted = { id: 12, voucher_number: 'OIN-2026-00001', status: 'posted', total_amount: 150 };
test('successful submit immediately displays voucher and success without unrelated reloads; repeated clicks cannot create again', async () => {
 let resolve, calls = 0;
 const s = setup({ saveOtherIncome: () => { calls++; return new Promise(r => resolve = r); } });
 Object.assign(s.incomeForm, { taxable_amount: 150, total_amount: 150 });
 const pending = s.saveIncome('posted'); await s.saveIncome('posted');
 assert.equal(calls, 1); assert.equal(s.saving.value, true);
 resolve({ income: posted }); await pending;
 assert.equal(s.incomes.value[0].id, 12); assert.match(s.incomeMessage.value, /approved and posted successfully/);
 assert.equal(s.incomePosted.value, true); await s.saveIncome('posted'); assert.equal(calls, 1);
 s.resetIncome(); assert.equal(s.incomeForm.id, null); assert.equal(s.incomeForm.total_amount, 0);
});
test('draft save retains ID and subsequent approval updates the same voucher', async () => {
 const ids = [];
 const s = setup({ saveOtherIncome: async (payload,id) => { ids.push(id); return { income: { ...posted, status: payload.status } }; } });
 await s.saveIncome('draft'); await s.saveIncome('posted');
 assert.deepEqual(ids, [null,12]); assert.equal(s.incomes.value.length, 1);
});
test('field validation, server, session and network errors all become visible and allow correction', async () => {
 for (const [error,message] of [
  [{response:{status:422,data:{errors:{total_amount:['Total amount must be greater than zero.'],voucher_date:['Accounting period is locked.']}}}},'Total amount must be greater than zero.'],
  [{response:{status:500,data:{message:'Server unavailable'}}},'Server unavailable'],
  [{response:{status:419,data:{message:'CSRF token mismatch.'}}},'Your session expired. Refresh the page and try again.'],
  [new Error('Network Error'),'Network Error'],
 ]) {
  const s = setup({saveOtherIncome: async () => { throw error; }});
  await s.saveIncome('posted'); assert.equal(s.incomeErrorMessages.value[0], message);
  assert.equal(s.saving.value, false); assert.equal(s.incomeForm.id, null); assert.equal(s.incomeForm.status, 'draft');
 }
});
test('existing row posting updates status and catches posting validation errors', async () => {
 const s = setup({ postOtherIncome: async () => ({income:posted}) });
 s.incomes.value = [{...posted,status:'draft'}]; await s.postIncome(s.incomes.value[0]);
 assert.equal(s.incomes.value.length, 1); assert.equal(s.incomes.value[0].status,'posted');
 const failed = setup({postOtherIncome:async()=>{throw {response:{data:{errors:{voucher_date:['Accounting period is locked.']}}}};}});
 await failed.postIncome({id:12}); assert.deepEqual(failed.incomeErrorMessages.value,['Accounting period is locked.']);
});
