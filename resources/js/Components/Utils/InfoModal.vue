<script setup>
import { computed } from 'vue';
const { modal_type, modal_info , id} = defineProps(['modal_type','modal_info','id'])


function openWhatsApp(number, text) {
  window.open('https://web.whatsapp.com:/send?phone=+91'+number+'&text='+text);  
}

</script>

<template>
  <Modal :id="id">
    <div v-if="modal_type == 'mobile' " class="text-center" style="font-size: 20px">
        <a :href="`tel: ${modal_info}`">{{ modal_info }}</a> <button @click="openWhatsApp(modal_info,'')" style="border: none; background: transparent; margin-left: 15px">
          <img src="/assets/images/whatsapp.png" style="width: 32px; height: auto" />
        </button>
    </div>

    <div v-if="modal_type == 'email' " class="text-center" style="font-size: 16px">
        <a :href="`mailto: ${modal_info}`">{{ modal_info }}</a>
    </div>
  </Modal>
</template>