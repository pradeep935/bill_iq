<script setup>
import { computed, nextTick, onMounted, reactive, ref } from 'vue';
import Layout from './Layout.vue';
import SalesApi from './Sales/SalesApi';

defineProps({ page: { type: String, default: 'pos' }, title: { type: String, default: 'POS Billing' } });

const today = new Date().toISOString().slice(0, 10);
const scanInput = ref(null);
const search = ref('');
const products = ref([]);
const references = ref({ customers: [], branches: [], warehouses: [], payment_methods: [] });
const saving = ref(false);
const message = ref('');
const form = reactive({
    branch_id: '', warehouse_id: '', customer_id: '', invoice_date: today, sale_type: 'cash',
    invoice_type: 'retail_invoice', tax_type: 'intrastate', voucher_discount_type: '', voucher_discount_value: 0,
    shipping_amount: 0, other_charges: 0, remarks: '', items: [], payments: [],
});

const filteredWarehouses = computed(() => !form.branch_id ? references.value.warehouses || [] : (references.value.warehouses || []).filter((w) => Number(w.branch_id || 0) === Number(form.branch_id)));
const cashMethod = computed(() => (references.value.payment_methods || []).find((m) => m.type === 'cash') || (references.value.payment_methods || [])[0]);
const upiMethod = computed(() => (references.value.payment_methods || []).find((m) => m.type === 'upi'));
const cardMethod = computed(() => (references.value.payment_methods || []).find((m) => m.type === 'card'));
const selectedCustomer = computed(() => (references.value.customers || []).find((c) => Number(c.id) === Number(form.customer_id)));
const priceType = computed(() => selectedCustomer.value?.price_type || 'retail');

const line = (item) => {
    const gross = Number(item.quantity || 0) * Number(item.selling_rate || 0);
    const discount = item.discount_type === 'percentage' ? gross * Math.min(Number(item.discount_value || 0), 100) / 100 : Math.min(gross, Number(item.discount_value || 0));
    const taxable = Math.max(0, gross - discount);
    const tax = taxable * (Number(item.gst_rate || 0) + Number(item.cess_rate || 0)) / 100;
    return { taxable, tax, total: taxable + tax };
};
const totals = computed(() => {
    const gross = form.items.reduce((sum, item) => sum + line(item).total, 0);
    const discount = form.voucher_discount_type === 'percentage' ? gross * Math.min(Number(form.voucher_discount_value || 0), 100) / 100 : Math.min(gross, Number(form.voucher_discount_value || 0));
    const grand = Math.round(Math.max(0, gross - discount));
    const paid = form.payments.reduce((sum, p) => sum + Number(p.amount || 0), 0);
    return { grand, paid, balance: Math.max(0, grand - paid), change: Math.max(0, paid - grand) };
});

