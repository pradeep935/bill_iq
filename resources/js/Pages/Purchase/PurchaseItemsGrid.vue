<script setup>
import { nextTick, ref, watch } from 'vue';

// Spreadsheet-like item entry: type in the last row, press Enter to pick, Enter again to move across the row.
const props = defineProps({
    items: { type: Array, required: true },
    totals: { type: Object, default: null },
    search: { type: String, default: '' },
    results: { type: Array, default: () => [] },
    searching: { type: Boolean, default: false },
    searchError: { type: String, default: '' },
    searchComplete: { type: Boolean, default: false },
    taxInclusive: { type: Boolean, default: false },
    readonly: { type: Boolean, default: false },
});
const emit = defineEmits(['update:search', 'update:taxInclusive', 'search', 'pick', 'new-product']);

const root = ref(null);
const searchInput = ref(null);
const highlighted = ref(0);
const pickWhenReady = ref(false);
const opened = ref(new Set());

const money = (value) => Number(value || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const line = (index) => props.totals?.items?.[index] || null;
const lineTax = (index) => {
    const row = line(index);
    return row ? Number(row.cgst_amount || 0) + Number(row.sgst_amount || 0) + Number(row.igst_amount || 0) + Number(row.cess_amount || 0) : null;
};
const sum = (key) => props.items.reduce((total, item) => total + Number(item[key] || 0), 0);
const totalOf = (getter) => props.items.reduce((total, item, index) => total + Number(getter(index) || 0), 0);

const needsDetails = (item) => Boolean(item.variants?.length || item.product_variant_id || item.batch_required || item.expiry_required || ['batch', 'batch_expiry', 'batch_serial'].includes(item.tracking_type) || item.batch_number || item.expiry_date || item.manufacturing_date || item.warehouse_location);
// A bill opened for viewing cannot be clicked, so any filled price is shown straight away.
const isOpen = (item, index) => opened.value.has(index) || needsDetails(item) || (props.readonly && Boolean(Number(item.selling_price) || Number(item.mrp)));
const toggle = (index) => {
    const next = new Set(opened.value);
    next.has(index) ? next.delete(index) : next.add(index);
    opened.value = next;
};
const remove = (index) => { props.items.splice(index, 1); opened.value = new Set(); };

const focusSearch = () => nextTick(() => searchInput.value?.focus());
const choose = async (product) => {
    pickWhenReady.value = false;
    highlighted.value = 0;
    emit('pick', product);
    await nextTick();
    const rows = root.value?.querySelectorAll('tr.item-row');
    const quantity = rows?.[rows.length - 1]?.querySelector('input[data-cell="quantity"]');
    quantity?.focus();
    quantity?.select();
};
const onType = (event) => {
    highlighted.value = 0;
    pickWhenReady.value = false;
    emit('update:search', event.target.value);
    emit('search');
};
const onSearchKey = (event) => {
    if (event.key === 'ArrowDown') { event.preventDefault(); highlighted.value = Math.min(highlighted.value + 1, props.results.length - 1); }
    else if (event.key === 'ArrowUp') { event.preventDefault(); highlighted.value = Math.max(highlighted.value - 1, 0); }
    else if (event.key === 'Enter') {
        event.preventDefault();
        if (props.results[highlighted.value]) choose(props.results[highlighted.value]);
        else if (props.search.trim()) pickWhenReady.value = true; // a barcode scanner presses Enter before results arrive
    }
};
watch(() => props.results, (results) => { if (pickWhenReady.value && results.length === 1) choose(results[0]); });

const nextCell = (event) => {
    event.preventDefault();
    const cells = [...event.target.closest('tr').querySelectorAll('input[data-cell]')];
    const next = cells[cells.indexOf(event.target) + 1];
    if (next) { next.focus(); next.select(); } else focusSearch();
};
defineExpose({ focusSearch });
</script>

<template>
    <section ref="root" class="grid-box" aria-label="Purchase items">
        <div class="grid-scroll" :class="{ open: searching || searchComplete || searchError }">
            <table>
                <thead>
                    <tr>
                        <th class="c-no">#</th>
                        <th class="c-item">Item</th>
                        <th class="c-num">Qty <b class="req">*</b></th>
                        <th class="c-num">Free</th>
                        <th class="c-unit">Unit</th>
                        <th class="c-rate">
                            Rate <b class="req">*</b>
                            <select :value="taxInclusive ? 'with' : 'without'" aria-label="Rates include tax or not" :disabled="readonly" @change="emit('update:taxInclusive', $event.target.value === 'with')">
                                <option value="without">Without tax</option>
                                <option value="with">With tax</option>
                            </select>
                        </th>
                        <th class="c-disc">Discount</th>
                        <th class="c-num">GST %</th>
                        <th class="c-money">Tax</th>
                        <th class="c-money">Amount</th>
                        <th class="c-act"></th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="(item, index) in items" :key="`${item.product_id}-${index}`">
                        <tr class="item-row">
                            <td class="c-no">{{ index + 1 }}</td>
                            <td class="c-item">
                                <strong>{{ item.product_name }}</strong>
                                <button type="button" class="link" :aria-expanded="isOpen(item, index)" @click="toggle(index)">{{ item.sku || 'No SKU' }} · MRP, batch &amp; more {{ isOpen(item, index) ? '▴' : '▾' }}</button>
                            </td>
                            <td class="c-num"><input v-model="item.quantity" data-cell="quantity" type="number" min="0" step="any" aria-label="Quantity" @keydown.enter="nextCell" /></td>
                            <td class="c-num"><input v-model="item.free_quantity" data-cell="free" type="number" min="0" step="any" aria-label="Free quantity" @keydown.enter="nextCell" /></td>
                            <td class="c-unit">{{ item.unit || '-' }}</td>
                            <td class="c-rate"><input v-model="item.purchase_rate" data-cell="rate" type="number" min="0" step="any" aria-label="Purchase rate" @keydown.enter="nextCell" /></td>
                            <td class="c-disc">
                                <div class="disc">
                                    <input v-model="item.discount_value" data-cell="discount" type="number" min="0" step="any" aria-label="Item discount" @input="item.discount_type ||= 'percentage'" @keydown.enter="nextCell" />
                                    <select v-model="item.discount_type" aria-label="Item discount type"><option value="">-</option><option value="percentage">%</option><option value="amount">Rs</option></select>
                                </div>
                            </td>
                            <td class="c-num"><input v-model="item.gst_rate" data-cell="gst" type="number" min="0" max="100" step="any" list="gst-rates" aria-label="GST percent" @keydown.enter="nextCell" /></td>
                            <td class="c-money">{{ lineTax(index) === null ? '…' : money(lineTax(index)) }}</td>
                            <td class="c-money strong">{{ line(index) ? money(line(index).line_total) : '…' }}</td>
                            <td class="c-act"><button type="button" class="remove" :aria-label="`Remove ${item.product_name}`" title="Remove item" @click="remove(index)">×</button></td>
                        </tr>
                        <tr v-if="isOpen(item, index)" class="detail-row">
                            <td></td>
                            <td colspan="10">
                                <div class="details">
                                    <label>Sell price<input v-model="item.selling_price" type="number" min="0" step="any" /></label>
                                    <label>MRP<input v-model="item.mrp" type="number" min="0" step="any" /></label>
                                    <label v-if="item.variants?.length || item.product_variant_id">Variant<select v-model="item.product_variant_id"><option value="">Default</option><option v-if="item.product_variant_id && !item.variants?.length" :value="item.product_variant_id">{{ item.variant || 'Selected variant' }}</option><option v-for="v in item.variants" :key="v.id" :value="v.id">{{ v.sku || v.barcode }}</option></select></label>
                                    <label>Batch {{ item.batch_required ? '*' : '' }}<input v-model="item.batch_number" /></label>
                                    <label>MFG date<input v-model="item.manufacturing_date" type="date" /></label>
                                    <label>Expiry date {{ item.expiry_required ? '*' : '' }}<input v-model="item.expiry_date" type="date" /></label>
                                    <label>Rack / location<input v-model="item.warehouse_location" /></label>
                                </div>
                            </td>
                        </tr>
                    </template>

                    <tr v-if="!readonly" class="new-row">
                        <td class="c-no">{{ items.length + 1 }}</td>
                        <td colspan="10">
                            <div class="finder">
                                <input ref="searchInput" :value="search" placeholder="Type item name or scan barcode, then press Enter" aria-label="Add item: search by name, SKU or barcode" autocomplete="off" @input="onType" @keydown="onSearchKey" />
                                <button type="button" class="new-product" @click="emit('new-product')">+ New product</button>
                                <div v-if="searching || searchError || searchComplete" class="results" aria-live="polite">
                                    <p v-if="searching">Searching…</p>
                                    <p v-else-if="searchError" role="alert">{{ searchError }}</p>
                                    <p v-else-if="!results.length">No matching stock product. Try another word or use “+ New product”.</p>
                                    <template v-else>
                                        <div class="result-head"><span>Item</span><span>Stock</span><span>Last rate</span></div>
                                        <button v-for="(product, i) in results" :key="product.id" type="button" :class="{ active: i === highlighted }" @mouseenter="highlighted = i" @click="choose(product)">
                                            <span><strong>{{ product.name }}</strong><small>{{ product.sku || '' }}</small></span>
                                            <span :class="{ low: Number(product.current_stock) <= 0 }">{{ product.current_stock }} {{ product.unit }}</span>
                                            <span>{{ money(product.purchase_rate) }}</span>
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </td>
                    </tr>
                </tbody>
                <tfoot v-if="items.length">
                    <tr>
                        <td></td>
                        <td>Total · {{ items.length }} {{ items.length === 1 ? 'item' : 'items' }}</td>
                        <td class="c-num">{{ sum('quantity') }}</td>
                        <td class="c-num">{{ sum('free_quantity') }}</td>
                        <td colspan="4"></td>
                        <td class="c-money">{{ totals ? money(totalOf(lineTax)) : '…' }}</td>
                        <td class="c-money strong">{{ totals ? money(totalOf((i) => line(i)?.line_total)) : '…' }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <datalist id="gst-rates"><option value="0" /><option value="5" /><option value="12" /><option value="18" /><option value="28" /><option value="40" /></datalist>
    </section>
</template>

<style scoped>
.grid-box{margin:14px 0;border:1px solid #dfe6ef;border-radius:10px}
.grid-scroll{overflow-x:auto;padding-bottom:4px}
/* leave room for the search results inside the scrolling area */
.grid-scroll.open{padding-bottom:262px}
table{width:100%;min-width:980px;border-collapse:collapse}
th{padding:8px;color:#5b6b84;background:#f6f8fc;border-bottom:1px solid #dfe6ef;font-size:11px;font-weight:800;text-align:left;text-transform:uppercase;white-space:nowrap}
td{padding:6px 8px;border-bottom:1px solid #eef2f7;color:#27344c;font-size:13px;vertical-align:middle}
input,select{width:100%;min-width:0;min-height:36px;padding:6px 8px;color:#17233b;background:#fff;border:1px solid #d8e0eb;border-radius:7px;font-size:13px}
input:focus,select:focus{border-color:#2457d6;outline:2px solid rgba(36,87,214,.15)}
.c-no{width:34px;color:#98a2b3;text-align:center}
.c-item{min-width:220px}
.c-item strong{display:block;font-size:13px}
.c-num{width:86px}
.c-unit{width:56px;color:#667085;font-size:12px}
.c-rate{width:130px}
th.c-rate select{display:block;min-height:26px;margin-top:4px;padding:2px 4px;font-size:11px;font-weight:700;text-transform:none}
.c-disc{width:140px}
.disc{display:grid;grid-template-columns:1fr 58px;gap:4px}
.c-money{width:96px;text-align:right;white-space:nowrap}
th.c-money{text-align:right}
.strong{font-weight:800}
.c-act{width:40px;text-align:center}
.req{color:#dc2626}
button.link{min-height:0;padding:0;color:#2457d6;background:none;border:0;font-size:11px;cursor:pointer}
button.remove{width:28px;height:28px;color:#c4323d;background:#fff3f4;border:1px solid #ffd6da;border-radius:7px;font-size:16px;line-height:1;cursor:pointer}
.detail-row td{background:#fbfcfe}
.details{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px}
.details label{display:grid;gap:3px;color:#667085;font-size:11px;font-weight:800}
.finder{position:relative;display:flex;gap:8px}
.finder>input{flex:1;min-height:40px;border-color:#9db7f0;background:#f8fbff}
.new-product{min-height:40px;padding:0 14px;color:#2457d6;background:#edf4ff;border:1px solid #cfe0ff;border-radius:7px;font-size:12px;font-weight:800;white-space:nowrap;cursor:pointer}
.results{position:absolute;z-index:40;top:calc(100% + 4px);left:0;right:0;max-height:250px;overflow:auto;background:#fff;border:1px solid #dce4ef;border-radius:9px;box-shadow:0 14px 34px rgba(15,34,66,.16)}
.results p{margin:0;padding:12px;color:#667085;font-size:12px}
.result-head,.results button{display:grid;grid-template-columns:minmax(0,1fr) 120px 100px;gap:10px;width:100%;padding:8px 12px;text-align:left}
.result-head{color:#98a2b3;border-bottom:1px solid #eef2f7;font-size:10px;font-weight:800;text-transform:uppercase}
.results button{align-items:center;background:#fff;border:0;border-bottom:1px solid #f1f4f8;color:#27344c;font-size:12px;cursor:pointer}
.results button.active{background:#eef4ff}
.results small{display:block;color:#98a2b3;font-size:10px}
.results .low{color:#c4323d}
tfoot td{background:#f8fafc;border-bottom:0;font-size:12px;font-weight:800}
/* phone: the item search stays on screen while the table scrolls sideways */
@media(max-width:700px){.finder{position:sticky;left:42px;flex-direction:column;width:calc(100vw - 106px)}.finder>input{flex:none;width:100%}.result-head,.results button{grid-template-columns:minmax(0,1fr) 64px 56px;gap:6px;padding:8px}.grid-scroll.open{padding-bottom:300px}.results{max-height:240px}}
</style>
