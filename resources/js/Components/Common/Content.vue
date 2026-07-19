<script setup>
const { title, show_header = false } = defineProps(['title','show_header'])
</script>

<template>
  <div class="content-wrap">
    <slot></slot>
  </div>
</template>