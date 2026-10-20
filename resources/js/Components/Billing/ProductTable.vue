<template>
  <section
    class="bill-ui-card bill-product-table-card"
    :class="{ 'is-empty': !items.length }"
  >
    <header class="product-card-head">
      <div>
        <span class="product-eyebrow">PRODUCTS</span>
        <h2>Product Entry</h2>
      </div>

      <slot name="actions" />
    </header>

    <div v-if="!items.length" class="empty-state">
      <div class="empty-icon">▣</div>
      <strong>No products added</strong>
      <span>Scan a barcode or search a product to start billing.</span>
    </div>

    <div v-else class="product-list">
      <article
        v-for="(item, index) in items"
        :key="`${item.product_id}-${item.product_variant_id || 0}-${item.batch_id || 0}-${index}`"
        class="product-card"
        :class="{
          'scan-highlight':
            highlightKey &&
            rowKey(item) === highlightKey
        }"
      >
        <!-- TOP ROW -->
        <div class="product-top">
          <!-- Product -->
          <div class="product-identity">
            <div class="product-image">
              <img
                v-if="item.image_url"
                :src="item.image_url"
                :alt="item.product"
              />

              <span v-else>
                {{ initials(item.product) }}
              </span>
            </div>

            <div class="product-copy">
              <div class="product-title-row">
                <strong class="product-title">
                  {{ item.product }}
                </strong>

                <span class="unit-chip">
                  {{ item.unit || 'PCS' }}
                </span>
              </div>

              <div class="product-meta-line">
                <span>
                  SKU: {{ item.sku || 'No SKU' }}
                </span>

                <span v-if="item.hsn_code">
                  HSN {{ item.hsn_code }}
                </span>

                <span
                  v-if="
                    item.gst_rate !== null &&
                    item.gst_rate !== undefined
                  "
                >
                  GST {{ money(item.gst_rate) }}%
                </span>
              </div>

              <slot name="serials" :item="item" />
              <div class="product-status-line">
                <span
                  v-if="
                    item.available_stock !== null &&
                    item.available_stock !== undefined
                  "
                  class="stock-chip"
                >
                  {{ item.available_stock }} in stock
                </span>

                <span
                  v-if="item.previous_purchase"
                  class="previous-purchase"
                >
                  Last purchase
                  ₹{{ money(item.previous_purchase.selling_rate) }}
                  ·
                  {{ dateText(item.previous_purchase.invoice_date) }}
                </span>
              </div>
            </div>
          </div>

          <!-- Controls -->
          <div class="product-actions">
            <!-- Quantity -->
            <div class="field-block qty-block">
              <span class="field-label">
                Qty
              </span>

              <div class="qty-control">
                <button
                  type="button"
                  title="Decrease quantity"
                  @mousedown.prevent
                  @click.prevent.stop="$emit('decrement', item)"
                >
                  −
                </button>

                <input
                  v-model.number="item.quantity"
                  type="number"
                  min="1"
                  :max="item.available_stock || undefined"
                  @keydown.enter.prevent
                  @input="$emit('quantity-change', item)"
                />

                <button
                  type="button"
                  title="Increase quantity"
                  @mousedown.prevent
                  @click.prevent.stop="$emit('increment', item)"
                >
                  +
                </button>
              </div>
            </div>

            <!-- Rate -->
            <div class="field-block rate-block">
              <span class="field-label">
                Rate
              </span>

              <div class="money-input">
                <span>₹</span>

                <input
                  v-model.number="item.selling_rate"
                  type="number"
                  min="0"
                  @input="$emit('change')"
                />
              </div>

              <small v-if="Number(item.mrp || 0)">
                MRP
                <del>
                  ₹{{ money(item.mrp) }}
                </del>
              </small>
            </div>

            <!-- Total -->
            <div class="line-total-block">
              <span>
                Line Total
              </span>

              <strong>
                {{ lineDetails(item).total }}
              </strong>

              <small
                v-if="savingAmount(item) > 0"
                class="saving-text"
              >
                Save ₹{{ money(savingAmount(item)) }}

                <template v-if="savingPercent(item)">
                  ({{ savingPercent(item) }}%)
                </template>
              </small>
            </div>

            <!-- Delete -->
            <button
              type="button"
              class="delete-button"
              title="Remove product"
              @click="$emit('remove', index)"
            >
              ×
            </button>
          </div>
        </div>

        <!-- BOTTOM ROW -->
        <div class="product-bottom">
          <!-- Batch / Inventory -->
          <div class="batch-inline">
            <!-- Batch Product -->
            <template v-if="(item.batches || []).length">
              <div class="batch-label-row">
                <span class="field-label">
                  Batch / Expiry
                </span>

                <span
                  v-if="!item.batch_id"
                  class="fefo-badge"
                >
                  FEFO AUTO
                </span>
              </div>

              <select
                v-model="item.batch_id"
                class="batch-select"
                title="Select batch"
                @change="$emit('batch-change', item)"
              >
                <option value="">
                  Automatic FEFO
                </option>

                <option
                  v-for="batch in item.batches || []"
                  :key="batch.id"
                  :value="batch.id"
                >
                  {{ batch.batch_no }}
                  · {{ batch.available_stock }} available
                  <template v-if="batch.expiry_date">
                    · Exp {{ dateText(batch.expiry_date) }}
                  </template>
                </option>
              </select>
            </template>

            <!-- Standard Stock Product -->
            <template v-else>
              <div class="batch-label-row">
                <span class="field-label">
                  Inventory
                </span>
              </div>

              <div class="standard-stock">
                Standard stock item
              </div>
            </template>
          </div>

          <!-- Allocation Preview -->
          <div
            v-if="
              (item.batches || []).length &&
              allocationPreview(item).length
            "
            class="allocation-summary"
          >
            <div
              v-for="batch in allocationPreview(item)"
              :key="batch.id"
              class="allocation-chip"
            >
              <div>
                <strong>
                  {{ batch.batch_no }}
                </strong>

                <span v-if="batch.expiry_date">
                  Exp {{ shortDate(batch.expiry_date) }}
                </span>
              </div>

              <div class="allocation-count">
                <span>
                  Allocate
                </span>

                <strong>
                  {{ batch.allocated }}
                  /
                  {{ batch.available_stock }}
                </strong>
              </div>
            </div>
          </div>

          <!-- Empty spacer for non-batch products -->
          <div
            v-else
            class="allocation-empty"
          ></div>

          <!-- Financial -->
          <div class="financial-inline">
            <!-- Discount -->
            <div class="discount-field">
              <span class="field-label">
                Line Discount
              </span>

              <div class="money-input compact">
                <span>₹</span>

                <input
                  v-model.number="item.discount_value"
                  type="number"
                  min="0"
                  placeholder="0"
                  @input="$emit('change')"
                />
              </div>
            </div>

            <!-- Taxable -->
            <div class="metric-box">
              <span>
                Taxable
              </span>

              <strong>
                {{ lineDetails(item).taxable }}
              </strong>
            </div>

            <!-- GST -->
            <div class="metric-box">
              <span>
                GST
              </span>

              <strong>
                {{ lineDetails(item).gstAmount }}
              </strong>
            </div>
          </div>
        </div>
      </article>
    </div>

    <footer class="product-table-footer">
      <slot name="footer" />
    </footer>
  </section>
