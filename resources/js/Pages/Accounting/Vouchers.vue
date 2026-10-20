<script setup>
import { formatDate } from '../../Support/dateFormat.js';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import Layout from '../Layout.vue';
import AccountingApi from './AccountingApi';

defineProps({ page: { type: String, default: 'vouchers' }, title: { type: String, default: 'Accounting Vouchers' } });
const today = new Date().toISOString().slice(0, 10);
const refs = ref({ accounts: [], cash_bank_accounts: [], customers: [], suppliers: [], branches: [] });
const journals = ref([]); const receipts = ref([]); const payments = ref([]); const tab = ref('receipt'); const saving = ref(false);
const receipt = reactive({ branch_id: '', customer_id: '', receipt_date: today, receipt_mode: 'cash', cash_bank_account_id: '', amount: 0, discount_allowed: 0, write_off_amount: 0, reference_number: '', remarks: '', status: 'approved', allocations: [] });
const payment = reactive({ branch_id: '', supplier_id: '', payment_date: today, payment_mode: 'cash', cash_bank_account_id: '', amount: 0, discount_received: 0, write_off_amount: 0, reference_number: '', remarks: '', status: 'approved', allocations: [] });
const contra = reactive({ voucher_date: today, from_account_id: '', to_account_id: '', amount: 0, reference_number: '', remarks: '' });
const journal = reactive({ voucher_type: 'journal', voucher_date: today, reference_number: '', narration: '', status: 'approved', entries: [{ account_id: '', debit_amount: 0, credit_amount: 0, narration: '' }, { account_id: '', debit_amount: 0, credit_amount: 0, narration: '' }] });
const totals = computed(() => ({ debit: journal.entries.reduce((s, e) => s + Number(e.debit_amount || 0), 0), credit: journal.entries.reduce((s, e) => s + Number(e.credit_amount || 0), 0) }));
const load = async () => { refs.value = await AccountingApi.references(); receipt.cash_bank_account_id = receipt.cash_bank_account_id || refs.value.cash_bank_accounts?.[0]?.id || ''; payment.cash_bank_account_id = payment.cash_bank_account_id || refs.value.cash_bank_accounts?.[0]?.id || ''; const jr = await AccountingApi.journals(); journals.value = jr.journals || []; const rr = await AccountingApi.receipts(); receipts.value = rr.receipts || []; const pr = await AccountingApi.payments(); payments.value = pr.payments || []; };
const openBills = ref([]);
const billsLoading = ref(false);
const paymentError = ref('');
const paymentMessage = ref('');
let billVersion = 0;
const loadBills = async () => {
    const version = ++billVersion;
    openBills.value = [];
    paymentError.value = '';
    billsLoading.value = false;
    if (!payment.supplier_id) return;
    billsLoading.value = true;
    try {
        const result = await AccountingApi.supplierOutstanding({ supplier_id: payment.supplier_id });
        if (version === billVersion) openBills.value = result.filter(b => Number(b.outstanding_amount) > 0).map(b => ({ ...b, allocate: 0 }));
    } catch (error) { if (version === billVersion) paymentError.value = 'Could not load open bills. Select the supplier again to retry.'; }
    finally { if (version === billVersion) billsLoading.value = false; }
};
watch(() => payment.supplier_id, () => { paymentMessage.value = ''; loadBills(); });
onBeforeUnmount(() => { billVersion++; });
const allocatedBranches = computed(() => [...new Set(openBills.value.filter(b => Number(b.allocate) > 0).map(b => b.branch_id ? Number(b.branch_id) : null))]);
const branchName = (id) => id ? refs.value.branches.find(b => Number(b.id) === Number(id))?.name || `Branch ${id}` : 'No branch (source bill)';
const resolvedBranchLabel = computed(() => allocatedBranches.value.length > 1 ? 'Multiple branches — use separate payments' : allocatedBranches.value.length ? branchName(allocatedBranches.value[0]) : 'Select a bill to allocate');
watch(allocatedBranches, (branches) => { payment.branch_id = branches.length === 1 ? branches[0] || '' : ''; }, { flush: 'sync' });
const differentBranch = (bill) => allocatedBranches.value.length === 1 && (bill.branch_id ? Number(bill.branch_id) : null) !== allocatedBranches.value[0];
const allocatedCents = computed(() => openBills.value.reduce((sum, b) => sum + Math.round(Number(b.allocate || 0) * 100), 0));
const remainingCents = computed(() => Math.round(Number(payment.amount || 0) * 100) - allocatedCents.value);
const invalidPayment = computed(() => allocatedBranches.value.length > 1 || billsLoading.value || !payment.supplier_id || !payment.payment_date || !payment.cash_bank_account_id || Number(payment.amount) <= 0 || remainingCents.value !== 0 || !allocatedCents.value || openBills.value.some(b => !Number.isFinite(Number(b.allocate)) || Number(b.allocate) < 0 || Number(b.allocate) > Number(b.outstanding_amount)));
const savePayment = async () => {
    if (saving.value || invalidPayment.value) return;
    saving.value = true; paymentError.value = ''; paymentMessage.value = '';
    try {
        const allocations = openBills.value.filter(b => Number(b.allocate) > 0).map(b => ({ reference_type: 'App\\Models\\PurchaseVoucher', reference_id: b.purchase_id, allocated_amount: Number(b.allocate) }));
        const response = await AccountingApi.savePayment({ ...payment, branch_id: allocatedBranches.value[0] || null, allocations });
        payment.amount = 0;
        paymentMessage.value = response.message || 'Payment saved.';
        await loadBills();
        await load();
    } catch (error) { paymentError.value = Object.values(error.response?.data?.errors || {}).flat().join(' ') || error.response?.data?.message || 'Payment could not be saved.'; }
    finally { saving.value = false; }
};
const openInvoices = ref([]);
const invoicesLoading = ref(false);
const receiptError = ref('');
const receiptMessage = ref('');
let invoiceVersion = 0;
const loadInvoices = async () => {
    const version = ++invoiceVersion;
    openInvoices.value = [];
    receiptError.value = '';
    invoicesLoading.value = false;
    if (!receipt.customer_id) return;
    invoicesLoading.value = true;
    try {
        const result = await AccountingApi.customerOutstanding({ customer_id: receipt.customer_id });
        if (version === invoiceVersion) openInvoices.value = result.filter(b => Number(b.outstanding_amount) > 0).map(b => ({ ...b, allocate: 0 }));
    } catch (error) { if (version === invoiceVersion) receiptError.value = 'Could not load open invoices. Select the customer again to retry.'; }
    finally { if (version === invoiceVersion) invoicesLoading.value = false; }
};
watch(() => receipt.customer_id, () => { receiptMessage.value = ''; loadInvoices(); });
onBeforeUnmount(() => { invoiceVersion++; });
const receiptBranches = computed(() => [...new Set(openInvoices.value.filter(b => Number(b.allocate) > 0).map(b => b.branch_id ? Number(b.branch_id) : null))]);
const receiptBranchName = (id) => id ? refs.value.branches.find(b => Number(b.id) === Number(id))?.name || `Branch ${id}` : 'No branch (source invoice)';
const receiptBranchLabel = computed(() => receiptBranches.value.length > 1 ? 'Multiple branches — use separate receipts' : receiptBranches.value.length ? receiptBranchName(receiptBranches.value[0]) : 'Select an invoice to allocate');
watch(receiptBranches, (branches) => { receipt.branch_id = branches.length === 1 ? branches[0] || '' : ''; }, { flush: 'sync' });
const differentReceiptBranch = (invoice) => receiptBranches.value.length === 1 && (invoice.branch_id ? Number(invoice.branch_id) : null) !== receiptBranches.value[0];
const receiptAllocatedCents = computed(() => openInvoices.value.reduce((sum, b) => sum + Math.round(Number(b.allocate || 0) * 100), 0));
const receiptRemainingCents = computed(() => Math.round(Number(receipt.amount || 0) * 100) - receiptAllocatedCents.value);
const invalidReceipt = computed(() => receiptBranches.value.length > 1 || invoicesLoading.value || !receipt.customer_id || !receipt.receipt_date || !receipt.cash_bank_account_id || Number(receipt.amount) <= 0 || receiptRemainingCents.value !== 0 || !receiptAllocatedCents.value || openInvoices.value.some(b => !Number.isFinite(Number(b.allocate)) || Number(b.allocate) < 0 || Number(b.allocate) > Number(b.outstanding_amount)));
const saveReceipt = async () => {
    if (saving.value || invalidReceipt.value) return;
    saving.value = true; receiptError.value = ''; receiptMessage.value = '';
    try {
        const allocations = openInvoices.value.filter(b => Number(b.allocate) > 0).map(b => ({ reference_type: 'App\\Models\\SalesVoucher', reference_id: b.invoice_id, allocated_amount: Number(b.allocate) }));
        const response = await AccountingApi.saveReceipt({ ...receipt, branch_id: receiptBranches.value[0] || null, allocations });
        receipt.amount = 0;
        receiptMessage.value = response.message || 'Receipt saved.';
        await loadInvoices();
        await load();
    } catch (error) { receiptError.value = Object.values(error.response?.data?.errors || {}).flat().join(' ') || error.response?.data?.message || 'Receipt could not be saved.'; }
    finally { saving.value = false; }
};
const saveContra = async () => { saving.value = true; try { await AccountingApi.saveContra({ ...contra }); await load(); } finally { saving.value = false; } };
const saveJournal = async () => { saving.value = true; try { await AccountingApi.saveJournal({ ...journal }); await load(); } finally { saving.value = false; } };
const addLine = () => journal.entries.push({ account_id: '', debit_amount: 0, credit_amount: 0, narration: '' });
const removeLine = (i) => journal.entries.splice(i, 1);
const canReverse = (row) => ['approved', 'posted'].includes(row.status) && row.voucher_type !== 'reversal' && !row.reversed_by_id && !row.reversal_of_id;
const reverse = async (row) => { if (!canReverse(row)) return; const remarks = prompt('Reversal remarks'); if (remarks) { await AccountingApi.reverseJournal(row.id, remarks); await load(); } };
const money = (v) => Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
onMounted(load);
</script>

