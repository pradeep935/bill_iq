<template>
  <section class="dispatch-summary-section">
    <div class="dispatch-summary-head"><span>SUMMARY</span><h2>Dispatch Summary</h2></div>
    <div class="dispatch-summary">
      <button v-for="card in cards" :key="card.label" type="button" :title="card.label" @click="$emit('select', card)">
        <span>{{ card.label }}</span>
        <strong>{{ card.value }}</strong>
      </button>
    </div>
  </section>
</template>

<script setup>
defineProps({ cards: { type: Array, default: () => [] } });
defineEmits(['select']);
</script>

<style scoped>
.dispatch-summary-section{position:sticky;top:74px;z-index:10;margin-bottom:14px}.dispatch-summary-head{display:flex;align-items:end;justify-content:space-between;margin-bottom:8px}.dispatch-summary-head span{color:#2457d6;font-size:10px;font-weight:900;letter-spacing:.08em}.dispatch-summary-head h2{margin:0;color:#142139;font-size:15px}.dispatch-summary{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px}.dispatch-summary button{min-height:78px;padding:12px 13px;border:1px solid #dfe6ef;border-left:4px solid #2457d6;border-radius:8px;background:#fff;box-shadow:0 8px 22px rgba(25,50,84,.035);text-align:left;cursor:pointer}.dispatch-summary button span{display:block;color:#7f8da4;font-size:11px;font-weight:850}.dispatch-summary strong{display:block;margin-top:8px;color:#142139;font-size:21px;font-weight:900;line-height:1}@media(max-width:1200px){.dispatch-summary-section{position:static}.dispatch-summary{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:720px){.dispatch-summary{grid-template-columns:1fr}}
</style>
