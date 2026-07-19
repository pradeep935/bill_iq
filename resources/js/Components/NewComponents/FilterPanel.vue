<script setup>

const {show_filters} = defineProps(['show_filters']);
const emit = defineEmits(['search']);

function searchList() {
    emit('search','search');
}

function clearSearch() {
    emit('search','clear');
}
</script>
<template>
  <div class="filter-panel" :class="{ open: show_filters }">
    <div class="filter-panel-inner">
      <slot></slot>
      <div class="filter-actions">
        <button class="btn btn-secondary btn-sm" @click="clearSearch"><i class="bi bi-x"></i> Clear All</button>
        <button class="btn btn-primary btn-sm" @click="searchList"><i class="bi bi-check2"></i> Apply Filters</button>
      </div>
    </div>
  </div>
</template>