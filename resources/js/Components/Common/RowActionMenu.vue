<template>
  <button v-if="showView" type="button" class="crud-action" :title="viewTitle" @click="$emit('view')">
    {{ viewLabel }}
  </button>

  <div class="action-menu-wrap">
    <button
      ref="moreButton"
      type="button"
      class="crud-action more-action"
      :aria-label="moreTitle"
      :aria-expanded="open"
      aria-haspopup="menu"
      @click="$emit('toggle')"
    >
      <span class="more-dots" aria-hidden="true"></span>
      <span class="more-label">{{ moreLabel }}</span>
    </button>

    <div v-if="open" ref="menu" class="action-menu" :style="menuStyle" role="menu">
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
  placement: { type: String, default: 'auto' },
});

const moreButton = ref(null);
const menu = ref(null);
const menuStyle = ref({});
const emit = defineEmits(['view', 'toggle']);

const positionMenu = async () => {
  if (!props.open) return;
  await nextTick();
  const rect = moreButton.value?.getBoundingClientRect();
  if (!rect) return;

  const gap = 7;
  const menuHeight = menu.value?.offsetHeight || 0;
  const menuWidth = menu.value?.offsetWidth || 180;
  const spaceBelow = window.innerHeight - rect.bottom - gap;
  const spaceAbove = rect.top - gap;
  const shouldOpenTop = props.placement === 'top'
    ? spaceAbove >= menuHeight || spaceBelow < menuHeight
    : props.placement === 'bottom'
      ? false
      : spaceBelow < menuHeight && spaceAbove > spaceBelow;
  const openFromLeft = rect.right < menuWidth + 16;
  const right = Math.max(8, window.innerWidth - rect.right);
  const left = Math.max(8, Math.min(rect.left, window.innerWidth - menuWidth - 8));

  menuStyle.value = {
    position: 'fixed',
    right: openFromLeft ? 'auto' : `${right}px`,
    left: openFromLeft ? `${left}px` : 'auto',
    maxHeight: `${Math.max(160, Math.min(360, window.innerHeight - 24))}px`,
    overflow: 'auto',
    ...(shouldOpenTop
      ? { bottom: `${Math.max(8, window.innerHeight - rect.top + gap)}px`, top: 'auto' }
      : { top: `${rect.bottom + gap}px`, bottom: 'auto' }),
  };
};

watch(() => props.open, positionMenu);

const closeFromOutside = (event) => {
  if (!props.open) return;
  const target = event.target;
  if (moreButton.value?.contains(target) || menu.value?.contains(target)) return;
  emit('toggle');
};

const closeFromKeyboard = (event) => {
  if (!props.open || event.key !== 'Escape') return;
  emit('toggle');
};

onMounted(() => {
  document.addEventListener('pointerdown', closeFromOutside);
  document.addEventListener('keydown', closeFromKeyboard);
  window.addEventListener('resize', positionMenu);
  window.addEventListener('scroll', positionMenu, true);
});

onUnmounted(() => {
  document.removeEventListener('pointerdown', closeFromOutside);
  document.removeEventListener('keydown', closeFromKeyboard);
  window.removeEventListener('resize', positionMenu);
  window.removeEventListener('scroll', positionMenu, true);
});
</script>

<style scoped>
.crud-action {
  align-items: center;
  background: #f8fbff;
  border: 1px solid #cfe0f7;
  border-radius: 999px;
  color: #244363;
  cursor: pointer;
  display: inline-flex;
  font-size: 11px;
  font-weight: 800;
  gap: 7px;
  height: 32px;
  justify-content: center;
  line-height: 1;
  min-width: 38px;
  padding: 0 11px;
  transition: background .16s ease, border-color .16s ease, box-shadow .16s ease, color .16s ease;
}

.crud-action:hover {
  background: #eef5ff;
  border-color: #9fc0ff;
  color: #2457d6;
  box-shadow: 0 8px 18px rgba(36, 87, 214, .12);
}

.more-action {
  min-width: 78px;
}

.more-action[aria-expanded="true"] {
  background: #2457d6;
  border-color: #2457d6;
  color: #ffffff;
  box-shadow: 0 10px 22px rgba(36, 87, 214, .22);
}

.more-dots {
  width: 3px;
  height: 3px;
  border-radius: 50%;
  background: currentColor;
  box-shadow: 0 -5px 0 currentColor, 0 5px 0 currentColor;
  display: inline-block;
}

.more-label {
  white-space: nowrap;
}

.action-menu-wrap {
  position: relative;
}

.action-menu {
  background: #ffffff;
  border: 1px solid #dbe5f1;
  border-radius: 10px;
  box-shadow: 0 18px 42px rgba(15, 23, 42, 0.18);
  display: grid;
  gap: 4px;
  min-width: 178px;
  padding: 8px;
  z-index: 1000;
}

.action-menu :slotted(button) {
  background: transparent;
  border: 0;
  border-radius: 8px;
  color: #344158;
  cursor: pointer;
  font-size: 12px;
  font-weight: 800;
  min-height: 36px;
  padding: 0 12px;
  text-align: left;
  width: 100%;
}

.action-menu :slotted(button:hover) {
  background: #eef5ff;
  color: #2457d6;
}

.action-menu :slotted(button.danger) {
  color: #c93645;
}

.action-menu :slotted(button.danger:hover) {
  background: #fff0f2;
  color: #b42334;
}

.action-menu :slotted(button:disabled) {
  color: #96a0b3;
  cursor: not-allowed;
}
</style>
