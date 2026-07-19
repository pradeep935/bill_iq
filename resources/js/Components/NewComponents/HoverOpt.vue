<script setup>
    import { ref, onMounted, watch } from 'vue';

    const {show_box=false, show_hover=false, display_all = false} = defineProps(['show_box','show_hover','display_all']);

    const show_message = ref(false);
</script>

<template>
  <span class="hover-container" v-if="show_box || display_all" @mouseover="show_message = true" @mouseleave="show_message = false">
    <slot></slot>
    <div v-if="(show_message && show_box && show_hover) || display_all" class="hover-tooltip">
        <slot name="display_message"></slot>
    </div>
  </span>
</template>

<style scoped>
    .hover-container {
    position: relative;
    }

    .hover-tooltip {
    position: absolute;
    bottom: 60%;
    left: 50%;
    background-color: #333;
    color: #fff;
    padding: 6px 10px;
    border-radius: 4px;
    white-space: nowrap;
    font-size: 12px;
    z-index: 1000;
    }
</style>
