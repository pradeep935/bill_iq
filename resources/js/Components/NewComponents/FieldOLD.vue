<script setup>
    import { ref, onMounted, watch } from 'vue';
    import DBService from '@/Service/Utils/DBService.js'
    import HoverFieldMessage from '@/Components/NewComponents/HoverFieldMessage.vue';

    const {position_arr=[], width=160, rotate = false} = defineProps(['position_arr','width','rotate']);

    watch(
        () => width,
        () => { calcHeight(); }
    )

    onMounted(() => {
        calcHeight();
        getPositions();
    });


    const base_url = import.meta.env.VITE_APP_URL;
    const field_url = "/main/images/football-ground.png";
    const height = ref(108);
    const position_list = ref([]);
    const hover_pos_id = ref(0);

    function calcHeight(){
        height.value = (width * 59)/80;
    }

    function getPositions(){
        DBService.getData('/api/fetch-player-positions') .then( (data) => {
            if(data.success){
                position_list.value = data.position_list;
            } else{
                bootbox.alert(data.message);
            }
        });
    }

    function checkPosition(position_id){
        if(position_arr.includes(position_id)){
            return true;
        }
        return false;
    }

    function hoverIdChange(temp_pos_id){
        hover_pos_id.value = temp_pos_id;
    }
</script>

<template>
    <div :style="'position: relative; background-color: #e6e6e6; width:' + width + 'px; height:' + height + 'px;'" :class="rotate ? 'rotate-this' : ''">
        <img :src="base_url + field_url" style="position: absolute; top: 0; left: 0; width: 100%; height: auto;" draggable="false">
        <span :style="'position: absolute; top:' + position.position_top + '%; left:' + position.position_left + '%; transform: translate(-50%, -50%); font-size: 10px; color: #0059b3; cursor: pointer;'" :class="(hover_pos_id == position.id) ? 'z-index1' : 'z-index2'" v-for="position in position_list"><HoverFieldMessage :position_id="position.id" @callback="(args) => hoverIdChange(args)" :message="position.position_short" v-if="checkPosition(position.id)" :class="rotate ? 'counter-rotate' : ''">&#9673;</HoverFieldMessage></span>
    </div>
</template>

<style scoped>
    .rotate-this {
        transform: rotate(-90deg);
        transform-origin: center center;
        display: inline-block;
        width: fit-content;
        height: fit-content;
    }

    .counter-rotate {
        transform: rotate(90deg);
        display: inline-block;
    }

    .z-index1{
        z-index: 9999;
    }

    .z-index2{
        z-index: 9;
    }
</style>