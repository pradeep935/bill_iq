<script setup>
import { computed, reactive, ref, watch } from 'vue';
import axios from 'axios';
import SalesApi from './SalesApi';

// Direct edit of a saved sales invoice. A posted invoice keeps its number; the server corrects stock and accounts.
const props = defineProps({ sale: { type: Object, required: true }, references: { type: Object, required: true } });
const emit = defineEmits(['close', 'saved']);

const number = (value) => Number(value || 0);
const round = (value) => Math.round((value + Number.EPSILON) * 100) / 100;
const money = (value) => number(value).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const today = new Date().toISOString().slice(0, 10);

const posted = ['approved', 'confirmed', 'reversed'].includes(props.sale.status);
const paidOnBill = (props.sale.payments || []).reduce((sum, p) => sum + number(p.amount), 0);
// Anything paid beyond the money entered on the bill came from receipt vouchers.
const settled = posted ? Math.max(0, round(number(props.sale.paid_amount) - Math.min(paidOnBill, number(props.sale.grand_total)))) : 0;

const form = reactive({
    ...JSON.parse(JSON.stringify(props.sale)),
    due_date: props.sale.due_date || '',
    voucher_discount_type: props.sale.voucher_discount_type || '',
    items: (props.sale.items || []).map((item) => ({ ...item, discount_type: item.discount_type || '', batches: [] })),
    payments: (props.sale.payments || []).map((p) => ({ ...p })),
});
const saving = ref('');
const error = ref('');
const search = ref('');
const results = ref([]);

const taxFree = computed(() => ['exempt', 'nil_rated'].includes(form.tax_type) || form.invoice_type === 'bill_of_supply');
const line = (item) => {
    const gross = round(number(item.quantity) * number(item.selling_rate));
    const value = number(item.discount_value);
    const discount = value > 0 && item.discount_type ? round(item.discount_type === 'percentage' ? gross * Math.min(value, 100) / 100 : Math.min(gross, value)) : 0;
    const net = Math.max(0, gross - discount);
    const rate = taxFree.value ? 0 : number(item.gst_rate) + number(item.cess_rate);
    const taxable = item.tax_inclusive && rate > 0 ? round(net * 100 / (100 + rate)) : net;
    const tax = item.tax_inclusive && rate > 0 ? round(net - taxable) : round(taxable * rate / 100);
    return { taxable, tax, total: taxable + tax };
};
const totals = computed(() => {
    const lines = form.items.map(line);
    const taxable = lines.reduce((sum, l) => sum + l.taxable, 0);
    const tax = lines.reduce((sum, l) => sum + l.tax, 0);
    const value = number(form.voucher_discount_value);
    const discount = value > 0 && form.voucher_discount_type ? (form.voucher_discount_type === 'percentage' ? taxable * Math.min(value, 100) / 100 : Math.min(taxable, value)) : 0;
    const after = Math.max(0, taxable - discount);
    const taxAfter = taxable > 0 ? round(tax * after / taxable) : 0;
    const before = round(after + taxAfter + number(form.shipping_amount) + number(form.other_charges));
    const grand = Math.round(before);
    const paid = round(form.payments.reduce((sum, p) => sum + number(p.amount), 0));
    return { taxable: after, discount, tax: taxAfter, roundOff: grand - before, grand, paid, balance: Math.max(0, round(grand - paid - settled)) };
});

// A cash bill paid with one entry stays fully paid when the total changes.
watch(() => totals.value.grand, (grand, old) => {
    if (form.sale_type === 'cash' && form.payments.length === 1 && Math.abs(number(form.payments[0].amount) - old) < 0.005) form.payments[0].amount = grand;
});
watch(() => form.invoice_type, (type) => {
    if (type === 'bill_of_supply') form.tax_type = 'exempt';
    else if (form.tax_type === 'exempt') form.tax_type = 'intrastate';
});

