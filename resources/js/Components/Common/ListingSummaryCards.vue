<template>
  <div class="summary-grid">
    <button
      v-for="card in cards"
      :key="card.label"
      type="button"
      class="summary-card"
      :class="{ clickable: card.filter }"
      :disabled="!card.filter"
      @click="card.filter && emit('select', card)"
    >
      <span>{{ card.label }}</span>
      <strong>{{ card.value }}</strong>
    </button>
  </div>
</template>

<script setup>
defineProps({
  cards: { type: Array, default: () => [] },
});
const emit = defineEmits(['select']);
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
  cursor: default;
  padding: 16px 18px;
  text-align: left;
}

.summary-card.clickable {
  cursor: pointer;
}

.summary-card.clickable:hover {
  border-color: #c7d5f6;
  box-shadow: 0 10px 28px rgba(36, 87, 214, .08);
}

.summary-card:disabled {
  opacity: 1;
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