</template>

<script setup>
import { formatDate } from '../../Support/dateFormat.js';
const props = defineProps({
  items: {
    type: Array,
    default: () => []
  },

  lineTotal: {
    type: Function,
    required: true
  },

  lineDetails: {
    type: Function,
    default: null
  },

  highlightKey: {
    type: String,
    default: ''
  }
});

defineEmits([
  'increment',
  'decrement',
  'remove',
  'change',
  'quantity-change',
  'batch-change'
]);

const allocationPreview = (item) => {
  let remaining =
    Number(item.quantity || 0) +
    Number(item.free_quantity || 0);

  return (item.batches || [])
    .filter(
      (batch) =>
        !item.batch_id ||
        Number(batch.id) === Number(item.batch_id)
    )
    .map((batch) => {
      const allocated = Math.min(
        remaining,
        Number(batch.available_stock || 0)
      );

      remaining = Math.max(
        0,
        remaining - allocated
      );

      return {
        ...batch,
        allocated
      };
    })
    .filter(
      (batch) =>
        batch.allocated > 0 ||
        item.batch_id
    );
};

const initials = (name = '') =>
  String(name)
    .split(' ')
    .map((part) => part[0])
    .join('')
    .slice(0, 2)
    .toUpperCase() || 'BI';

const rowKey = (item) =>
  `${item.product_id}-${item.product_variant_id || 0}-${item.batch_id || 0}`;

