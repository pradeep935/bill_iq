<template>
  <Teleport to="body">
    <Transition name="crud-drawer">
      <div v-if="modelValue" class="crud-drawer-wrapper">
        <div class="crud-drawer-backdrop" @click="$emit('close')"></div>
        <aside class="crud-drawer-panel">
          <header class="crud-drawer-header">
            <div class="crud-drawer-heading">
              <div class="crud-drawer-icon">
                <slot name="icon">
                  <svg viewBox="0 0 24 24" fill="none">
                    <path d="M4 7h16M7 4v6M17 4v6M6 14h12M9 11v6M15 11v6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                  </svg>
                </slot>
              </div>
              <div>
                <span>{{ eyebrow }}</span>
                <h2>{{ title }}</h2>
                <p>{{ description }}</p>
              </div>
            </div>
            <button type="button" class="crud-drawer-close" @click="$emit('close')">x</button>
          </header>

          <nav v-if="$slots.tabs" class="crud-drawer-tabs">
            <slot name="tabs"></slot>
          </nav>

          <main class="crud-drawer-content">
            <div v-if="errorList.length" class="crud-error-summary">
              <strong>Please check these fields</strong>
              <span v-for="(error, index) in errorList" :key="index">{{ error }}</span>
            </div>
            <slot></slot>
          </main>

          <footer v-if="showFooter" class="crud-drawer-footer">
            <button type="button" class="crud-secondary" @click="$emit('close')">Cancel</button>
            <button type="button" class="crud-primary" :disabled="processing" @click="$emit('save')">
              {{ processing ? 'Saving...' : saveLabel }}
            </button>
          </footer>
        </aside>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { computed, onBeforeUnmount, watch } from 'vue';

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  title: { type: String, required: true },
  description: { type: String, default: '' },
  eyebrow: { type: String, default: 'MASTER SETUP' },
  saveLabel: { type: String, default: 'Save' },
  processing: { type: Boolean, default: false },
  showFooter: { type: Boolean, default: true },
  errors: { type: Object, default: () => ({}) },
});

defineEmits(['close', 'save']);

watch(
  () => props.modelValue,
  (isOpen) => {
    document.body.classList.toggle('product-drawer-open', isOpen);
  }
);

onBeforeUnmount(() => {
  document.body.classList.remove('product-drawer-open');
});

const errorList = computed(() =>
  Object.values(props.errors || {})
    .flatMap((messages) => Array.isArray(messages) ? messages : [messages])
    .filter(Boolean)
);
</script>

<style scoped>
.crud-drawer-wrapper {
  inset: 0;
  position: fixed;
  z-index: 9999;
}

.crud-drawer-backdrop {
  background: rgba(5, 18, 38, .62);
  backdrop-filter: blur(3px);
  inset: 0;
  position: absolute;
}

.crud-drawer-panel {
  background: #f4f7fb;
  box-shadow: -24px 0 60px rgba(7, 25, 51, .22);
  display: flex;
  flex-direction: column;
  height: 100vh;
  position: absolute;
  right: 0;
  top: 0;
  width: min(960px, 100%);
}

.crud-drawer-header {
  align-items: center;
  background: #fff;
  border-bottom: 1px solid #e3e9f2;
  display: flex;
  flex-shrink: 0;
  gap: 18px;
  justify-content: space-between;
  min-height: 96px;
  padding: 19px 28px;
  z-index: 10;
}

.crud-drawer-heading {
  align-items: center;
  display: flex;
  gap: 15px;
}

.crud-drawer-icon {
  background: linear-gradient(145deg, #edf3ff, #dce7ff);
  border: 1px solid #d4e1ff;
  border-radius: 14px;
  color: #2457d6;
  display: grid;
  flex-shrink: 0;
  height: 48px;
  place-items: center;
  width: 48px;
}

.crud-drawer-icon svg {
  height: 25px;
  width: 25px;
}

.crud-drawer-header span {
  color: #2563eb;
  display: block;
  font-size: 10px;
  font-weight: 800;
  letter-spacing: 1.5px;
  margin-bottom: 2px;
}

.crud-drawer-header h2 {
  color: #101c34;
  font-size: 22px;
  font-weight: 800;
  line-height: 1.25;
  margin: 0;
}

.crud-drawer-header p {
  color: #718198;
  font-size: 12px;
  margin: 4px 0 0;
}

.crud-drawer-close {
  align-items: center;
  background: #f4f6fa;
  border: 1px solid #dfe5ee;
  border-radius: 11px;
  color: #536078;
  cursor: pointer;
  display: inline-flex;
  font-size: 25px;
  font-weight: 300;
  height: 40px;
  justify-content: center;
  line-height: 1;
  width: 40px;
}

.crud-drawer-close:hover {
  background: #fff0f1;
  border-color: #ffd4d7;
  color: #d23b45;
}

.crud-drawer-content {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  padding: 22px 28px 30px;
}

.crud-error-summary {
  background: #fff3f4;
  border: 1px solid #ffd4d8;
  border-radius: 9px;
  color: #96333a;
  display: grid;
  font-size: 11px;
  gap: 4px;
  margin-bottom: 14px;
  padding: 11px 13px;
}

.crud-error-summary strong {
  color: #7d2730;
  font-size: 12px;
}

.crud-drawer-tabs {
  background: #fff;
  border-bottom: 1px solid #e3e9f2;
  display: flex;
  flex-shrink: 0;
  gap: 7px;
  overflow-x: auto;
  padding: 12px 28px;
}

.crud-drawer-footer {
  align-items: center;
  background: #fff;
  border-top: 1px solid #dfe6ef;
  box-shadow: 0 -5px 18px rgba(18, 40, 71, .05);
  display: flex;
  flex-shrink: 0;
  gap: 12px;
  justify-content: flex-end;
  min-height: 74px;
  padding: 14px 28px;
  z-index: 10;
}

.crud-drawer-footer button {
  border: 1px solid #d8dfe9;
  border-radius: 9px;
  cursor: pointer;
  font-size: 12px;
  font-weight: 750;
  min-height: 42px;
  min-width: 94px;
  padding: 9px 18px;
}

.crud-secondary {
  background: #fff;
  color: #465269;
}

.crud-primary {
  background: #2563eb;
  border-color: #2563eb !important;
  box-shadow: 0 5px 14px rgba(37, 99, 235, .22);
  color: #fff;
}

.crud-primary:hover {
  background: #1d4ed8;
  border-color: #1d4ed8 !important;
}

.crud-primary:disabled {
  cursor: not-allowed;
  opacity: .65;
}

.crud-drawer-enter-active,
.crud-drawer-leave-active {
  transition: opacity .18s ease;
}

.crud-drawer-enter-active .crud-drawer-panel,
.crud-drawer-leave-active .crud-drawer-panel {
  transition: transform .22s ease;
}

.crud-drawer-enter-from,
.crud-drawer-leave-to {
  opacity: 0;
}

.crud-drawer-enter-from .crud-drawer-panel,
.crud-drawer-leave-to .crud-drawer-panel {
  transform: translateX(100%);
}

@media (max-width: 767px) {
  .crud-drawer-header {
    min-height: 84px;
    padding: 15px 16px;
  }

  .crud-drawer-icon,
  .crud-drawer-header p {
    display: none;
  }

  .crud-drawer-content {
    padding: 15px 14px 24px;
  }

  .crud-drawer-tabs {
    padding: 12px 14px;
  }

  .crud-drawer-footer {
    padding: 12px 14px;
  }

  .crud-drawer-footer button {
    flex: 1;
  }
}
</style>
