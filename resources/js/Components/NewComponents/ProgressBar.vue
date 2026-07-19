<script setup>
    const {num, total, color, text1, text2} = defineProps(['num', 'total', 'color', 'text1', 'text2']);

    function calcPercent(){
        return total ? (num*100)/total : 0;
    }
</script>

<template>
    <div>
    <div class="d-flex justify-content-between mb-1">
        <span class="fs-13 fw-600">{{ text1 }}</span>
        <span class="fs-13 text-muted">{{ text2 }}</span>
    </div>
    <div class="progress">
        <div class="progress-bar" :style="'width: ' + calcPercent() + '%;background:' + color"></div>
    </div>
    </div>
</template>