<script setup>
import { computed, ref } from 'vue';
import { helpFor } from './pageHelp';

const props = defineProps({
    page: { type: String, required: true },
    title: { type: String, default: '' },
});

const help = computed(() => helpFor(props.page, props.title));
const open = ref(false);
const show = () => { if (help.value) open.value = true; return Boolean(help.value); };
defineExpose({ show });
</script>

<template>
    <div v-if="help" class="ph-bar">
        <button type="button" class="ph-toggle" @click="open = true">
            <span class="ph-mark">?</span>
            <strong>How to use: {{ title }}</strong>
            <em>Open guide</em>
        </button>
    </div>

    <Teleport to="body">
        <div v-if="help && open" class="ph-backdrop" @click.self="open = false" @keydown.esc="open = false">
            <aside class="ph-drawer" role="dialog" aria-modal="true" :aria-label="`${title} help`">
                <div class="ph-head">
                    <div><span>HELP</span><h2>{{ title }}</h2></div>
                    <button type="button" @click="open = false">Close</button>
                </div>

                <h3>What this page does</h3>
                <p>{{ help.what }}</p>

                <template v-if="help.tabs.length">
                    <h3>Tabs on this page</h3>
                    <dl><template v-for="tab in help.tabs" :key="tab[0]"><dt>{{ tab[0] }}</dt><dd>{{ tab[1] }}</dd></template></dl>
                </template>

                <template v-if="help.steps.length">
                    <h3>How to use it</h3>
                    <ol><li v-for="step in help.steps" :key="step">{{ step }}</li></ol>
                </template>

                <template v-if="help.notes.length">
                    <h3>Please note</h3>
                    <ul><li v-for="note in help.notes" :key="note">{{ note }}</li></ul>
                </template>
            </aside>
        </div>
    </Teleport>
</template>

<style scoped>
.ph-bar{margin-bottom:14px}
.ph-toggle{display:flex;align-items:center;gap:10px;width:100%;min-height:42px;padding:8px 12px;color:#1e3a8a;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;cursor:pointer;text-align:left;font-size:13px}
.ph-toggle em{margin-left:auto;color:#2457d6;font-size:12px;font-style:normal;font-weight:800}
.ph-mark{display:grid;flex:0 0 20px;place-items:center;width:20px;height:20px;color:#fff;background:#2457d6;border-radius:50%;font-size:12px;font-weight:900}
.ph-backdrop{position:fixed;inset:0;z-index:1200;display:flex;justify-content:flex-end;background:rgba(15,23,42,.45)}
.ph-drawer{width:min(520px,100vw);height:100vh;height:100dvh;overflow:auto;padding:0 22px 28px;color:#27344c;background:#fff;box-shadow:-24px 0 70px rgba(15,23,42,.22);font-size:14px;line-height:1.7}
.ph-head{position:sticky;top:0;display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 -22px 8px;padding:16px 22px;background:#fff;border-bottom:1px solid #edf1f5}
.ph-head span{color:#2457d6;font-size:10px;font-weight:800;letter-spacing:1.2px}
.ph-head h2{margin:0;color:#142139;font-size:20px;font-weight:800}
.ph-head button{min-height:36px;padding:7px 14px;color:#344159;background:#fff;border:1px solid #d8e0eb;border-radius:8px;cursor:pointer;font-size:12px;font-weight:750}
h3{margin:18px 0 6px;color:#142139;font-size:13px;font-weight:800;text-transform:uppercase}
p{margin:0}
ol{margin:0;padding-left:20px;list-style:decimal}
ul{margin:0;padding-left:20px;list-style:disc}
dl{margin:0}
dt{color:#142139;font-weight:800}
dd{margin:0 0 8px;color:#475569;font-size:13px}
@media print{.ph-bar,.ph-backdrop{display:none}}
</style>
