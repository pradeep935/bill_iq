<script setup>
    const { cls='',cls2='bi bi-exclamation-circle'} = defineProps(['cls','cls2']);
</script>

<template>
    <div class="empty-state" :class="cls">
        <div class="empty-state-icon">
            <i :class="cls2"></i>
        </div>
        <p class="empty-state-text">No records found</p>
    </div>
</template>
