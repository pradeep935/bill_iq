<template>
  <section class="bill-ui-card bill-payment-panel" id="payment-panel">
    <div class="bill-ui-card-head">
      <div>
        <span>PAYMENT</span>
        <h2>Payment Summary</h2>
      </div>
      <strong class="bill-payment-total">{{ grandTotal }}</strong>
    </div>
    <div class="bill-payment-methods">
      <button
        v-for="mode in modes"
        :key="mode.value"
        type="button"
        :class="{ active: mode.value === paymentMode }"
        :title="`Use ${mode.label}`"
        @click="$emit('update:paymentMode', mode.value)"
      >
        {{ mode.label }}
      </button>
    </div>
    <p class="bill-payment-hint">
      <template v-if="paymentMode === 'credit'">Unpaid: full amount is added to the customer's outstanding.</template>
      <template v-else-if="paymentMode === 'partial'">Part paid: enter the amount received now; the balance stays outstanding.</template>
      <template v-else>Paid in full.</template>
    </p>
    <div v-if="paymentMode !== 'credit'" class="bill-payment-lines">
      <div v-for="(payment, index) in payments" :key="index" class="bill-payment-line">
        <select v-model="payment.payment_method_id" title="Payment method" aria-label="Payment method">
          <option v-for="method in methods" :key="method.id" :value="method.id">{{ method.name }}</option>
        </select>
        <input v-model.number="payment.amount" type="number" min="0" placeholder="Received amount" title="Received amount" aria-label="Received amount" />
      </div>
    </div>
    <div class="bill-payment-balance">
      <div><span>Received</span><strong>{{ received }}</strong></div>
      <div><span>Balance</span><strong>{{ balance }}</strong></div>
      <div><span>Change</span><strong>{{ change }}</strong></div>
    </div>
    <slot name="actions" />
  </section>
</template>

<script setup>
defineProps({
  paymentMode: { type: String, default: 'cash' },
  payments: { type: Array, default: () => [] },
  methods: { type: Array, default: () => [] },
  grandTotal: { type: String, default: '₹0.00' },
  received: { type: String, default: '₹0.00' },
  balance: { type: String, default: '₹0.00' },
  change: { type: String, default: '₹0.00' },
});

defineEmits(['update:paymentMode']);

const modes = [
  { value: 'cash', label: 'Cash' },
  { value: 'upi', label: 'UPI' },
  { value: 'card', label: 'Card' },
  { value: 'split', label: 'Split Payment' },
  { value: 'partial', label: 'Part Paid' },
  { value: 'credit', label: 'Unpaid' },
];
</script>

<style scoped>
.bill-payment-hint{margin:8px 0 4px;color:#667085;font-size:12px}
</style>