<template>
    <Layout :page="page" :title="title">
        <template #topbar-title>
            <div class="bill-page-title"><span>ACCOUNTING</span><h1>Vouchers</h1><p>Customer receipts, supplier payments, contra and manual journals.</p></div>
        </template>
        <div class="acct-page">
            <section class="tabs"><button :class="{active:tab==='receipt'}" @click="tab='receipt'">Receipt</button><button :class="{active:tab==='payment'}" @click="tab='payment'">Payment</button><button :class="{active:tab==='contra'}" @click="tab='contra'">Contra</button><button :class="{active:tab==='journal'}" @click="tab='journal'">Journal</button></section>
            <section v-if="tab==='receipt'" class="panel receipt-panel">
                <fieldset :disabled="saving">
                    <div class="form-grid">
                        <label>Customer<select v-model="receipt.customer_id"><option value="">Select Customer</option><option v-for="s in refs.customers" :key="s.id" :value="s.id">{{ s.customer_name || s.name }}</option></select></label>
                        <label>Receipt Date<input v-model="receipt.receipt_date" type="date" /></label>
                        <label>Receipt Mode<select v-model="receipt.receipt_mode"><option value="cash">Cash</option><option value="bank_transfer">Bank</option><option value="upi">UPI</option><option value="card">Card</option><option value="cheque">Cheque</option><option value="wallet">Wallet</option><option value="mixed">Mixed</option></select></label>
                        <label>Cash/Bank Account<select v-model="receipt.cash_bank_account_id"><option value="">Select Account</option><option v-for="a in refs.cash_bank_accounts" :key="a.id" :value="a.id">{{ a.account_name }}</option></select></label>
                        <label>Receipt Amount<input v-model="receipt.amount" type="number" min="0.01" step="0.01" /></label>
                        <label>Receipt Branch<input :value="receiptBranchLabel" readonly /><small>Set automatically from allocated invoices. One branch per receipt.</small></label>
                        <label>Reference<input v-model="receipt.reference_number" /></label>
                        <label>Remarks<input v-model="receipt.remarks" /></label>
                    </div>
                    <h3>Open Sales Invoices</h3>
                    <p v-if="invoicesLoading">Loading open invoices…</p>
                    <p v-else-if="!openInvoices.length">{{ receipt.customer_id ? 'No open sales invoices for this customer.' : 'Select a customer to view open sales invoices.' }}</p>
                    <div v-else class="table-wrapper"><table><thead><tr><th>Invoice No</th><th>Branch</th><th>Date</th><th>Due Date</th><th>Original Amount</th><th>Paid</th><th>Credit Note</th><th>Outstanding</th><th>Allocate Now</th></tr></thead><tbody><tr v-for="bill in openInvoices" :key="bill.invoice_id"><td>{{ bill.invoice_number }}</td><td>{{ receiptBranchName(bill.branch_id) }}</td><td>{{ formatDate(bill.invoice_date) }}</td><td>{{ formatDate(bill.due_date) }}</td><td>{{ money(bill.invoice_amount) }}</td><td>{{ money(bill.received_amount) }}</td><td>{{ money(bill.credit_note_amount) }}</td><td>{{ money(bill.outstanding_amount) }}</td><td><input v-model="bill.allocate" :disabled="differentReceiptBranch(bill)" :aria-label="`Allocate to ${bill.invoice_number}`" type="number" min="0" :max="bill.outstanding_amount" step="0.01" /></td></tr></tbody></table></div>
                    <div class="totals"><span>Receipt: ₹{{ money(receipt.amount) }}</span><span>Allocated: ₹{{ money(receiptAllocatedCents / 100) }}</span><span>Unallocated: ₹{{ money(receiptRemainingCents / 100) }}</span></div>
                    <p>Allocate the full receipt amount to invoices. Advances and discount/write-off adjustments are not available in this flow.</p>
                    <p v-if="receiptError" role="alert" class="receipt-error">{{ receiptError }}</p><p v-if="receiptMessage" role="status">{{ receiptMessage }}</p>
                    <button :disabled="saving || invalidReceipt" @click="saveReceipt">{{ saving ? 'Saving…' : 'Save Receipt' }}</button>
                </fieldset>
            </section>
            <section v-if="tab==='payment'" class="panel payment-panel">
                <fieldset :disabled="saving">
                    <div class="form-grid">
                        <label>Supplier<select v-model="payment.supplier_id"><option value="">Select Supplier</option><option v-for="s in refs.suppliers" :key="s.id" :value="s.id">{{ s.supplier_name || s.name }}</option></select></label>
                        <label>Payment Date<input v-model="payment.payment_date" type="date" /></label>
                        <label>Payment Mode<select v-model="payment.payment_mode"><option value="cash">Cash</option><option value="bank_transfer">Bank</option><option value="upi">UPI</option><option value="card">Card</option><option value="cheque">Cheque</option><option value="wallet">Wallet</option><option value="mixed">Mixed</option></select></label>
                        <label>Cash/Bank Account<select v-model="payment.cash_bank_account_id"><option value="">Select Account</option><option v-for="a in refs.cash_bank_accounts" :key="a.id" :value="a.id">{{ a.account_name }}</option></select></label>
                        <label>Payment Amount<input v-model="payment.amount" type="number" min="0.01" step="0.01" /></label>
                        <label>Payment Branch<input :value="resolvedBranchLabel" readonly /><small>Set automatically from allocated bills. One branch per payment.</small></label>
                        <label>Reference<input v-model="payment.reference_number" /></label>
                        <label>Remarks<input v-model="payment.remarks" /></label>
                    </div>
                    <h3>Open Purchase Bills</h3>
                    <p v-if="billsLoading">Loading open bills…</p>
                    <p v-else-if="!openBills.length">{{ payment.supplier_id ? 'No open purchase bills for this supplier.' : 'Select a supplier to view open purchase bills.' }}</p>
                    <div v-else class="table-wrapper"><table><thead><tr><th>Purchase No</th><th>Branch</th><th>Date</th><th>Due Date</th><th>Original Amount</th><th>Paid</th><th>Outstanding</th><th>Allocate Now</th></tr></thead><tbody><tr v-for="bill in openBills" :key="bill.purchase_id"><td>{{ bill.purchase_number }}</td><td>{{ branchName(bill.branch_id) }}</td><td>{{ formatDate(bill.purchase_date) }}</td><td>{{ formatDate(bill.due_date) }}</td><td>{{ money(bill.purchase_amount) }}</td><td>{{ money(bill.paid_amount) }}</td><td>{{ money(bill.outstanding_amount) }}</td><td><input v-model="bill.allocate" :disabled="differentBranch(bill)" :aria-label="`Allocate to ${bill.purchase_number}`" type="number" min="0" :max="bill.outstanding_amount" step="0.01" /></td></tr></tbody></table></div>
                    <div class="totals"><span>Payment: ₹{{ money(payment.amount) }}</span><span>Allocated: ₹{{ money(allocatedCents / 100) }}</span><span>Unallocated: ₹{{ money(remainingCents / 100) }}</span></div>
                    <p>Allocate the full payment amount to bills. Advances and discount/write-off adjustments are not available in this flow.</p>
                    <p v-if="paymentError" role="alert" class="payment-error">{{ paymentError }}</p><p v-if="paymentMessage" role="status">{{ paymentMessage }}</p>
                    <button :disabled="saving || invalidPayment" @click="savePayment">{{ saving ? 'Saving…' : 'Save Payment' }}</button>
                </fieldset>
            </section>
            <section v-if="tab==='contra'" class="panel"><div class="form-grid"><input v-model="contra.voucher_date" type="date" /><select v-model="contra.from_account_id"><option value="">From Account</option><option v-for="a in refs.cash_bank_accounts" :key="a.id" :value="a.id">{{ a.account_name }}</option></select><select v-model="contra.to_account_id"><option value="">To Account</option><option v-for="a in refs.cash_bank_accounts" :key="a.id" :value="a.id">{{ a.account_name }}</option></select><input v-model="contra.amount" type="number" placeholder="Amount" /><input v-model="contra.reference_number" placeholder="Reference" /><input v-model="contra.remarks" placeholder="Remarks" /><button :disabled="saving" @click="saveContra">Post Contra</button></div></section>
            <section v-if="tab==='journal'" class="panel"><div class="form-grid"><input v-model="journal.voucher_date" type="date" /><input v-model="journal.reference_number" placeholder="Reference" /><input v-model="journal.narration" placeholder="Narration" /><select v-model="journal.status"><option value="draft">Draft</option><option value="approved">Post</option></select></div><div class="table-wrapper"><table><thead><tr><th>Account</th><th>Debit</th><th>Credit</th><th>Narration</th><th></th></tr></thead><tbody><tr v-for="(line,i) in journal.entries" :key="i"><td><select v-model="line.account_id"><option value="">Account</option><option v-for="a in refs.accounts" :key="a.id" :value="a.id">{{ a.account_name }}</option></select></td><td><input v-model="line.debit_amount" type="number" /></td><td><input v-model="line.credit_amount" type="number" /></td><td><input v-model="line.narration" /></td><td><button @click="removeLine(i)">Remove</button></td></tr></tbody></table></div><div class="totals"><span>Debit Rs. {{ money(totals.debit) }}</span><span>Credit Rs. {{ money(totals.credit) }}</span><span>Difference Rs. {{ money(totals.debit - totals.credit) }}</span><button @click="addLine">Add Row</button><button :disabled="saving || totals.debit !== totals.credit" @click="saveJournal">Save Journal</button></div></section>
            <section class="panel"><h3>Recent Journals</h3><div class="table-wrapper"><table><thead><tr><th>No</th><th>Date</th><th>Type</th><th>Debit</th><th>Credit</th><th>Status</th><th></th></tr></thead><tbody><tr v-for="j in journals" :key="j.id"><td>{{ j.voucher_number }}</td><td>{{ formatDate(j.voucher_date) }}</td><td>{{ j.voucher_type }}</td><td>Rs. {{ money(j.total_debit) }}</td><td>Rs. {{ money(j.total_credit) }}</td><td>{{ j.status }}</td><td><button v-if="canReverse(j)" @click="reverse(j)">Reverse</button></td></tr></tbody></table></div></section>
        </div>
    </Layout>
