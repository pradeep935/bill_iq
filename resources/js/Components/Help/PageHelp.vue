<script setup>
import { computed, ref, watch } from 'vue';
import { guideTopics, helpFor, pageHelp, startGuide } from './pageHelp';

const props = defineProps({
    page: { type: String, required: true },
    title: { type: String, default: '' },
});

const pageGuide = computed(() => helpFor(props.page, props.title));
const open = ref(false);
// null = this page; 'all' = topic list; otherwise a topic key.
const topic = ref(null);
const search = ref('');
const guideOf = (key) => (key === 'getting-started' ? startGuide : pageHelp[key]);
const help = computed(() => (topic.value && topic.value !== 'all' ? guideOf(topic.value) : pageGuide.value));
const heading = computed(() => (topic.value === 'all' ? 'All guides' : (guideTopics.find(([, key]) => key === topic.value)?.[0] || props.title)));
const matches = computed(() => {
    const words = search.value.trim().toLowerCase().split(/\s+/).filter(Boolean);
    return guideTopics.filter(([name, key]) => {
        const text = `${name} ${JSON.stringify(guideOf(key) || '')}`.toLowerCase();
        return words.every((word) => text.includes(word));
    });
});
const openHere = () => { topic.value = null; search.value = ''; open.value = true; };
// Search within one screen's buttons.
const find = ref('');
const groups = computed(() => {
    const word = find.value.trim().toLowerCase();
    return (help.value?.buttons || [])
        .map(([name, list]) => [name, word ? list.filter((row) => row.join(' ').toLowerCase().includes(word)) : list])
        .filter(([, list]) => list.length);
});
const body = ref(null);
const jump = (index) => body.value?.querySelectorAll('.ph-group')[index]?.scrollIntoView({ behavior: 'smooth', block: 'start' });
watch(topic, () => { find.value = ''; if (body.value) body.value.scrollTop = 0; });
const show = () => { if (pageGuide.value) openHere(); return Boolean(pageGuide.value); };
defineExpose({ show });
</script>

