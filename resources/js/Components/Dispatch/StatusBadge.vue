<template>
  <span class="dispatch-status" :class="normalized">{{ label }}</span>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  status: { type: [String, Number], default: '' },
});

const normalized = computed(() => String(props.status || 'draft').toLowerCase().replaceAll(' ', '_'));
const label = computed(() => String(props.status || 'Draft').replaceAll('_', ' '));
</script>

<style scoped>
.dispatch-status{display:inline-flex;align-items:center;min-height:24px;padding:4px 8px;border-radius:7px;background:#edf2ff;color:#2457d6;font-size:10px;font-weight:900;text-transform:capitalize;white-space:nowrap}.draft,.pending,.ready,.reserved{background:#fff7ed;color:#c2410c}.picking,.packed,.partial,.partially_dispatched,.in_transit{background:#eef6ff;color:#1d4f8f}.dispatched,.delivered,.completed,.fully_dispatched{background:#ecfdf3;color:#027a48}.cancelled,.released,.expired{background:#fff1f2;color:#be123c}
</style>
