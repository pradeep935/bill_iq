<template>
  <button v-if="showView" type="button" class="crud-action" :title="viewTitle" @click="$emit('view')">
    {{ viewLabel }}
  </button>

  <div class="action-menu-wrap">
    <button type="button" class="crud-action more-action" :title="moreTitle" @click="$emit('toggle')">
      {{ moreLabel }}
    </button>

    <div v-if="open" class="action-menu">
      <slot />
    </div>
  </div>
</template>

<script setup>
defineProps({
  open: { type: Boolean, default: false },
  showView: { type: Boolean, default: true },
  viewLabel: { type: String, default: 'View' },
  viewTitle: { type: String, default: 'View record' },
  moreLabel: { type: String, default: 'More' },
  moreTitle: { type: String, default: 'More actions' },
});

defineEmits(['view', 'toggle']);
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
  position: absolute;
  right: 0;
  top: calc(100% + 7px);
  z-index: 25;
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
