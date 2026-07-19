<template>
  <Layout page="pos" title="POS Billing">
    <section class="bill-pos-strip">
      <span>Shift: Open</span>
      <span>Register: Main Counter</span>
      <span>Invoice series: INV-2026</span>
      <span>GST mode: Inclusive</span>
    </section>

    <section class="bill-pos">
      <article class="bill-card">
        <div class="bill-card-head">
          <h3>Scan / Search Item</h3>
        </div>
        <input class="bill-scan" placeholder="Scan barcode or type product name" />
        <div class="bill-customer-row">
          <input placeholder="Customer / phone / GSTIN" />
          <button>Find</button>
        </div>
        <div class="bill-keypad">
          <button>Cash</button>
          <button>UPI</button>
          <button>Card</button>
          <button>Credit</button>
        </div>
      </article>

      <article class="bill-card">
        <div class="bill-card-head">
          <h3>Current Cart</h3>
          <span class="bill-badge">{{ cart.length }} items</span>
        </div>
        <table class="bill-table">
          <tbody>
            <tr v-for="item in cart" :key="item.name">
              <td>{{ item.name }}</td>
              <td>{{ item.qty }}</td>
              <td>{{ item.rate }}</td>
              <td>{{ item.amount }}</td>
            </tr>
          </tbody>
        </table>
        <div class="bill-total-row">
          <span>Taxable</span>
          <strong>₹580</strong>
        </div>
        <div class="bill-total-row">
          <span>GST</span>
          <strong>₹105</strong>
        </div>
        <div class="bill-total-row grand">
          <span>Grand Total</span>
          <strong>₹685</strong>
        </div>
        <button class="bill-primary full">Complete Sale</button>
      </article>
    </section>
  </Layout>
</template>

<script setup>
import Layout from './Layout.vue';

defineProps({
  cart: { type: Array, default: () => [] },
});
</script>
