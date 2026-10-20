import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ref, reactive, computed } from 'vue';
import { flattenErrors } from '../../resources/js/Support/errorMessages.js';
import { formatDate } from '../../resources/js/Support/dateFormat.js';
const script = readFileSync(new URL('../../resources/js/Pages/Accounting/Expenses.vue', import.meta.url), 'utf8').split('<script setup>')[1].split('</script>')[0].replace(/^import .*;\n/gm, '');
const setup = api => new Function('ref','reactive','computed','onMounted','watch','defineProps','AccountingApi','flattenErrors','formatDate', `${script}\nreturn { recurringForm, recurring, recurringMessage, recurringErrors, recurringSaving, recurringFieldError, editRecurring, saveRecurring, resetRecurring };`)(ref,reactive,computed,()=>{},()=>{},()=>{},api,flattenErrors,formatDate);
test('edit preserves hidden schedule settings and calendar dates; status changes use existing update API', async () => {
 let sent;
 const row = {id:8, template_name:'Monthly Rent', start_date:'2026-09-12T18:30:00.000000Z', next_run_date:'2026-10-12T18:30:00.000000Z', end_date:null, interval_value:3, auto_post:true, approval_required:false, paid_from_account_id:7, gst_rate:'18.00', status:'active'};
 const s=setup({saveRecurringExpense:async(payload,id)=>{sent={payload,id};return {template:{...payload,id}};}});
 s.editRecurring(row); assert.equal(s.recurringForm.start_date,'2026-09-13'); assert.equal(s.recurringForm.next_run_date,'2026-10-13');
 s.recurringForm.status='paused'; await s.saveRecurring();
 assert.equal(sent.id,8); assert.equal(sent.payload.interval_value,3); assert.equal(sent.payload.auto_post,true); assert.equal(sent.payload.approval_required,false); assert.equal(sent.payload.paid_from_account_id,7); assert.equal(sent.payload.status,'paused');
 assert.equal(s.recurring.value[0].status,'paused'); assert.match(s.recurringMessage.value,/saved successfully/);
});
test('save displays field and server errors and releases busy state',async()=>{
 const s=setup({saveRecurringExpense:async()=>{throw {response:{data:{errors:{amount:['Enter a positive amount.'],start_date:['Start date is required.']}}}};}});
 await s.saveRecurring(); assert.equal(s.recurringFieldError('amount'),'Enter a positive amount.'); assert.equal(s.recurringErrors.value.start_date[0],'Start date is required.'); assert.equal(s.recurringSaving.value,false);
});
test('save retains ID and replaces row without full module reload or double submit',async()=>{
 let resolve,calls=0;
 const s=setup({saveRecurringExpense:()=>{calls++;return new Promise(r=>resolve=r);}});
 const pending=s.saveRecurring(); await s.saveRecurring(); assert.equal(calls,1);
 resolve({template:{id:3,template_name:'Test'}}); await pending;
 assert.equal(s.recurringForm.id,3); assert.equal(s.recurring.value.length,1);
 s.resetRecurring(); assert.equal(s.recurringForm.id,null);
});
