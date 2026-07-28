<script setup>
defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
    loading: Boolean,
    initialLoaded: Boolean,
    cards: { type: Array, default: () => [] },
    pagination: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['page']);
</script>

<template>
    <div class="inventory-module">
        <div class="module-toolbar">
            <slot name="toolbar" />
        </div>

        <div class="summary-grid">
            <article v-for="card in cards" :key="card.label" class="summary-card" :class="`tone-${card.tone || 'info'}`">
                <span>{{ card.label }}</span>
                <strong>{{ card.value }}</strong>
            </article>
        </div>

        <section class="panel loading-host">
            <div class="section-head">
                <div>
                    <h2>{{ title }}</h2>
                    <p>{{ subtitle }}</p>
                </div>
                <slot name="section-actions" />
            </div>

            <div class="filters">
                <slot name="filters" />
            </div>

            <div v-if="loading && initialLoaded" class="overlay">Refreshing data...</div>

            <slot />

            <div class="pager">
                <button :disabled="pagination.current_page <= 1" @click="emit('page', pagination.current_page - 1)">Previous</button>
                <span>{{ pagination.from || 0 }}-{{ pagination.to || 0 }} of {{ pagination.total || 0 }}</span>
                <button :disabled="pagination.current_page >= pagination.last_page" @click="emit('page', pagination.current_page + 1)">Next</button>
            </div>
        </section>
    </div>
</template>

<style scoped>
.inventory-module{padding:0 0 28px}.module-toolbar{display:flex;gap:10px;justify-content:flex-end;margin:-4px 0 14px}.summary-grid{display:grid;gap:12px;grid-template-columns:repeat(6,minmax(0,1fr));margin-bottom:16px}.summary-card{background:#fff;border:1px solid #dfe6ef;border-left:4px solid #2563eb;border-radius:8px;box-shadow:0 8px 22px rgba(25,50,84,.035);min-height:76px;padding:13px 14px}.summary-card span{color:#7f8da4;display:block;font-size:11px;font-weight:800;margin-bottom:7px}.summary-card strong{color:#142139;display:block;font-size:20px;font-weight:900;line-height:1.1}.tone-good{border-left-color:#22c55e}.tone-warn{border-left-color:#f59e0b}.tone-bad{border-left-color:#ef4444}.tone-info{border-left-color:#2563eb}.tone-money{border-left-color:#14b8a6}.panel{background:#fff;border:1px solid #dfe6ef;border-radius:8px;margin-top:18px;padding:18px}.loading-host{position:relative}.section-head{align-items:flex-start;display:flex;gap:14px;justify-content:space-between;margin-bottom:14px}.section-head h2{color:#142139;font-size:18px;margin:0}.section-head p{color:#758197;font-size:12px;margin:4px 0 0}.filters{display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(142px,1fr));margin-bottom:14px}.pager{align-items:center;display:flex;gap:12px;justify-content:flex-end;margin-top:14px}.overlay{align-items:center;background:rgba(255,255,255,.78);bottom:0;color:#344159;display:flex;font-size:13px;font-weight:800;justify-content:center;left:0;position:absolute;right:0;top:0;z-index:8}button{background:#fff;border:1px solid #d8e0eb;border-radius:8px;color:#344159;cursor:pointer;font-size:12px;font-weight:750;min-height:38px;padding:8px 10px}@media(max-width:1200px){.summary-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:760px){.summary-grid{grid-template-columns:1fr}.module-toolbar,.section-head{align-items:flex-start;flex-direction:column}.pager{justify-content:flex-start}}
</style>