const money = (value) =>
  Number(value || 0).toLocaleString(
    'en-IN',
    {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    }
  );

const savingAmount = (item) =>
  Math.max(
    0,
    (
      Number(item.mrp || 0) -
      Number(item.selling_rate || 0)
    ) *
    Number(item.quantity || 0)
  );

const savingPercent = (item) => {
  const mrp = Number(item.mrp || 0);

  if (mrp <= 0) {
    return '';
  }

  const percent = Math.max(
    0,
    (
      (
        mrp -
        Number(item.selling_rate || 0)
      ) /
      mrp
    ) * 100
  );

  return percent > 0
    ? percent.toFixed(2)
    : '';
};

const dateText = formatDate;
const shortDate = formatDate;

const lineDetails = (item) => {
  if (
    typeof props.lineDetails === 'function'
  ) {
    return props.lineDetails(item);
  }

  return {
    taxable: props.lineTotal(item),
    gstAmount: '₹0.00',
    total: props.lineTotal(item)
  };
};
</script>

<style scoped>
.bill-product-table-card {
  overflow: hidden;
  border: 1px solid #e4eaf2;
  border-radius: 12px;
  background: #ffffff;
  box-shadow:
    0 10px 30px rgba(15, 23, 42, 0.05);
}

.product-card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;

  padding:
    16px
    18px
    14px;

  border-bottom:
    1px solid #edf1f6;

  background:
    linear-gradient(
      180deg,
      #ffffff 0%,
      #fbfdff 100%
    );
}

.product-eyebrow {
  display: block;

  margin-bottom: 3px;

  color: #2563eb;

  font-size: 9px;
  font-weight: 950;

  letter-spacing: 0.14em;
}

.product-card-head h2 {
  margin: 0;

  color: #0f172a;

  font-size: 17px;
  font-weight: 950;
}

.product-list {
  display: grid;

  gap: 10px;

  padding: 12px;

  background: #f8fafc;
}

.product-card {
  overflow: hidden;

  border: 1px solid #e5eaf1;
  border-radius: 12px;

  background: #ffffff;

  box-shadow:
    0 1px 2px rgba(15, 23, 42, 0.03);
}

.product-top {
  display: grid;

  grid-template-columns:
    minmax(0, 1fr)
    auto;

  gap: 18px;

  align-items: center;

  padding:
    14px
    16px
    12px;
}

.product-identity {
  display: flex;

  min-width: 0;

  gap: 12px;

  align-items: center;
}

.product-image {
  display: grid;
  place-items: center;

  width: 52px;
  height: 52px;

  flex: 0 0 52px;

  overflow: hidden;

  border:
    1px solid #e3eaf3;

  border-radius: 10px;

  background: #ffffff;
}

.product-image img {
  width: 100%;
  height: 100%;

  object-fit: contain;

  background: #ffffff;
}

.product-image span {
  color: #2563eb;

  font-size: 13px;
  font-weight: 950;
}

.product-copy {
  min-width: 0;
}

.product-title-row {
  display: flex;
  align-items: center;

  gap: 8px;

  min-width: 0;
}

.product-title {
  overflow: hidden;

  color: #0f172a;

  font-size: 14px;
  font-weight: 950;
  line-height: 1.25;

  text-overflow: ellipsis;
  white-space: nowrap;
}

.unit-chip {
  flex: 0 0 auto;

  padding:
    3px
    7px;

  border-radius: 999px;

  background: #f1f5f9;

  color: #475569;

  font-size: 8px;
  font-weight: 900;
}

.product-meta-line {
  display: flex;
  flex-wrap: wrap;

  gap:
    4px
    8px;

  margin-top: 5px;

  color: #64748b;

  font-size: 8px;
  font-weight: 760;
}