</template>

<style scoped>
.acct-page{padding:4px 0 28px}.page-heading,.tabs,.totals{display:flex;align-items:center;gap:10px}.page-heading{justify-content:space-between;margin-bottom:18px}.page-heading span{color:#2457d6;font-size:10px;font-weight:800;letter-spacing:1.2px}.page-heading h1{margin:0;color:#142139}.page-heading p{margin:6px 0 0;color:#758197;font-size:13px}.tabs{margin-bottom:12px}.tabs .active{color:#fff;background:#2457d6;border-color:#2457d6}.panel{margin-bottom:18px;padding:18px;background:#fff;border:1px solid #dfe6ef;border-radius:8px}.form-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}input,select,button{min-height:38px;padding:8px 10px;color:#344159;background:#fff;border:1px solid #d8e0eb;border-radius:8px;font-size:12px}button{font-weight:750;cursor:pointer}.table-wrapper{overflow-x:auto}table{width:100%;border-collapse:collapse}th,td{padding:11px 10px;border-bottom:1px solid #edf1f5;text-align:left;white-space:nowrap;font-size:12px}th{color:#69758a;background:#f8fafc;font-size:10px;text-transform:uppercase}.totals{justify-content:flex-end;margin-top:12px}@media(max-width:900px){.form-grid{grid-template-columns:1fr}.tabs,.totals{align-items:stretch;flex-direction:column}}
.payment-panel fieldset,.receipt-panel fieldset{border:0;padding:0;margin:0;min-width:0}.payment-panel label,.receipt-panel label{display:grid;gap:6px;font-size:12px;color:#667085}.payment-panel input,.payment-panel select,.receipt-panel input,.receipt-panel select{width:100%;min-width:0}.payment-panel p,.receipt-panel p{font-size:12px;color:#667085}.payment-panel .payment-error,.receipt-panel .receipt-error{color:#b42318}.payment-panel button:disabled{opacity:.5;cursor:not-allowed}
</style>