const loadReferences = async () => {
    references.value = await SalesApi.references();
    form.branch_id = references.value.branches?.[0]?.id || '';
    form.warehouse_id = filteredWarehouses.value?.[0]?.id || references.value.warehouses?.[0]?.id || '';
    form.customer_id = (references.value.customers || []).find((c) => c.customer_type === 'walk_in')?.id || '';
};
const searchProducts = async () => {
    if (search.value.trim().length < 2) { products.value = []; return; }
    products.value = await SalesApi.searchProducts(search.value.trim(), { branch_id: form.branch_id, warehouse_id: form.warehouse_id, price_type: priceType.value });
};
const addProduct = (product) => {
    const batch = (product.batches || []).find((b) => Number(b.available_stock || 0) > 0);
    const existing = form.items.find((item) => item.product_id === product.id && Number(item.batch_id || 0) === Number(batch?.id || 0));
    if (existing) {
        existing.quantity = Number(existing.quantity || 0) + 1;
    } else {
        form.items.push({
            product_id: product.id, product: product.name, sku: product.sku, product_variant_id: '',
            batch_id: batch?.id || '', unit_id: product.unit_id || '', quantity: 1, free_quantity: 0,
            selling_rate: product.selling_rate || 0, mrp: product.mrp || '', discount_type: '', discount_value: 0,
            gst_rate: product.gst_rate || 0, cess_rate: product.cess_rate || 0, batches: product.batches || [],
            available_stock: batch?.available_stock ?? product.available_stock,
        });
    }
    search.value = ''; products.value = []; message.value = '';
    nextTick(() => scanInput.value?.focus());
};
const scan = async () => {
    await searchProducts();
    if (products.value.length) addProduct(products.value[0]);
    else message.value = 'Barcode or product not found.';
};
const qty = (item, amount) => { item.quantity = Math.max(1, Number(item.quantity || 0) + amount); };
const removeItem = (index) => form.items.splice(index, 1);
const setPayment = (method) => { if (!method) return; form.payments = [{ payment_method_id: method.id, amount: totals.value.grand, reference_number: '', payment_date: today, notes: '' }]; };
const hold = () => save('hold');
const complete = () => save('approved');
const save = async (status) => {
    if (saving.value) return;
    saving.value = true; message.value = '';
    try {
        if (!form.payments.length && status === 'approved') setPayment(cashMethod.value);
        const response = await SalesApi.saveSale({ ...form, due_date: '', place_of_supply_state_id: null, status }, null);
        message.value = response.message || 'Sale completed.';
        if (status === 'approved') print(response.sale);
        form.items = []; form.payments = []; search.value = '';
    } catch (error) {
        message.value = error.response?.data?.message || Object.values(error.response?.data?.errors || {})?.[0]?.[0] || 'POS sale save nahi ho saka.';
    } finally { saving.value = false; nextTick(() => scanInput.value?.focus()); }
};
const print = (sale) => {
    const html = `<html><head><title>${sale.invoice_number}</title><style>body{font-family:monospace;width:280px;margin:0;padding:8px}h3,p{margin:4px 0}.row{display:flex;justify-content:space-between;border-bottom:1px dashed #bbb;padding:4px 0}</style></head><body><h3>Receipt</h3><p>${sale.invoice_number}</p>${(sale.items || []).map((i) => `<div class="row"><span>${i.product} x ${i.quantity}</span><span>${formatMoney(i.line_total)}</span></div>`).join('')}<h3>Total Rs. ${formatMoney(sale.grand_total)}</h3><p>Paid Rs. ${formatMoney(sale.paid_amount)} Change Rs. ${formatMoney(sale.change_returned)}</p></body></html>`;
    const win = window.open('', '_blank'); win.document.write(html); win.document.close(); win.print();
};
const formatMoney = (value) => Number(value || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

onMounted(async () => { await loadReferences(); scanInput.value?.focus(); });
</script>

<template>
    <Layout page="pos" title="POS Billing">
        <div class="pos-page">
            <section class="pos-bar"><select v-model="form.branch_id"><option v-for="b in references.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select><select v-model="form.warehouse_id"><option v-for="w in filteredWarehouses" :key="w.id" :value="w.id">{{ w.name }}</option></select><select v-model="form.customer_id"><option v-for="c in references.customers" :key="c.id" :value="c.id">{{ c.customer_name }}</option></select><button @click="hold">Hold</button></section>
            <section class="pos-grid">
                <div class="panel">
                    <input ref="scanInput" v-model="search" class="scan" placeholder="Scan barcode or type product name" @keyup.enter="scan" @input="searchProducts" />
                    <div v-if="message" class="message">{{ message }}</div>
                    <div v-if="products.length" class="results"><button v-for="product in products" :key="product.id" @click="addProduct(product)"><strong>{{ product.name }}</strong><span>{{ product.sku }} | Stock {{ product.available_stock ?? 'Service' }}</span></button></div>
                    <div class="quick"><button @click="setPayment(cashMethod)">Cash</button><button @click="setPayment(upiMethod)">UPI</button><button @click="setPayment(cardMethod)">Card</button><button @click="form.sale_type='credit'">Credit</button></div>
                </div>
                <div class="panel cart">
                    <div class="cart-head"><h2>Cart</h2><span>{{ form.items.length }} items</span></div>
                    <div v-for="(item,index) in form.items" :key="`${item.product_id}-${index}`" class="cart-row"><div><strong>{{ item.product }}</strong><span>{{ item.sku }} | Rs. {{ formatMoney(item.selling_rate) }}</span></div><div class="qty"><button @click="qty(item,-1)">-</button><input v-model="item.quantity" type="number" /><button @click="qty(item,1)">+</button></div><b>Rs. {{ formatMoney(line(item).total) }}</b><button class="danger" @click="removeItem(index)">Remove</button></div>
                    <div v-if="!form.items.length" class="empty">Scan items to start billing.</div>
                    <div class="totals"><span>Grand Total</span><strong>Rs. {{ formatMoney(totals.grand) }}</strong><span>Paid</span><input v-if="form.payments[0]" v-model="form.payments[0].amount" type="number" /><span>Balance</span><strong>Rs. {{ formatMoney(totals.balance) }}</strong><span>Change</span><strong>Rs. {{ formatMoney(totals.change) }}</strong></div>
                    <button class="complete" :disabled="saving || !form.items.length" @click="complete">{{ saving ? 'Saving...' : 'Complete Sale' }}</button>
                </div>
            </section>
        </div>
    </Layout>
</template>

<style scoped>
.pos-page{padding:4px 0 28px}.pos-bar,.quick,.cart-head,.cart-row,.qty,.totals{display:flex;align-items:center;gap:10px}.pos-bar{margin-bottom:14px;flex-wrap:wrap}.pos-grid{display:grid;grid-template-columns:360px minmax(0,1fr);gap:16px}.panel{padding:16px;background:#fff;border:1px solid #dfe6ef;border-radius:8px}.scan{width:100%;height:48px;font-size:15px}input,select,button{min-height:38px;padding:8px 10px;color:#344159;background:#fff;border:1px solid #d8e0eb;border-radius:8px;font-size:12px}button{font-weight:800;cursor:pointer}.quick{display:grid;grid-template-columns:repeat(2,1fr);margin-top:12px}.quick button,.complete{color:#fff;background:#2457d6;border-color:#2457d6}.results{display:grid;margin-top:10px;border:1px solid #e5ebf3;border-radius:8px;overflow:hidden}.results button{display:grid;justify-items:start;border:0;border-bottom:1px solid #edf1f5;border-radius:0}.results span,.cart-row span{display:block;color:#7a869a;font-size:10px}.cart{display:grid;gap:10px}.cart-head{justify-content:space-between}.cart-row{justify-content:space-between;border-bottom:1px solid #edf1f5;padding:10px 0}.qty input{width:62px;text-align:center}.danger{color:#d23f49;background:#fff3f4;border-color:#ffd6da}.totals{display:grid;grid-template-columns:1fr 1fr;margin-top:10px;padding:14px;background:#f8fafc;border-radius:8px}.totals strong{font-size:18px}.complete{height:52px;font-size:16px}.empty{padding:38px;text-align:center;color:#8490a2}.message{margin-top:10px;padding:10px;color:#96333a;background:#fff3f4;border:1px solid #ffd4d8;border-radius:8px}@media(max-width:900px){.pos-grid{grid-template-columns:1fr}.cart-row{align-items:flex-start;flex-direction:column}}
</style>
