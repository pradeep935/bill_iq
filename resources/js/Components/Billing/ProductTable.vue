<template>
  <section class="bill-ui-card bill-product-table-card" :class="{ 'is-empty': !items.length }">
    <div class="bill-ui-card-head">
      <div>
        <span>PRODUCTS</span>
        <h2>Product Entry</h2>
      </div>
      <slot name="actions" />
    </div>
    <div class="bill-product-table-wrap">
      <table class="bill-product-table">
        <thead>
          <tr>
            <th>Product</th>
            <th>HSN/SAC</th>
            <th>Batch</th>
            <th>Qty</th>
            <th>Unit</th>
            <th>MRP</th>
            <th>Rate</th>
            <th>Saving</th>
            <th>Disc.</th>
            <th>GST %</th>
            <th>Total</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(item, index) in items" :key="`${item.product_id}-${item.product_variant_id || 0}-${item.batch_id || 0}-${index}`" :class="{ 'scan-highlight': highlightKey && rowKey(item) === highlightKey }">
            <td class="bill-product-cell">
              <div class="bill-product-info">
                <div class="bill-product-image">
                  <img v-if="item.image_url" :src="item.image_url" :alt="item.product" />
                  <span v-else>{{ initials(item.product) }}</span>
                </div>
                <div>
                  <strong>{{ item.product }}</strong>
                  <small>SKU: {{ item.sku || 'No SKU' }} <span v-if="item.barcode">- {{ item.barcode }}</span></small>
                  <small v-if="item.available_stock !== null && item.available_stock !== undefined" class="bill-stock-text">In Stock: {{ item.available_stock }}</small>
                  <small v-if="item.previous_purchase" class="bill-previous-purchase">
                    Last purchased at Rs. {{ money(item.previous_purchase.selling_rate) }} on {{ dateText(item.previous_purchase.invoice_date) }}
                  </small>
                </div>
              </div>
            </td>
            <td><span class="bill-stock-pill">{{ item.hsn_code || '-' }}</span></td>
            <td>
              <select class="bill-row-select" v-model="item.batch_id" title="Select batch" @change="$emit('batch-change', item)">
                <option value="">Batch</option>
                <option v-for="batch in item.batches || []" :key="batch.id" :value="batch.id">{{ batch.batch_no }}{{ batch.expiry_date ? ` | ${batch.expiry_date}` : '' }}</option>
              </select>
            </td>
            <td>
              <div class="bill-qty-control">
                <button type="button" title="Decrease quantity" @mousedown.prevent @click.prevent.stop="$emit('decrement', item)">-</button>
                <input v-model.number="item.quantity" type="number" min="1" :max="item.available_stock || undefined" placeholder="Qty" @keydown.enter.prevent @input="$emit('quantity-change', item)" />
                <button type="button" title="Increase quantity" @mousedown.prevent @click.prevent.stop="$emit('increment', item)">+</button>
              </div>
            </td>
            <td><span class="bill-unit-pill">{{ item.unit || 'PCS' }}</span></td>
            <td><span class="bill-mrp-text" :class="{ muted: !Number(item.mrp || 0) }">{{ priceText(item.mrp || item.selling_rate) }}</span></td>
            <td><input class="bill-row-input rate-input" v-model.number="item.selling_rate" type="number" min="0" placeholder="Rate" @input="$emit('change')" /></td>
            <td><span class="bill-saving-text">{{ savingText(item) }}</span><small v-if="savingPercent(item)" class="bill-saving-percent">({{ savingPercent(item) }}%)</small></td>
            <td><input class="bill-row-input" v-model.number="item.discount_value" type="number" min="0" placeholder="Discount" @input="$emit('change')" /></td>
            <td><span class="bill-gst-pill">{{ money(item.gst_rate) }}%</span></td>
            <td><strong class="bill-line-total">{{ lineDetails(item).total }}</strong></td>
            <td><button class="bill-delete-button" type="button" title="Remove product" @click="$emit('remove', index)">×</button></td>
          </tr>
          <tr v-if="!items.length">
            <td colspan="12" class="bill-empty-row">Scan barcode or search a product to add invoice lines.</td>
          </tr>
        </tbody>
      </table>
    </div>
    <div class="bill-product-table-footer">
      <slot name="footer" />
    </div>
  </section>
</template>

<script setup>
const props = defineProps({
  items: { type: Array, default: () => [] },
  lineTotal: { type: Function, required: true },
  lineDetails: { type: Function, default: null },
  highlightKey: { type: String, default: '' },
});

defineEmits(['increment', 'decrement', 'remove', 'change', 'quantity-change', 'batch-change']);

