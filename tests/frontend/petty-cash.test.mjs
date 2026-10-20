import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ref, reactive, computed, watch } from 'vue';
import { flattenErrors } from '../../resources/js/Support/errorMessages.js';
const script=readFileSync(new URL('../../resources/js/Pages/Accounting/Expenses.vue',import.meta.url),'utf8').split('<script setup>')[1].split('</script>')[0].replace(/^import .*;\n/gm,'');
const setup=api=>new Function('ref','reactive','computed','onMounted','watch','defineProps','AccountingApi','flattenErrors',`${script}\nreturn {pettyForm,pettyCash,pettySaving,pettySaved,pettyMessage,pettyErrors,pettyFieldError,savePetty,resetPetty,pettyDraftAction,editPetty,pettyEmployeeOptions,refs};`)(ref,reactive,computed,()=>{},watch,()=>{},api,flattenErrors);
test('save shows returned row and accurate draft/issued feedback, blocks repeat creation until New Advance',async()=>{
 for(const status of ['draft','issued']){
  let calls=0;const s=setup({savePettyCash:async payload=>{calls++;return {advance:{...payload,id:4,voucher_number:'PC-2026-00001'}};}});
  s.pettyForm.status=status; await s.savePetty();
  assert.equal(s.pettyCash.value[0].status,status);assert.equal(s.pettySaved.value,true);
  assert.match(s.pettyMessage.value,status==='draft'?/No accounting entries/:/issued and posted/);
  await s.savePetty();assert.equal(calls,1);s.resetPetty();assert.equal(s.pettySaved.value,false);assert.equal(s.pettyForm.status,'draft');
 }
});
test('validation, posting and network failures stay visible and allow correction',async()=>{
 for(const error of [{response:{data:{errors:{employee_id:['Enter an employee ID.'],entries:['Journal must balance.']}}}},new Error('Network unavailable')]){
  const s=setup({savePettyCash:async()=>{throw error;}});await s.savePetty();
  assert.equal(s.pettySaved.value,false);assert.equal(s.pettySaving.value,false);assert.ok(flattenErrors(s.pettyErrors.value).length);
  if(error.response)assert.equal(s.pettyFieldError('employee_id'),'Enter an employee ID.');
 }
});
test('in-flight save is guarded',async()=>{let resolve,calls=0;const s=setup({savePettyCash:()=>{calls++;return new Promise(r=>resolve=r);}});const pending=s.savePetty();await s.savePetty();assert.equal(calls,1);resolve({advance:{id:5,status:'draft',voucher_number:'PC-5'}});await pending;});

test('employee search options are branch-filtered and clear invalid selection after branch or reference change', async()=>{
 const s=setup({}); s.refs.value.petty_cash_employees=[{id:1,branch_id:10,display_name:'Rahul Sharma',employee_code:'EMP-001'},{id:2,branch_id:20,display_name:'Asha',employee_code:'EMP-002'}];
 assert.equal(s.pettyEmployeeOptions.value[0].label,'Rahul Sharma — EMP-001');
 s.pettyForm.employee_id=1;s.pettyForm.branch_id=20;await Promise.resolve();
 assert.equal(s.pettyEmployeeOptions.value.length,1);assert.equal(s.pettyForm.employee_id,'');
 s.pettyForm.employee_id=2;s.refs.value.petty_cash_employees=[];await Promise.resolve();assert.equal(s.pettyForm.employee_id,'');
});

test('unexpected server failures do not expose exception details',async()=>{
 const s=setup({savePettyCash:async()=>{throw {response:{status:500,data:{message:'SQLSTATE secret stack trace',errors:{internal:['private details']}}}};}});
 await s.savePetty();const message=flattenErrors(s.pettyErrors.value).join(' ');
 assert.match(message,/Refresh the advance list/);assert.doesNotMatch(message,/SQLSTATE|secret|private/);
});

test('draft actions require confirmation, update rows, reject issued UI actions and expose errors',async()=>{
 let calls=0;globalThis.window={confirm:()=>false};
 const s=setup({issuePettyCash:async id=>{calls++;return {advance:{id,status:'issued'}};},deletePettyCash:async()=>{calls++;}});
 const row={id:3,status:'draft',amount:100,voucher_number:'PC-3'};s.pettyCash.value=[row];
 await s.pettyDraftAction(row,'issue');assert.equal(calls,0);
 window.confirm=()=>true;await s.pettyDraftAction(row,'issue');assert.equal(calls,1);assert.equal(s.pettyCash.value[0].status,'issued');
 await s.pettyDraftAction(s.pettyCash.value[0],'delete');assert.equal(calls,1);
 s.pettyCash.value=[row];await s.pettyDraftAction(row,'delete');assert.equal(calls,2);assert.equal(s.pettyCash.value.length,0);
 const failed=setup({issuePettyCash:async()=>{throw {response:{data:{errors:{status:['Already issued.']}}}};}});
 await failed.pettyDraftAction(row,'issue');assert.equal(failed.pettyErrors.value.status[0],'Already issued.');
 const expired=setup({deletePettyCash:async()=>{throw {response:{status:419,data:{message:'CSRF token mismatch.'}}};}});expired.pettyCash.value=[row];
 await expired.pettyDraftAction(row,'delete');assert.match(expired.pettyErrors.value.form[0],/session expired/i);assert.equal(expired.pettyCash.value.length,1);delete globalThis.window;
});
