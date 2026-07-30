<template>
  <section class="bill-ui-card bill-summary-card">
    <div class="bill-ui-card-head">
      <div>
        <span v-if="eyebrow">{{ eyebrow }}</span>
        <h2>{{ title }}</h2>
      </div>
      <slot name="badge" />
    </div>
    <div class="bill-summary-list">
      <div v-for="row in rows" :key="row.label" class="bill-summary-row" :class="{ grand: row.grand }">
        <span>{{ row.label }}</span>
        <strong>{{ row.value }}</strong>
      </div>
    </div>
    <slot />
  </section>
</template>

<script setup>
defineProps({
  title: { type: String, required: true },
  eyebrow: { type: String, default: '' },
  rows: { type: Array, default: () => [] },
});
</script>
