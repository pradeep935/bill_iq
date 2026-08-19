<template>
  <button v-if="showView" type="button" class="crud-action" :title="viewTitle" @click="$emit('view')">
    {{ viewLabel }}
  </button>

  <div class="action-menu-wrap">
    <button ref="moreButton" type="button" class="crud-action more-action" :title="moreTitle" @click="$emit('toggle')">
      {{ moreLabel }}
    </button>

    <div v-if="open" class="action-menu" :style="menuStyle">
      <slot />
    </div>
  </div>
</template>

<script setup>
import { nextTick, onMounted, onUnmounted, ref, watch } from 'vue';

const props = defineProps({
  open: { type: Boolean, default: false },
  showView: { type: Boolean, default: true },
  viewLabel: { type: String, default: 'View' },
  viewTitle: { type: String, default: 'View record' },
  moreLabel: { type: String, default: 'More' },
  moreTitle: { type: String, default: 'More actions' },
  placement: { type: String, default: 'bottom' },
});

defineEmits(['view', 'toggle']);

const moreButton = ref(null);
const menuStyle = ref({});

const positionMenu = async () => {
  if (!props.open) return;
  await nextTick();
  const rect = moreButton.value?.getBoundingClientRect();
  if (!rect) return;

  const gap = 7;
  menuStyle.value = {
    position: 'fixed',
    right: `${Math.max(8, window.innerWidth - rect.right)}px`,
    ...(props.placement === 'top'
      ? { bottom: `${Math.max(8, window.innerHeight - rect.top + gap)}px`, top: 'auto' }
      : { top: `${rect.bottom + gap}px`, bottom: 'auto' }),
  };
};

watch(() => props.open, positionMenu);

onMounted(() => {
  window.addEventListener('resize', positionMenu);
  window.addEventListener('scroll', positionMenu, true);
});

onUnmounted(() => {
  window.removeEventListener('resize', positionMenu);
  window.removeEventListener('scroll', positionMenu, true);
});
</script>

<style scoped>
.crud-action {
  align-items: center;
  background: #ffffff;
  border: 1px solid #dce3ec;
  border-radius: 8px;
  color: #536179;
  cursor: pointer;
  display: inline-flex;
  font-size: 10px;
  font-weight: 750;
  height: 30px;
  justify-content: center;
  min-width: 46px;
  padding: 0 9px;
}

.crud-action:hover {
  background: #edf2ff;
  border-color: #ccdaff;
  color: #2457d6;
}

.more-action {
  min-width: 56px;
}

.action-menu-wrap {
  position: relative;
}

.action-menu {
  background: #ffffff;
  border: 1px solid #dce3ec;
  border-radius: 8px;
  box-shadow: 0 16px 34px rgba(15, 23, 42, 0.16);
  display: grid;
  gap: 3px;
  min-width: 166px;
  padding: 6px;
  z-index: 1000;
}

.action-menu :slotted(button) {
  background: transparent;
  border: 0;
  border-radius: 6px;
  color: #344158;
  cursor: pointer;
  font-size: 12px;
  font-weight: 750;
  min-height: 32px;
  padding: 0 10px;
  text-align: left;
  width: 100%;
}

.action-menu :slotted(button:hover) {
  background: #edf2ff;
  color: #2457d6;
}

.action-menu :slotted(button:disabled) {
  color: #96a0b3;
  cursor: not-allowed;
}
</style>
