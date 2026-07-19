<script setup>
    import { computed, ref } from 'vue';

    const props = defineProps({
        message: {
            type: String,
            default: '',
        },
        position: {
            type: String,
            default: undefined,
        },
    });

    const show_message = ref(false);
    const tooltipClass = computed(() => {
        if (!props.position) {
            return '';
        }

        return `hover-tooltip--${props.position}`;
    });
</script>

<template>
  <span class="hover-container" @mouseover="show_message = true" @mouseleave="show_message = false">
    <slot></slot>
    <div v-if="show_message" :class="['hover-tooltip', tooltipClass]">
      {{ props.message }}
    </div>
  </span>
</template>

<style scoped>
    .hover-container {
    position: relative;
    }

    .hover-tooltip {
    position: absolute;
    bottom: -50%;
    left: 0;
    background-color: #333;
    color: #fff;
    padding: 6px 10px;
    border-radius: 4px;
    white-space: nowrap;
    font-size: 12px;
    z-index: 1000;
    }

    .hover-tooltip--top {
    bottom: calc(100% + 8px);
    left: 50%;
    transform: translateX(-50%);
    }

    .hover-tooltip--bottom {
    top: calc(100% + 8px);
    bottom: auto;
    left: 50%;
    transform: translateX(-50%);
    }

    .hover-tooltip--left {
    top: 50%;
    bottom: auto;
    left: auto;
    right: calc(100% + 8px);
    transform: translateY(-50%);
    }

    .hover-tooltip--right {
    top: 50%;
    bottom: auto;
    left: calc(100% + 8px);
    transform: translateY(-50%);
    }
</style>