let timer;
const searchProducts = () => {
    clearTimeout(timer);
    const term = search.value.trim();
    if (term.length < 2) { results.value = []; return; }
    timer = setTimeout(async () => {
        const found = await SalesApi.searchProducts(term, { branch_id: form.branch_id, warehouse_id: form.warehouse_id }).catch(() => []);
        if (search.value.trim() === term) results.value = found.slice(0, 10);
    }, 250);
};
const addProduct = (product) => {
    results.value = [];
    search.value = '';
    if (product.serial_required) { error.value = `${product.name} needs serial numbers. Bill it from POS Billing.`; return; }
    const batch = (product.batches || []).find((b) => number(b.available_stock) > 0);
    form.items.push({
        product_id: product.id, product: product.name, product_variant_id: product.product_variant_id || null,
        batch_id: product.batch_id || batch?.id || null, batches: product.batches || [], unit_id: product.unit_id || null, unit: product.unit || 'PCS',
        quantity: 1, free_quantity: 0, selling_rate: number(product.selling_rate), mrp: product.mrp || null,
        discount_type: '', discount_value: 0, gst_rate: number(product.gst_rate), cess_rate: number(product.cess_rate), tax_inclusive: Boolean(product.tax_inclusive),
    });
};

const addPayment = () => {
    const methods = props.references.payment_methods || [];
    const method = methods.find((m) => m.type === 'cash') || methods[0];
    if (method) form.payments.push({ payment_method_id: method.id, amount: totals.value.balance, reference_number: '', payment_date: today, notes: '' });
};

const save = async (status) => {
    if (saving.value) return;
    error.value = '';
    if (!form.items.length) { error.value = 'Add at least one item.'; return; }
    if (status === 'approved' && form.sale_type === 'cash' && totals.value.paid + 0.005 < totals.value.grand) {
        error.value = 'Cash bill: the received amount is less than the total. Correct the payment or choose Credit.';
        return;
    }
    saving.value = status;
    try {
        const response = await axios.put(`/app/sales/invoices/${props.sale.id}`, {
            ...form, status,
            voucher_discount_type: form.voucher_discount_type || null,
            items: form.items.map(({ batches, ...item }) => ({ ...item, discount_type: item.discount_type || null, discount_value: item.discount_value || 0 })),
            payments: form.payments.filter((p) => number(p.amount) > 0),
        });
        emit('saved', response.data);
    } catch (e) {
        error.value = Object.values(e.response?.data?.errors || {})[0]?.[0] || e.response?.data?.message || 'Could not save the invoice. Please try again.';
    } finally {
        saving.value = '';
    }
};
</script>

