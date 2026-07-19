<script setup>
import { computed } from 'vue';
const { text, length } = defineProps(['text','length'])

const processedHtml = computed(() => {

  if(!text){
    return "";
  }

  if(text.length > length) {
    return text.substr(0,length)+'...';
  } else {
    return text;
  }
});

</script>

<template>
  <span v-html="processedHtml"></span>
</template>