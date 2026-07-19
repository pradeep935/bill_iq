<script setup>
import { computed } from 'vue';

const {filters, filterCount = 0, field = 'name'} = defineProps(['filters','filterCount','field']);
const emit = defineEmits(['search', 'toggle-filters']);

function searchList() {
    emit('search','search');
}

function clearSearch() {
    emit('search','clear');
}
</script>

<template>
  <div class="filter-bar" style="gap:8px;">
    <div class="input-group" style="max-width:220px;">
      <span class="input-group-text"><i class="bi bi-search" style="font-size:13px;"></i></span>
      <input
        type="text"
        class="form-control"
        :placeholder="`Search by ${field.replace('_',' ')}...`"
        style="height:36px;"
        v-model="filters[field]"
        @keyup.enter="searchList"
      />
    </div>

    <button class="btn btn-sm" :class="filters.show ? 'btn-primary' : 'btn-secondary'" @click="$emit('toggle-filters')">
      <i class="bi bi-sliders"></i> Filters
      <span class="filter-count-badge" v-if="filterCount > 0">{{ filterCount }}</span>
    </button>

    <div style="flex:1;min-width:0;"></div>

    <button class="btn btn-ghost-danger btn-sm" v-if="filterCount > 0" @click="clearSearch">
      <i class="bi bi-x-circle"></i> Clear all
    </button>
  </div>
</template>