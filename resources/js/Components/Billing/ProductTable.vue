<template>
  <section class="bill-ui-card bill-product-table-card">
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
            <th>Stock</th>
            <th>Batch</th>
            <th>Qty</th>
            <th>Rate</th>
            <th>Discount</th>
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
                  <small>{{ item.sku || 'No SKU' }} <span v-if="item.barcode">- {{ item.barcode }}</span></small>
                </div>
              </div>
            </td>
            <td><span class="bill-stock-pill">{{ item.available_stock ?? 'Service' }}</span></td>
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
            <td><input class="bill-row-input" v-model.number="item.selling_rate" type="number" min="0" placeholder="Rate" @input="$emit('change')" /></td>
            <td><input class="bill-row-input" v-model.number="item.discount_value" type="number" min="0" placeholder="Discount" @input="$emit('change')" /></td>
            <td><strong class="bill-line-total">{{ lineTotal(item) }}</strong></td>
            <td><button class="bill-delete-button" type="button" title="Remove product" @click="$emit('remove', index)">Remove</button></td>
          </tr>
          <tr v-if="!items.length">
            <td colspan="8" class="bill-empty-row">Scan barcode or search a product to add invoice lines.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

<script setup>
defineProps({
  items: { type: Array, default: () => [] },
  lineTotal: { type: Function, required: true },
  highlightKey: { type: String, default: '' },
});

defineEmits(['increment', 'decrement', 'remove', 'change', 'quantity-change', 'batch-change']);

const initials = (name = '') => String(name).split(' ').map((part) => part[0]).join('').slice(0, 2).toUpperCase() || 'BI';
const rowKey = (item) => `${item.product_id}-${item.product_variant_id || 0}-${item.batch_id || 0}`;
</script>

<style scoped>
.bill-product-table {
  min-width: 980px;
}

.bill-product-table th,
.bill-product-table td {
  vertical-align: middle;
}

.bill-product-cell {
  min-width: 260px;
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

.bill-row-select,
.bill-row-input {
  width: 100%;
  min-height: 42px;
}

.bill-qty-control {
  display: grid;
  grid-template-columns: 42px 70px 42px;
  gap: 6px;
  align-items: center;
}

.bill-qty-control button {
  width: 42px;
  height: 42px;
  border-radius: 8px;
  font-size: 18px;
  font-weight: 900;
}

.bill-qty-control input {
  width: 70px;
  min-height: 42px;
  text-align: center;
  font-size: 16px;
  font-weight: 900;
}

.bill-line-total {
  white-space: nowrap;
  color: #142139;
}

.bill-delete-button {
  min-height: 38px;
  padding: 0 12px;
}

.scan-highlight td {
  animation: scan-row-pulse 1.2s ease-out;
}

@keyframes scan-row-pulse {
  0% { background: #dcfce7; }
  100% { background: transparent; }
}
</style>
