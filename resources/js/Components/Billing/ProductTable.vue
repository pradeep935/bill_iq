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
            <th>Image</th>
            <th>Product Name</th>
            <th>SKU</th>
            <th>Barcode</th>
            <th>Available Stock</th>
            <th>Batch</th>
            <th>Qty</th>
            <th>Unit</th>
            <th>Rate</th>
            <th>Discount</th>
            <th>GST</th>
            <th>Total</th>
            <th>Delete</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(item, index) in items" :key="`${item.product_id}-${item.product_variant_id || 0}-${item.batch_id || 0}-${index}`" :class="{ 'scan-highlight': highlightKey && rowKey(item) === highlightKey }">
            <td>
              <div class="bill-product-image">
                <img v-if="item.image_url" :src="item.image_url" :alt="item.product" />
                <span v-else>{{ initials(item.product) }}</span>
              </div>
            </td>
            <td><strong>{{ item.product }}</strong></td>
            <td>{{ item.sku || '-' }}</td>
            <td>{{ item.barcode || '-' }}</td>
            <td>{{ item.available_stock ?? 'Service' }}</td>
            <td>
              <select v-model="item.batch_id" title="Select batch">
                <option value="">Batch</option>
                <option v-for="batch in item.batches || []" :key="batch.id" :value="batch.id">{{ batch.batch_no }}{{ batch.expiry_date ? ` | ${batch.expiry_date}` : '' }}</option>
              </select>
            </td>
            <td>
              <div class="bill-qty-control">
                <button type="button" title="Decrease quantity" @click="$emit('decrement', item)">-</button>
                <input v-model.number="item.quantity" type="number" min="1" :max="item.available_stock || undefined" placeholder="Qty" @input="$emit('quantity-change', item)" />
                <button type="button" title="Increase quantity" @click="$emit('increment', item)">+</button>
              </div>
            </td>
            <td><input v-model="item.unit_id" placeholder="Unit" title="Unit" @input="$emit('change')" /></td>
            <td><input v-model.number="item.selling_rate" type="number" min="0" placeholder="Rate" @input="$emit('change')" /></td>
            <td><input v-model.number="item.discount_value" type="number" min="0" placeholder="Discount" @input="$emit('change')" /></td>
            <td><input v-model.number="item.gst_rate" type="number" min="0" max="100" placeholder="GST %" @input="$emit('change')" /></td>
            <td><strong>{{ lineTotal(item) }}</strong></td>
            <td><button class="bill-delete-button" type="button" title="Delete product" @click="$emit('remove', index)">Delete</button></td>
          </tr>
          <tr v-if="!items.length">
            <td colspan="13" class="bill-empty-row">Scan barcode or search a product to add invoice lines.</td>
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

defineEmits(['increment', 'decrement', 'remove', 'change', 'quantity-change']);

const initials = (name = '') => String(name).split(' ').map((part) => part[0]).join('').slice(0, 2).toUpperCase() || 'BI';
const rowKey = (item) => `${item.product_id}-${item.product_variant_id || 0}-${item.batch_id || 0}`;
</script>

<style scoped>
.scan-highlight td {
  animation: scan-row-pulse 1.2s ease-out;
}

@keyframes scan-row-pulse {
  0% { background: #dcfce7; }
  100% { background: transparent; }
}
</style>