.product-meta-line span + span::before {
  content: '•';

  margin-right: 8px;

  color: #cbd5e1;
}

.product-status-line {
  display: flex;
  flex-wrap: wrap;
  align-items: center;

  gap: 7px;

  margin-top: 6px;
}

.stock-chip {
  padding:
    3px
    7px;

  border-radius: 999px;

  background: #ecfdf5;

  color: #047857;

  font-size: 8px;
  font-weight: 950;
}

.previous-purchase {
  color: #2563eb;

  font-size: 8px;
  font-weight: 760;
}

.product-actions {
  display: grid;

  grid-template-columns:
    120px
    104px
    112px
    30px;

  gap: 10px;

  align-items: end;
}

.field-label,
.line-total-block > span,
.metric-box > span {
  display: block;

  margin-bottom: 4px;

  color: #64748b;

  font-size: 7px;
  font-weight: 950;

  letter-spacing: 0.04em;

  text-transform: uppercase;
}

.qty-control {
  display: grid;

  grid-template-columns:
    32px
    48px
    32px;

  gap: 4px;
}

.qty-control button {
  width: 32px;
  height: 34px;

  padding: 0;

  border:
    1px solid #dbe3ef;

  border-radius: 8px;

  background: #ffffff;

  color: #334155;

  cursor: pointer;

  font-size: 16px;
  font-weight: 950;
}

.qty-control button:hover {
  border-color: #93c5fd;

  background: #eff6ff;

  color: #2563eb;
}

.qty-control input {
  width: 48px;
  height: 34px;

  padding:
    0
    4px;

  border:
    1px solid #dbe3ef;

  border-radius: 8px;

  background: #ffffff;

  color: #0f172a;

  text-align: center;

  font-size: 12px;
  font-weight: 950;
}

.money-input {
  display: flex;
  align-items: center;

  height: 34px;

  padding:
    0
    8px;

  border:
    1px solid #dbe3ef;

  border-radius: 8px;

  background: #ffffff;
}

.money-input:focus-within {
  border-color: #93c5fd;

  box-shadow:
    0 0 0 3px rgba(59, 130, 246, 0.08);
}

.money-input > span {
  margin-right: 5px;

  color: #94a3b8;

  font-size: 9px;
  font-weight: 900;
}

.money-input input {
  width: 100%;
  min-width: 0;

  border: 0;
  outline: 0;

  background: transparent;

  color: #0f172a;

  font-size: 11px;
  font-weight: 950;
}

.rate-block small {
  display: block;

  margin-top: 3px;

  color: #94a3b8;

  font-size: 7px;
  font-weight: 750;
}

.line-total-block {
  text-align: right;
}

.line-total-block > strong {
  display: block;

  color: #0f172a;

  font-size: 18px;
  font-weight: 950;
  line-height: 1.05;
}

.saving-text {
  display: block;

  margin-top: 3px;

  color: #059669;

  font-size: 7px;
  font-weight: 900;

  white-space: nowrap;
}

.delete-button {
  display: grid;
  place-items: center;

  width: 30px;
  height: 30px;

  padding: 0;

  border:
    1px solid #fecdd3;

  border-radius: 999px;

  background: #fff1f2;

  color: #e11d48;

  cursor: pointer;

  font-size: 16px;
  font-weight: 900;
}

.delete-button:hover {
  border-color: #fda4af;

  background: #ffe4e6;
}

/* Bottom row */
.product-bottom {
  display: grid;

  grid-template-columns:
    minmax(300px, 1.3fr)
    minmax(150px, 0.48fr)
    minmax(280px, 0.8fr);

  gap: 12px;

  align-items: end;

  padding:
    10px
    16px
    12px;

  border-top:
    1px dashed #e2e8f0;

  background: #fcfdff;
}

.batch-inline,
.financial-inline,
.allocation-summary {
  min-width: 0;
}

.batch-label-row {
  display: flex;
  align-items: center;

  gap: 7px;
}

.fefo-badge {
  display: inline-flex;
  align-items: center;

  min-height: 16px;

  padding:
    1px
    6px;

  border-radius: 999px;

  background: #ecfdf5;

  color: #047857;

  font-size: 6px;
  font-weight: 950;

  letter-spacing: 0.03em;
}

