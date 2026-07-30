<template>
  <section class="bill-ui-card dispatch-filter-panel">
    <div class="bill-ui-card-head">
      <div><span>FILTERS</span><h2>Search & Filters</h2></div>
      <slot name="actions" />
    </div>
    <div class="dispatch-filter-grid"><slot /></div>
  </section>
</template>

<style scoped>
.dispatch-filter-panel{margin-bottom:14px}.dispatch-filter-grid{display:grid;grid-template-columns:repeat(5,minmax(150px,1fr));gap:10px}.dispatch-filter-grid :deep(label){display:flex;flex-direction:column;gap:5px}.dispatch-filter-grid :deep(label span){color:#69758a;font-size:10px;font-weight:900;text-transform:uppercase}.dispatch-filter-grid :deep(input),.dispatch-filter-grid :deep(select){min-height:38px;padding:8px 10px;border:1px solid #d8e0eb;border-radius:8px;background:#fff;color:#344159;font-size:12px}@media(max-width:1200px){.dispatch-filter-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:760px){.dispatch-filter-grid{grid-template-columns:1fr}}
</style>
