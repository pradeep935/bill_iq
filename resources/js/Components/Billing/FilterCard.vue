<template>
  <section class="bill-ui-card bill-filter-card">
    <div v-if="title || $slots.actions" class="bill-ui-card-head">
      <div>
        <span v-if="eyebrow">{{ eyebrow }}</span>
        <h2 v-if="title">{{ title }}</h2>
      </div>
      <div class="bill-ui-card-actions">
        <slot name="actions" />
      </div>
    </div>
    <div class="bill-filter-grid">
      <slot />
    </div>
  </section>
</template>

<script setup>
defineProps({
  title: { type: String, default: '' },
  eyebrow: { type: String, default: '' },
});
</script>
