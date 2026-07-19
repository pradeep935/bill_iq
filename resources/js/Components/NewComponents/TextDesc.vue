<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    text: {
        type: String,
        default: '',
    },
    color: {
        type: String,
        default: null,
    },
    max: {
        type: Number,
        default: 80,
    },
});

const showPopover = ref(false);
const wrapperRef = ref(null);
const triggerRef = ref(null);
const popoverRef = ref(null);
const popoverStyle = ref({
    top: '0px',
    left: '0px',
});

const safeText = computed(() => props.text || '');
const isTruncated = computed(() => safeText.value.length > props.max);
const trimmedText = computed(() => {
    if (!isTruncated.value) {
        return safeText.value;
    }

    return `${safeText.value.slice(0, props.max).trimEnd()}...`;
});

async function togglePopover() {
    if (!isTruncated.value) {
        return;
    }

    showPopover.value = !showPopover.value;

    if (showPopover.value) {
        await nextTick();
        updatePopoverPosition();
    }
}

function closePopover() {
    showPopover.value = false;
}

function updatePopoverPosition() {
    if (!triggerRef.value || !popoverRef.value) {
        return;
    }

    const triggerRect = triggerRef.value.getBoundingClientRect();
    const popoverRect = popoverRef.value.getBoundingClientRect();
    const viewportWidth = window.innerWidth;
    const viewportHeight = window.innerHeight;
    const gap = 10;

    let left = triggerRect.left;
    let top = triggerRect.bottom + gap;

    if (left + popoverRect.width > viewportWidth - 16) {
        left = viewportWidth - popoverRect.width - 16;
    }

    if (left < 16) {
        left = 16;
    }

    if (top + popoverRect.height > viewportHeight - 16) {
        top = triggerRect.top - popoverRect.height - gap;
    }

    if (top < 16) {
        top = 16;
    }

    popoverStyle.value = {
        top: `${top}px`,
        left: `${left}px`,
    };
}

function handleOutsideClick(event) {
    if (!wrapperRef.value || wrapperRef.value.contains(event.target)) {
        return;
    }

    closePopover();
}

onMounted(() => {
    document.addEventListener('click', handleOutsideClick);
    window.addEventListener('resize', updatePopoverPosition);
    window.addEventListener('scroll', updatePopoverPosition, true);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', handleOutsideClick);
    window.removeEventListener('resize', updatePopoverPosition);
    window.removeEventListener('scroll', updatePopoverPosition, true);
});
</script>

<template>
    <div ref="wrapperRef" class="expandable-description">
        <span class="expandable-description__text" :style="props.color ? 'color: ' + props.color : ''">{{ trimmedText || '-' }}</span>
        <button
            v-if="isTruncated"
            ref="triggerRef"
            type="button"
            class="expandable-description__trigger"
            @click.stop="togglePopover"
        >
            {{ showPopover ? 'Show less' : 'Show more' }}
        </button>

        <Teleport to="body">
            <transition name="description-popover">
                <div
                    v-if="showPopover"
                    ref="popoverRef"
                    class="expandable-description__popover"
                    :style="popoverStyle"
                    @click.stop
                >
                    <div class="expandable-description__popover-header">
                        <span>Full description</span>
                        <button
                            type="button"
                            class="expandable-description__close"
                            @click.stop="closePopover"
                        >
                            ×
                        </button>
                    </div>
                    <p class="expandable-description__content">{{ safeText }}</p>
                </div>
            </transition>
        </Teleport>
    </div>
</template>

<style scoped>
    .expandable-description {
        position: relative;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        flex-wrap: wrap;
        max-width: 100%;
    }

    .expandable-description__text {
        color: #243046;
        line-height: 1.45;
        word-break: break-word;
    }

    .expandable-description__trigger {
        border: 0;
        background: transparent;
        color: #0d6efd;
        font-size: 12px;
        font-weight: 600;
        padding: 0;
        cursor: pointer;
        text-decoration: underline;
        text-underline-offset: 2px;
    }

    .expandable-description__trigger:hover {
        color: #0a58ca;
    }

    .expandable-description__popover {
        position: fixed;
        min-width: 260px;
        max-width: 360px;
        max-height: min(320px, calc(100vh - 32px));
        background: #ffffff;
        border: 1px solid #d8e2f0;
        border-radius: 12px;
        box-shadow: 0 14px 32px rgba(15, 23, 42, 0.14);
        padding: 12px;
        overflow: auto;
        z-index: 2000;
    }

    .expandable-description__popover-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        margin-bottom: 8px;
        color: #162033;
        font-size: 12px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    .expandable-description__close {
        border: 0;
        background: #eef4ff;
        color: #335ea8;
        width: 24px;
        height: 24px;
        border-radius: 999px;
        cursor: pointer;
        font-size: 16px;
        line-height: 1;
    }

    .expandable-description__content {
        margin: 0;
        color: #334155;
        font-size: 13px;
        line-height: 1.6;
        white-space: pre-wrap;
        word-break: break-word;
    }

    .description-popover-enter-active,
    .description-popover-leave-active {
        transition: opacity 0.18s ease, transform 0.18s ease;
    }

    .description-popover-enter-from,
    .description-popover-leave-to {
        opacity: 0;
        transform: translateY(-4px);
    }
</style>
