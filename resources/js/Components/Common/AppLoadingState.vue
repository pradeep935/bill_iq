<template>
  <div class="app-loading-state" :class="[`is-${variant}`, { 'is-overlay': overlay }]">
    <div class="loading-card">
      <div class="loader"></div>
      <strong>{{ title }}</strong>
      <span>{{ description }}</span>
      <div v-if="showSkeleton" class="skeleton-list">
        <div v-for="index in rows" :key="index" class="skeleton-row">
          <i></i>
          <b></b>
          <em></em>
          <small></small>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
defineProps({
  title: { type: String, default: 'Loading records...' },
  description: { type: String, default: 'Please wait while data is loaded.' },
  rows: { type: Number, default: 3 },
  showSkeleton: { type: Boolean, default: true },
  overlay: { type: Boolean, default: false },
  variant: { type: String, default: 'panel' },
});
</script>

<style scoped>
.app-loading-state {
  align-items: center;
  display: flex;
  justify-content: center;
  min-height: 230px;
  padding: 34px 22px;
  text-align: center;
}

.app-loading-state.is-compact {
  min-height: 120px;
  padding: 18px;
}

.app-loading-state.is-overlay {
  background: rgba(241, 246, 252, .74);
  bottom: 0;
  left: 0;
  min-height: 0;
  padding: 0;
  position: absolute;
  right: 0;
  top: 0;
  z-index: 8;
}

.loading-card {
  align-items: center;
  display: flex;
  flex-direction: column;
  width: min(100%, 720px);
}

.is-overlay .loading-card {
  background: #fff;
  border: 1px solid #dfe6ef;
  border-radius: 8px;
  box-shadow: 0 20px 45px rgba(15, 23, 42, .16);
  padding: 22px;
  width: min(92%, 420px);
}

.loader {
  animation: spin .75s linear infinite;
  border: 3px solid #dfe7f5;
  border-radius: 50%;
  border-top-color: #2457d6;
  height: 34px;
  margin-bottom: 15px;
  width: 34px;
}

strong,
span {
  display: block;
}

strong {
  color: #28354d;
  font-size: 13px;
  font-weight: 850;
  margin-bottom: 4px;
}

span {
  color: #8490a2;
  font-size: 11px;
  font-weight: 650;
}

.skeleton-list {
  display: grid;
  gap: 9px;
  margin-top: 20px;
  max-width: 680px;
  width: min(100%, 680px);
}

.skeleton-row {
  align-items: center;
  background: #f8fafc;
  border: 1px solid #edf1f5;
  border-radius: 9px;
  display: grid;
  gap: 12px;
  grid-template-columns: 36px 1.4fr .9fr .55fr;
  min-height: 44px;
  padding: 8px 12px;
}

.skeleton-row i,
.skeleton-row b,
.skeleton-row em,
.skeleton-row small {
  animation: shimmer 1.1s ease-in-out infinite;
  background: linear-gradient(90deg, #edf2f7 25%, #f7faff 45%, #edf2f7 65%);
  background-size: 220% 100%;
  border-radius: 7px;
  display: block;
  height: 12px;
}

.skeleton-row i {
  border-radius: 9px;
  height: 28px;
}

.skeleton-row em {
  height: 10px;
}

.skeleton-row small {
  height: 22px;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

@keyframes shimmer {
  0% { background-position: 120% 0; }
  100% { background-position: -120% 0; }
}

@media (max-width: 720px) {
  .skeleton-row {
    grid-template-columns: 32px 1fr;
  }

  .skeleton-row em,
  .skeleton-row small {
    display: none;
  }
}
</style>