<template>
    <div v-if="pageGuide" class="ph-bar">
        <button type="button" class="ph-toggle" @click="openHere">
            <span class="ph-mark">?</span>
            <strong>How to use: {{ title }}</strong>
            <em>Open guide</em>
        </button>
    </div>

    <Teleport to="body">
        <div v-if="pageGuide && open" class="ph-backdrop" @click.self="open = false" @keydown.esc="open = false">
            <aside ref="body" class="ph-drawer" role="dialog" aria-modal="true" :aria-label="`${heading} help`">
                <div class="ph-head">
                    <div><span>HELP</span><h2>{{ heading }}</h2></div>
                    <div class="ph-head-actions">
                        <button v-if="topic !== 'all'" type="button" @click="topic = 'all'">All guides</button>
                        <button type="button" @click="open = false">Close</button>
                    </div>
                </div>

                <template v-if="topic === 'all'">
                    <input v-model="search" class="ph-search" type="search" placeholder="Search help, e.g. return, GST, password" aria-label="Search help" />
                    <div class="ph-topics">
                        <button v-for="[name, key] in matches" :key="key" type="button" @click="topic = key">{{ name }} <span>›</span></button>
                    </div>
                    <p v-if="!matches.length">No guide found. Try one word, such as “bill” or “stock”.</p>
                </template>

                <template v-else-if="help">
                    <h3>{{ topic === 'getting-started' ? 'Start here' : 'What this page does' }}</h3>
                    <p>{{ help.what }}</p>

                    <template v-if="help.tabs.length">
                        <h3>Tabs on this page</h3>
                        <dl><template v-for="tab in help.tabs" :key="tab[0]"><dt>{{ tab[0] }}</dt><dd>{{ tab[1] }}</dd></template></dl>
                    </template>

                    <template v-if="help.steps.length">
                        <h3>How to use it</h3>
                        <ol><li v-for="step in help.steps" :key="step">{{ step }}</li></ol>
                    </template>

                    <template v-if="help.buttons.length">
                        <h3>Every button on this screen</h3>
                        <input v-model="find" class="ph-search" type="search" placeholder="Find a button, e.g. print, edit, payment" aria-label="Find a button" />
                        <div v-if="!find && groups.length > 2" class="ph-jump">
                            <button v-for="([group], index) in groups" :key="group" type="button" @click="jump(index)">{{ group }}</button>
                        </div>
                        <p v-if="!groups.length">No button found with that word.</p>
                        <section v-for="[group, list] in groups" :key="group" class="ph-group">
                            <h4>{{ group }}</h4>
                            <div class="ph-cards">
                                <div v-for="[name, does, use] in list" :key="name" class="ph-button">
                                    <strong>{{ name }}</strong>
                                    <p>{{ does }}</p>
                                    <p v-if="use" class="ph-use"><b>Use:</b> {{ use }}</p>
                                </div>
                            </div>
                        </section>
                    </template>

                    <template v-if="help.notes.length">
                        <h3>Please note</h3>
                        <ul><li v-for="note in help.notes" :key="note">{{ note }}</li></ul>
                    </template>

                    <template v-if="help.faq.length">
                        <h3>Common questions</h3>
                        <details v-for="[question, answer] in help.faq" :key="question"><summary>{{ question }}</summary><p>{{ answer }}</p></details>
                    </template>
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
.ph-drawer{width:min(1040px,94vw);height:100vh;height:100dvh;overflow:auto;padding:0 22px 28px;color:#27344c;background:#fff;box-shadow:-24px 0 70px rgba(15,23,42,.22);font-size:14px;line-height:1.7}
.ph-head{position:sticky;top:0;display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 -22px 8px;padding:16px 22px;background:#fff;border-bottom:1px solid #edf1f5}
.ph-head span{color:#2457d6;font-size:10px;font-weight:800;letter-spacing:1.2px}
.ph-head h2{margin:0;color:#142139;font-size:20px;font-weight:800}
.ph-head button{min-height:36px;padding:7px 14px;color:#344159;background:#fff;border:1px solid #d8e0eb;border-radius:8px;cursor:pointer;font-size:12px;font-weight:750}
div.ph-head-actions{display:flex;gap:8px}
.ph-search{width:100%;min-height:42px;margin-top:8px;padding:9px 12px;border:1px solid #d8e0eb;border-radius:8px;font-size:14px}
.ph-topics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));column-gap:18px;margin-top:12px}
.ph-topics button{display:flex;align-items:center;justify-content:space-between;min-height:44px;padding:8px 4px;color:#142139;background:#fff;border:0;border-bottom:1px solid #eef2f7;cursor:pointer;text-align:left;font-size:14px;font-weight:700}
.ph-topics span{color:#2457d6}
h4{margin:14px 0 6px;color:#2457d6;font-size:13px;font-weight:800}
.ph-cards{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.ph-jump{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}
.ph-jump button{min-height:32px;padding:5px 12px;color:#2457d6;background:#edf4ff;border:1px solid #cfe0ff;border-radius:999px;cursor:pointer;font-size:12px;font-weight:750}
.ph-group{scroll-margin-top:80px}
@media(max-width:860px){.ph-cards,.ph-topics{grid-template-columns:minmax(0,1fr)}}
@media(max-width:560px){.ph-drawer{width:100vw}}
.ph-button{padding:10px 12px;border:1px solid #e3e9f2;border-radius:8px;line-height:1.5}
.ph-button strong{color:#142139;font-size:13px}
.ph-button p{margin:3px 0 0;color:#475569;font-size:13px}
.ph-button .ph-use{color:#1f6f43}
details{margin-bottom:8px;padding:10px 12px;border:1px solid #e3e9f2;border-radius:8px}
summary{color:#142139;cursor:pointer;font-weight:700}
details p{margin-top:6px;color:#475569;font-size:13px}
h3{margin:18px 0 6px;color:#142139;font-size:13px;font-weight:800;text-transform:uppercase}
p{margin:0}
ol{margin:0;padding-left:20px;list-style:decimal}
ul{margin:0;padding-left:20px;list-style:disc}
dl{margin:0}
dt{color:#142139;font-weight:800}
dd{margin:0 0 8px;color:#475569;font-size:13px}
@media print{.ph-bar,.ph-backdrop{display:none}}
</style>
