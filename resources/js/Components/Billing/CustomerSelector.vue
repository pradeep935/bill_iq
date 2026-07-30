<template>
  <section class="bill-ui-card">
    <div class="bill-ui-card-head">
      <div>
        <span>CUSTOMER</span>
        <h2>Customer Information</h2>
      </div>
      <span v-if="selectedCustomer" class="bill-status-badge">{{ selectedCustomer.customer_type || 'customer' }}</span>
    </div>
    <label class="bill-field">
      <span>Customer</span>
      <select ref="selectRef" :value="modelValue" title="Select invoice customer" @change="$emit('update:modelValue', $event.target.value)">
        <option value="">Walk-in Customer</option>
        <option v-for="customer in customers" :key="customer.id" :value="customer.id">
          {{ customer.customer_name }}{{ customer.mobile ? ` - ${customer.mobile}` : '' }}
        </option>
      </select>
    </label>
    <div v-if="selectedCustomer" class="bill-customer-meta">
      <div><span>Mobile</span><strong>{{ selectedCustomer.mobile || selectedCustomer.phone || '-' }}</strong></div>
      <div><span>GSTIN</span><strong>{{ selectedCustomer.gstin || '-' }}</strong></div>
      <div><span>Price Type</span><strong>{{ selectedCustomer.price_type || 'retail' }}</strong></div>
    </div>
  </section>
</template>

<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
  modelValue: { type: [String, Number], default: '' },
  customers: { type: Array, default: () => [] },
});

defineEmits(['update:modelValue']);

const selectRef = ref(null);
const selectedCustomer = computed(() => props.customers.find((customer) => Number(customer.id) === Number(props.modelValue)));

defineExpose({
  focus: () => selectRef.value?.focus(),
});
</script>
