<script setup>
import { ref } from 'vue';

// A small "How to use this page" box. Open on first visit; remembers when the user closes it.
const props = defineProps({
    id: { type: String, required: true },
    title: { type: String, required: true },
    steps: { type: Array, required: true },
    notes: { type: Array, default: () => [] },
});

const key = `billiq-howto-${props.id}`;
const read = () => { try { return localStorage.getItem(key) !== 'closed'; } catch (e) { return true; } };
const open = ref(read());
const toggle = () => {
    open.value = !open.value;
    try { localStorage.setItem(key, open.value ? 'open' : 'closed'); } catch (e) { /* storage unavailable */ }
};
</script>

<template>
    <section class="howto">
        <button type="button" class="howto-toggle" :aria-expanded="open" @click="toggle">
            <span class="howto-mark">?</span>
            <strong>{{ title }}</strong>
            <em>{{ open ? 'Hide' : 'Show steps' }}</em>
        </button>
        <div v-if="open" class="howto-body">
            <ol><li v-for="step in steps" :key="step">{{ step }}</li></ol>
            <p v-for="note in notes" :key="note">{{ note }}</p>
        </div>
    </section>
</template>

<style scoped>
.howto{margin-bottom:14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px}
.howto-toggle{display:flex;align-items:center;gap:10px;width:100%;min-height:42px;padding:8px 12px;color:#1e3a8a;background:transparent;border:0;cursor:pointer;text-align:left;font-size:13px}
.howto-toggle em{margin-left:auto;color:#2457d6;font-size:12px;font-style:normal;font-weight:800}
.howto-mark{display:grid;place-items:center;width:20px;height:20px;color:#fff;background:#2457d6;border-radius:50%;font-size:12px;font-weight:900}
.howto-body{padding:0 16px 12px 16px;color:#27344c;font-size:13px;line-height:1.7}
.howto-body ol{margin:0;padding-left:20px;list-style:decimal}
.howto-body p{margin:6px 0 0;color:#475569;font-size:12px}
@media print{.howto{display:none}}
</style>
