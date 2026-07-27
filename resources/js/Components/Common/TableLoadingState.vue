<template>
  <AppLoadingState
    :title="title"
    :description="description"
    :rows="rows"
    :show-skeleton="showSkeleton"
    :overlay="overlay"
    :variant="variant"
  />
</template>

<script setup>
import AppLoadingState from './AppLoadingState.vue';

defineProps({
  title: { type: String, default: 'Loading records...' },
  description: { type: String, default: 'Please wait while data is loaded.' },
  rows: { type: Number, default: 3 },
  showSkeleton: { type: Boolean, default: true },
  overlay: { type: Boolean, default: false },
  variant: { type: String, default: 'panel' },
});
</script>
