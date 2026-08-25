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
    <div v-if="paymentMode !== 'credit'" class="bill-payment-lines">
      <div v-for="(payment, index) in payments" :key="index" class="bill-payment-line">
        <select v-model="payment.payment_method_id" title="Payment method">
          <option v-for="method in methods" :key="method.id" :value="method.id">{{ method.name }}</option>
        </select>
        <input v-model.number="payment.amount" type="number" min="0" placeholder="Received amount" title="Received amount" />
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
  { value: 'credit', label: 'Credit' },
  { value: 'split', label: 'Split Payment' },
];
</script>
