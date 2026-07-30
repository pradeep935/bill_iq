<template>
  <section class="bill-invoice-header">
    <div>
      <span>{{ eyebrow }}</span>
      <h1>{{ title }}</h1>
      <p>{{ subtitle }}</p>
    </div>
    <div class="bill-shortcuts" aria-label="Keyboard shortcuts">
      <span v-for="shortcut in shortcuts" :key="shortcut.key"><kbd>{{ shortcut.key }}</kbd>{{ shortcut.label }}</span>
    </div>
  </section>
</template>

<script setup>
defineProps({
  eyebrow: { type: String, default: 'SALES' },
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
  shortcuts: {
    type: Array,
    default: () => [
      { key: 'F1', label: 'New Bill' },
      { key: 'F2', label: 'Customer' },
      { key: 'F3', label: 'Product Search' },
      { key: 'F4', label: 'Hold' },
      { key: 'F5', label: 'Payment' },
      { key: 'F6', label: 'Print' },
    ],
  },
});
</script>