.batch-select {
  width: 100%;
  height: 34px;

  padding:
    0
    9px;

  border:
    1px solid #dbe3ef;

  border-radius: 8px;

  background: #ffffff;

  color: #334155;

  font-size: 9px;
  font-weight: 800;
}

.batch-select:focus {
  border-color: #93c5fd;

  outline: 0;

  box-shadow:
    0 0 0 3px rgba(59, 130, 246, 0.08);
}

.standard-stock {
  display: inline-flex;
  align-items: center;

  min-height: 34px;

  padding:
    0
    10px;

  border-radius: 8px;

  background: #f8fafc;

  color: #64748b;

  font-size: 8px;
  font-weight: 800;
}

.allocation-chip {
  display: flex;
  align-items: center;
  justify-content: space-between;

  min-height: 34px;

  gap: 10px;

  padding:
    6px
    8px;

  border:
    1px solid #dbeafe;

  border-radius: 8px;

  background: #f8fbff;
}

.allocation-chip strong {
  display: block;

  color: #334155;

  font-size: 8px;
  font-weight: 950;
}

.allocation-chip span {
  display: block;

  margin-top: 1px;

  color: #94a3b8;

  font-size: 6px;
  font-weight: 800;
}

.allocation-count {
  text-align: right;
}

.allocation-count strong {
  color: #2563eb;
}

.allocation-empty {
  min-width: 0;
}

.financial-inline {
  display: grid;

  grid-template-columns:
    112px
    1fr
    1fr;

  gap: 8px;

  align-items: end;
}

.money-input.compact {
  height: 34px;
}

.metric-box {
  min-width: 0;
  min-height: 34px;

  padding:
    6px
    8px;

  border:
    1px solid #edf1f5;

  border-radius: 8px;

  background: #ffffff;
}

.metric-box > span {
  margin-bottom: 1px;
}

.metric-box > strong {
  display: block;

  overflow: hidden;

  color: #334155;

  font-size: 9px;
  font-weight: 950;

  text-overflow: ellipsis;
  white-space: nowrap;
}

.product-table-footer {
  display: grid;

  grid-template-columns:
    auto
    1fr
    auto;

  align-items: center;

  gap: 12px;

  padding:
    10px
    14px;

  border-top:
    1px solid #e8edf4;

  background: #ffffff;
}

.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;

  min-height: 180px;

  padding: 28px;

  text-align: center;
}

.empty-icon {
  display: grid;
  place-items: center;

  width: 46px;
  height: 46px;

  margin-bottom: 10px;

  border-radius: 12px;

  background: #eff6ff;

  color: #2563eb;

  font-size: 19px;
}

.empty-state strong {
  color: #0f172a;

  font-size: 13px;
  font-weight: 950;
}

.empty-state span {
  margin-top: 4px;

  color: #64748b;

  font-size: 10px;
}

.scan-highlight {
  animation:
    scan-card-highlight
    1.15s
    ease-out;
}

@keyframes scan-card-highlight {
  0% {
    border-color: #86efac;

    box-shadow:
      0 0 0 3px rgba(34, 197, 94, 0.12);
  }

  100% {
    border-color: #e5eaf1;

    box-shadow: none;
  }
}

@media (max-width: 1180px) {
  .product-top {
    grid-template-columns: 1fr;
  }

  .product-actions {
    grid-template-columns:
      120px
      104px
      minmax(100px, 1fr)
      30px;
  }

  .product-bottom {
    grid-template-columns: 1fr;
  }

  .allocation-empty {
    display: none;
  }

  .financial-inline {
    max-width: 360px;
  }
}

@media (max-width: 760px) {
  .product-card-head,
  .product-top,
  .product-bottom {
    padding-left: 12px;
    padding-right: 12px;
  }

  .product-identity {
    align-items: flex-start;
  }

  .product-actions {
    grid-template-columns:
      1fr
      1fr;
  }

  .line-total-block {
    text-align: left;
  }

  .delete-button {
    justify-self: end;
  }

  .financial-inline {
    grid-template-columns: 1fr;

    max-width: none;
  }

  .product-table-footer {
    grid-template-columns: 1fr;
  }
}
</style>