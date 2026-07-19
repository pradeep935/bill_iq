<script setup>
    import { ref } from 'vue'
    const {
        title,
        defaultOpen = true,
        cls = '',
        icon_cls = '',
        icon_clr = '',
    } = defineProps(['title', 'defaultOpen', 'cls', 'icon_cls', 'icon_clr']);

    const isOpen = ref(defaultOpen);
    const toggle = () => { isOpen.value = !isOpen.value };

    function closeSubSection(){
        isOpen.value = !isOpen.value
    }
</script>

<template>

    <div :class="`oc-section open ${cls}`">
        <button type="button" class="oc-section-header" @click.prevent="closeSubSection()">
            <span><i :class="icon_cls ? 'me-2 ' + icon_cls : ''" :style="icon_clr ? 'color : ' + icon_clr : 'color: var(--primary);'"></i>{{ title }}</span>
            <i class="bi bi-chevron-up oc-section-chevron"></i>
        </button>
        <div class="oc-section-body" v-show="isOpen">
            <slot />
        </div>
    </div>
    <!-- <div :class="`section-block ${cls}`">
        <button type="button" class="section-block-header" @click.prevent="toggle">
            <span class="section-block-title">{{ title }}</span>
            <i class="icons section-block-chevron" :class="isOpen ? 'icon-arrow-up' : 'icon-arrow-down'"></i>
        </button>
        <transition name="section-fade">
            <div v-if="isOpen" class="section-block-body">
                <slot />
            </div>
        </transition>
    </div> -->
</template>

<style scoped>
    .oc-section { border: 1px solid var(--border); border-radius: var(--r-sm); overflow: hidden; margin-bottom: 12px; }
    .oc-section:last-child { margin-bottom: 0; }
    .oc-section-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 11px 14px;
        background: #f8f9fb;
        cursor: pointer;
        border: none;
        width: 100%;
        text-align: left;
        font-size: 13px;
        font-weight: 700;
        color: var(--text-main);
        transition: background .15s;
    }
    .oc-section-header:hover { background: #f1f3f8; }
    .oc-section-chevron {
        font-size: 11px;
        color: var(--text-muted);
        transition: transform .2s;
    }
    .oc-section-body {
        padding: 14px;
        border-top: 1px solid var(--border);
        display: none;
    }
    .oc-section.open .oc-section-body { display: block; }
    .oc-section.open .oc-section-chevron { transform: rotate(180deg); }
</style>
