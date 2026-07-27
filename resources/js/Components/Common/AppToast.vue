<template>
  <Transition name="toast-slide">
    <div v-if="show" class="toast-message" :class="type">
      <span class="toast-icon"></span>
      <div>
        <strong>{{ title }}</strong>
        <p>{{ message }}</p>
      </div>
    </div>
  </Transition>
</template>

<script setup>
defineProps({
  show: { type: Boolean, default: false },
  title: { type: String, default: '' },
  message: { type: String, default: '' },
  type: { type: String, default: 'info' },
});
</script>

<style scoped>
.toast-message {
  align-items: flex-start;
  background: #fff;
  border: 1px solid #dfe6ef;
  border-left: 4px solid #2457d6;
  border-radius: 10px;
  box-shadow: 0 18px 44px rgba(15, 23, 42, .16);
  display: flex;
  gap: 12px;
  padding: 14px 15px;
  position: fixed;
  right: 22px;
  top: 18px;
  width: min(360px, calc(100vw - 32px));
  z-index: 13000;
}

.toast-message strong {
  color: #142038;
  display: block;
  font-size: 13px;
  font-weight: 800;
}

.toast-message p {
  color: #536179;
  font-size: 12px;
  line-height: 1.45;
  margin: 4px 0 0;
}

.toast-icon {
  background: #edf2ff;
  border: 1px solid #ccdaff;
  border-radius: 7px;
  flex-shrink: 0;
  height: 24px;
  width: 24px;
}

.toast-message.success {
  border-left-color: #20a464;
}

.toast-message.success .toast-icon {
  background: #eaf8f1;
  border-color: #bfead5;
}

.toast-message.error {
  border-left-color: #d23f49;
}

.toast-message.error .toast-icon {
  background: #fff1f2;
  border-color: #ffd5d8;
}

.toast-message.warning {
  border-left-color: #d79a20;
}

.toast-message.warning .toast-icon {
  background: #fff8e6;
  border-color: #ffe0a3;
}

.toast-slide-enter-active,
.toast-slide-leave-active {
  transition: opacity .2s ease, transform .2s ease;
}

.toast-slide-enter-from,
.toast-slide-leave-to {
  opacity: 0;
  transform: translateY(-8px);
}
</style>