<template>
    <div class="ei-backdrop">
        <section class="ei-panel" role="dialog" aria-modal="true" aria-label="Edit sales invoice">
            <div class="ei-head">
                <div><span>EDIT SALES INVOICE</span><h2>{{ sale.invoice_number }}</h2></div>
                <button type="button" @click="emit('close')">Cancel</button>
            </div>
            <p v-if="posted" class="ei-note">
                {{ sale.status === 'reversed' ? 'This invoice was reversed. Save Changes posts it again.' : 'This invoice is posted. Save Changes corrects stock and accounts.' }}
                The invoice number stays the same.
                <template v-if="settled"> Rs. {{ money(settled) }} is already received by receipt voucher and stays on this invoice.</template>
            </p>

            <div class="ei-grid">
                <label class="wide">Customer
                    <select v-model="form.customer_id" :disabled="settled > 0">
                        <option v-for="c in references.customers" :key="c.id" :value="c.id">{{ c.customer_name }}</option>
                        <option v-if="!references.customers?.some((c) => c.id === sale.customer_id)" :value="sale.customer_id">{{ sale.customer }}</option>
                    </select>
                </label>
                <label>Invoice Date<input v-model="form.invoice_date" type="date" /></label>
                <label>Due Date<input v-model="form.due_date" type="date" /></label>
                <label>Sale Type<select v-model="form.sale_type" :disabled="settled > 0"><option value="cash">Cash</option><option value="credit">Credit</option></select></label>
                <label>Bill Type<select v-model="form.invoice_type"><option value="retail_invoice">B2C Retail</option><option value="tax_invoice">B2B Tax Invoice</option><option value="bill_of_supply">Bill of Supply</option></select></label>
                <label>Tax Type<select v-model="form.tax_type" :disabled="form.invoice_type === 'bill_of_supply'"><option value="intrastate">Intrastate (CGST + SGST)</option><option value="interstate">Interstate (IGST)</option><option value="exempt">Exempt</option><option value="nil_rated">Nil rated</option></select></label>
            </div>

            <div class="ei-search">
                <input v-model="search" type="search" placeholder="Add item: type product name, SKU or barcode" aria-label="Add item" autocomplete="off" @input="searchProducts" />
                <div v-if="results.length" class="ei-results">
                    <button v-for="p in results" :key="`${p.id}-${p.product_variant_id || 0}`" type="button" @click="addProduct(p)">{{ p.name }} <small>Stock {{ p.available_stock ?? '-' }} · Rs. {{ money(p.selling_rate) }}</small></button>
                </div>
            </div>

            <div class="ei-table">
                <table>
                    <thead><tr><th>Item</th><th>Qty</th><th>Free</th><th>Rate</th><th>Discount</th><th>GST %</th><th class="num">Amount</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="(item, i) in form.items" :key="i">
                            <td class="name">
                                {{ item.product }}
                                <select v-if="item.batches?.length" v-model="item.batch_id" aria-label="Batch"><option v-for="b in item.batches" :key="b.id" :value="b.id">{{ b.batch_no || b.batch_number }} ({{ b.available_stock }})</option></select>
                                <small>{{ item.tax_inclusive ? 'rate with tax' : 'rate without tax' }}</small>
                            </td>
                            <td><input v-model="item.quantity" type="number" min="0" step="any" aria-label="Quantity" /></td>
                            <td><input v-model="item.free_quantity" type="number" min="0" step="any" aria-label="Free quantity" /></td>
                            <td><input v-model="item.selling_rate" type="number" min="0" step="any" aria-label="Rate" /></td>
                            <td class="discount">
                                <select v-model="item.discount_type" aria-label="Discount type"><option value="">None</option><option value="amount">Rs.</option><option value="percentage">%</option></select>
                                <input v-if="item.discount_type" v-model="item.discount_value" type="number" min="0" step="any" aria-label="Discount value" />
                            </td>
                            <td><input v-model="item.gst_rate" type="number" min="0" max="100" step="any" aria-label="GST rate" /></td>
                            <td class="num">{{ money(line(item).total) }}</td>
                            <td><button type="button" class="danger" :aria-label="`Remove ${item.product}`" @click="form.items.splice(i, 1)">×</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="ei-bottom">
                <div class="ei-extra">
                    <div class="pair">
                        <label>Bill Discount<select v-model="form.voucher_discount_type"><option value="">None</option><option value="amount">Amount</option><option value="percentage">Percent</option></select></label>
                        <label>Discount Value<input v-model="form.voucher_discount_value" type="number" min="0" step="any" :disabled="!form.voucher_discount_type" /></label>
                        <label>Shipping<input v-model="form.shipping_amount" type="number" min="0" step="any" /></label>
                        <label>Other Charges<input v-model="form.other_charges" type="number" min="0" step="any" /></label>
                    </div>
                    <label>Remarks<input v-model="form.remarks" /></label>
                    <div class="ei-payments">
                        <strong>Money received on this bill</strong>
                        <div v-for="(p, i) in form.payments" :key="i" class="pay">
                            <select v-model="p.payment_method_id" aria-label="Payment method"><option v-for="m in references.payment_methods" :key="m.id" :value="m.id">{{ m.name }}</option></select>
                            <input v-model="p.amount" type="number" min="0" step="any" aria-label="Amount received" />
                            <button type="button" class="danger" aria-label="Remove payment" @click="form.payments.splice(i, 1)">×</button>
                        </div>
                        <button type="button" class="secondary" @click="addPayment">+ Add payment</button>
                    </div>
                </div>
                <dl class="ei-total">
                    <dt>Taxable</dt><dd>{{ money(totals.taxable) }}</dd>
                    <dt>Tax</dt><dd>{{ money(totals.tax) }}</dd>
                    <dt>Round off</dt><dd>{{ money(totals.roundOff) }}</dd>
                    <dt class="grand">Total</dt><dd class="grand">Rs. {{ money(totals.grand) }}</dd>
                    <template v-if="settled"><dt>By receipt voucher</dt><dd>{{ money(settled) }}</dd></template>
                    <dt>Received on bill</dt><dd>{{ money(totals.paid) }}</dd>
                    <dt>Balance</dt><dd>{{ money(totals.balance) }}</dd>
                </dl>
            </div>

            <p v-if="posted && Math.abs(totals.grand - number(sale.grand_total)) >= 0.01" class="ei-warn" role="status">Total will change from Rs. {{ money(sale.grand_total) }} to Rs. {{ money(totals.grand) }}. Check it before saving.</p>

            <p v-if="error" class="ei-error" role="alert">{{ error }}</p>
            <div class="ei-foot">
                <button v-if="!posted" type="button" :disabled="Boolean(saving)" @click="save('draft')">{{ saving === 'draft' ? 'Saving…' : 'Save Draft' }}</button>
                <button type="button" class="primary" :disabled="Boolean(saving)" @click="save('approved')">{{ saving === 'approved' ? 'Saving…' : (posted ? 'Save Changes' : 'Confirm & Post') }}</button>
            </div>
        </section>
    </div>
