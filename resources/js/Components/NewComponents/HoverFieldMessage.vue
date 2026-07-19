<script setup>
    import { ref, onMounted, watch } from 'vue';

    const {message, position_id} = defineProps(['message','position_id']);
    const emit = defineEmits(['callback']);

    const show_message = ref(false);

    function hoverHere(){
      show_message.value = true;
      emit('callback',position_id);
    }


  function leaveHere() {
      show_message.value = false;
      emit('callback', 0); // reset
  }
</script>

<template>
  <span class="hover-container" @mouseover="hoverHere()"   @mouseleave="leaveHere" >
    <slot></slot>
    <div v-if="show_message" class="hover-tooltip">
      {{ message }}
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
</style>