const initials = (name = '') => String(name).split(' ').map((part) => part[0]).join('').slice(0, 2).toUpperCase() || 'BI';
const rowKey = (item) => `${item.product_id}-${item.product_variant_id || 0}-${item.batch_id || 0}`;
const money = (value) => Number(value || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const priceText = (value) => `₹${money(value)}`;
const savingAmount = (item) => Math.max(0, (Number(item.mrp || 0) - Number(item.selling_rate || 0)) * Number(item.quantity || 0));
const savingText = (item) => savingAmount(item) > 0 ? `₹${money(savingAmount(item))}` : '-';
const savingPercent = (item) => {
  const mrp = Number(item.mrp || 0);
  if (mrp <= 0) return '';
  const percent = Math.max(0, (mrp - Number(item.selling_rate || 0)) / mrp * 100);
  return percent > 0 ? percent.toFixed(2) : '';
};
const dateText = (value) => value ? new Date(value).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '-';
const lineDetails = (item) => {
  if (typeof props.lineDetails === 'function') return props.lineDetails(item);
  return { taxable: props.lineTotal(item), gstAmount: 'Rs. 0.00', total: props.lineTotal(item) };
};
</script>

<style scoped>
.bill-product-table {
  min-width: 960px;
  table-layout: fixed;
}

.bill-product-table th:nth-child(1) { width: 230px; }
.bill-product-table th:nth-child(2) { width: 78px; }
.bill-product-table th:nth-child(3) { width: 54px; }
.bill-product-table th:nth-child(4) { width: 132px; }
.bill-product-table th:nth-child(5) { width: 54px; }
.bill-product-table th:nth-child(6) { width: 76px; }
.bill-product-table th:nth-child(7) { width: 76px; }
.bill-product-table th:nth-child(8) { width: 88px; }
.bill-product-table th:nth-child(9) { width: 64px; }
.bill-product-table th:nth-child(10) { width: 54px; }
.bill-product-table th:nth-child(11) { width: 86px; }
.bill-product-table th:nth-child(12) { width: 34px; }

.bill-product-table th,
.bill-product-table td {
  vertical-align: middle;
}

.bill-product-cell {
  min-width: 0;
}

.bill-product-info {
  display: grid;
  grid-template-columns: 46px minmax(0, 1fr);
  gap: 10px;
  align-items: center;
}

.bill-product-info strong {
  display: block;
  color: #142139;
  font-size: 14px;
  line-height: 1.2;
}

.bill-product-info small {
  display: block;
  margin-top: 3px;
  color: #64748b;
  font-size: 12px;
  line-height: 1.25;
  word-break: break-word;
}

.bill-stock-text,
.bill-saving-text,
.bill-saving-percent {
  color: #078044 !important;
  font-weight: 900;
}

.bill-previous-purchase {
  color: #2457d6 !important;
  font-weight: 800;
}

.bill-product-image {
  width: 42px;
  height: 42px;
  border-radius: 8px;
}

.bill-product-image img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.bill-product-image span {
  display: grid;
  width: 100%;
  height: 100%;
  place-items: center;
}

.bill-stock-pill {
  display: inline-flex;
  align-items: center;
  min-height: 34px;
  padding: 6px 10px;
  border-radius: 999px;
  background: #eef6ff;
  color: #2457d6;
  font-size: 13px;
  font-weight: 900;
}

.bill-unit-pill,
.bill-gst-pill {
  display: inline-flex;
  align-items: center;
  min-height: 32px;
  padding: 5px 9px;
  border-radius: 8px;
  background: #f8fafc;
  color: #334155;
  font-size: 12px;
  font-weight: 900;
  white-space: nowrap;
}

.bill-row-select,
.bill-row-input {
  width: 100%;
  min-height: 38px;
}

.rate-input {
  max-width: none;
  font-weight: 900;
}

.bill-mrp-text {
  color: #64748b;
  font-size: 12px;
  font-weight: 850;
  text-decoration: line-through;
}

.bill-mrp-text.muted {
  text-decoration: none;
}

.bill-saving-text,
.bill-saving-percent {
  display: block;
  white-space: nowrap;
}

.bill-saving-percent {
  margin-top: 2px;
  font-size: 10px;
}

.bill-qty-control {
  display: grid;
  grid-template-columns: 34px 56px 34px;
  gap: 4px;
  align-items: center;
}

.bill-qty-control button {
  width: 34px;
  height: 38px;
  border-radius: 8px;
  font-size: 18px;
  font-weight: 900;
}

.bill-qty-control input {
  width: 56px;
  min-height: 38px;
  text-align: center;
  font-size: 16px;
  font-weight: 900;
}

.bill-line-total {
  white-space: nowrap;
  color: #142139;
}

.bill-delete-button {
  min-height: 30px;
  width: 30px;
  padding: 0;
  border-radius: 999px;
  font-size: 18px;
  line-height: 1;
}

.bill-product-table-footer {
  display: grid;
  grid-template-columns: auto 1fr auto;
  align-items: center;
  gap: 12px;
}

.scan-highlight td {
  animation: scan-row-pulse 1.2s ease-out;
}

@keyframes scan-row-pulse {
  0% { background: #dcfce7; }
  100% { background: transparent; }
}
</style>