</template>

<style scoped>
.ei-backdrop{position:fixed;z-index:1000;inset:0;display:flex;justify-content:flex-end;background:rgba(15,23,42,.42)}
.ei-panel{width:min(1100px,100%);height:100%;overflow:auto;padding:18px;background:#fff;box-shadow:-24px 0 70px rgba(15,23,42,.2)}
.ei-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}
.ei-head span{color:#2457d6;font-size:10px;font-weight:800;letter-spacing:1.2px}
.ei-head h2{margin:2px 0 0;color:#142139;font-size:20px}
input,select,button{min-height:36px;padding:7px 9px;color:#344159;background:#fff;border:1px solid #d8e0eb;border-radius:8px;font-size:13px}
input:disabled,select:disabled{background:#f1f4f8}
button{font-weight:750;cursor:pointer}
button:disabled{opacity:.55;cursor:not-allowed}
.primary{color:#fff;background:#2457d6;border-color:#2457d6}
.secondary{color:#2457d6;background:#edf4ff;border-color:#cfe0ff}
.danger{color:#d23f49;background:#fff3f4;border-color:#ffd6da}
.ei-note{margin:0 0 12px;padding:10px 12px;color:#1d4ed8;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;font-size:12px}
label{display:grid;gap:5px;color:#667085;font-size:11px;font-weight:800}
label input,label select{width:100%;min-width:0}
.ei-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.ei-grid .wide{grid-column:span 2}
.ei-search{position:relative;margin:14px 0 8px}
.ei-search input{width:100%}
.ei-results{position:absolute;z-index:5;top:calc(100% + 4px);left:0;right:0;display:grid;max-height:240px;overflow:auto;background:#fff;border:1px solid #dce4ef;border-radius:8px;box-shadow:0 12px 30px rgba(15,34,66,.12)}
.ei-results button{border:0;border-bottom:1px solid #eef2f6;border-radius:0;text-align:left}
small{color:#758197;font-size:11px}
.ei-table{overflow-x:auto;border:1px solid #e3e9f2;border-radius:10px}
table{width:100%;border-collapse:collapse}
th{padding:9px 8px;color:#69758a;background:#f8fafc;border-bottom:1px solid #e7ecf2;font-size:10px;text-align:left;text-transform:uppercase;white-space:nowrap}
td{padding:7px 8px;color:#27344c;border-bottom:1px solid #edf1f5;font-size:13px;vertical-align:top}
td input{width:86px}
td.name{min-width:200px}
td.name small{display:block}
td.name select{display:block;margin:4px 0}
.discount{white-space:nowrap}
.discount input{width:72px;margin-left:4px}
.num{text-align:right;white-space:nowrap}
.ei-bottom{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(250px,.8fr);gap:18px;margin-top:14px;align-items:start}
.ei-extra{display:grid;gap:10px}
.pair{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.ei-payments{display:grid;gap:8px;justify-items:start;font-size:12px;color:#142139}
.pay{display:grid;grid-template-columns:minmax(0,1fr) 130px auto;gap:8px;width:100%}
.ei-total{display:grid;grid-template-columns:1fr auto;gap:7px 16px;margin:0;padding:14px 16px;background:#f8fafc;border:1px solid #e3e9f2;border-radius:10px;font-size:13px}
.ei-total dd{margin:0;text-align:right;font-weight:700}
.ei-total .grand{padding-top:6px;border-top:1px solid #dfe6ef;color:#142139;font-size:17px;font-weight:800}
.ei-error{margin:12px 0 0;padding:10px 12px;color:#96333a;background:#fff3f4;border:1px solid #ffd4d8;border-radius:8px;font-size:13px}
.ei-warn{margin:12px 0 0;padding:10px 12px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;font-size:13px}
.ei-foot{position:sticky;bottom:-18px;display:flex;justify-content:flex-end;gap:10px;margin:14px -18px -18px;padding:12px 18px;background:#fff;border-top:1px solid #edf1f5}
@media(max-width:700px){.ei-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.ei-bottom{grid-template-columns:minmax(0,1fr)}}
</style>
