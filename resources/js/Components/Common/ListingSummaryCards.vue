<template>
  <div class="summary-grid">
    <div v-for="card in cards" :key="card.label" class="summary-card">
      <span>{{ card.label }}</span>
      <strong>{{ card.value }}</strong>
    </div>
  </div>
</template>

<script setup>
defineProps({
  cards: { type: Array, default: () => [] },
});
</script>

<style scoped>
.summary-grid {
  display: grid;
  gap: 16px;
  grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
  margin-bottom: 18px;
}

.summary-card {
  background: #fff;
  border: 1px solid #dfe6ef;
  border-radius: 12px;
  box-shadow: 0 8px 24px rgba(25, 50, 84, .035);
  padding: 16px 18px;
}

.summary-card span {
  color: #8190a8;
  display: block;
  font-size: 11px;
  font-weight: 750;
  margin-bottom: 6px;
}

.summary-card strong {
  color: #142139;
  display: block;
  font-size: 19px;
  font-weight: 850;
}
</style>
