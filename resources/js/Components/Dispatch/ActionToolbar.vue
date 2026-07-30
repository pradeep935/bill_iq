<template>
  <div class="dispatch-toolbar">
    <slot />
  </div>
</template>

<style scoped>
.dispatch-toolbar{display:flex;justify-content:flex-end;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:12px}.dispatch-toolbar :deep(button){min-height:38px;padding:8px 11px;border:1px solid #d8e0eb;border-radius:8px;background:#fff;color:#344159;font-size:12px;font-weight:850;cursor:pointer}.dispatch-toolbar :deep(.primary){color:#fff;background:#2457d6;border-color:#2457d6}.dispatch-toolbar :deep(.danger){color:#d23f49;background:#fff3f4;border-color:#ffd6da}@media(max-width:720px){.dispatch-toolbar{justify-content:flex-start}.dispatch-toolbar :deep(button){flex:1 1 auto}}
</style>
