<script setup>
    import { ref, onMounted, watch } from 'vue';
    import DBService from '@/Service/Utils/DBService.js'
    import HoverFieldMessage from '@/Components/NewComponents/HoverFieldMessage.vue';

    const {position_arr=[], width=160, rotate = false, display_sec_color = false} = defineProps(['position_arr','width','rotate','display_sec_color']);

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

    function checkSecondPos(pos_id){
        if(position_arr[1] == pos_id){
            return true;
        }
        return false;
    }
</script>

<template>
    <div :style="'position: relative; background-color: #ffff; width:' + width + 'px; height:' + height + 'px;'" :class="rotate ? 'rotate-this' : ''">
        <img :src="base_url + field_url" style="position: absolute; top: 0; left: 0; width: 100%; height: auto;" draggable="false">
       <!--  <span :style="'position: absolute; top:' + position.position_top + '%; left:' + position.position_left + '%; transform: translate(-50%, -50%); font-size: 20px; color: #26a645; cursor: pointer;'" :class="(hover_pos_id == position.id) ? 'z-index1' : 'z-index2'" v-for="position in position_list">
            <HoverFieldMessage :position_id="position.id" @callback="(args) => hoverIdChange(args)" :message="position.position_short" v-if="checkPosition(position.id)" :class="rotate ? 'counter-rotate' : ''">&#9673;</HoverFieldMessage>
        </span>  -->

        <span
            :style="`
                position:absolute;
                top:${position.position_top}%;
                left:${position.position_left}%;
                transform:translate(-50%, -50%);
                cursor:pointer;
            `"
            :class="(hover_pos_id == position.id) ? 'z-index1' : 'z-index2'"
            v-for="position in position_list"
            :key="position.id"
        >
            <HoverFieldMessage
                :position_id="position.id"
                @callback="(args) => hoverIdChange(args)"
                :message="position.position_short"
                v-if="checkPosition(position.id)"
                :class="rotate ? 'counter-rotate' : ''"
            >
                <span class="field-position-dot" :class="position_arr.length == 2 && checkSecondPos(position.id) && display_sec_color ? 'second-pos' : ''"></span>
            </HoverFieldMessage>
        </span>
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

    .second-pos{
        border: 4px solid #6699ff !important;
    }

    .second-pos::after{
        background: #6699ff !important;
    }

    .field-position-dot {
        width: 19px;
        height: 19px;
        border: 4px solid #2cab52;
        border-radius: 50%;
        background: #fff;
        display: inline-block;
        position: relative;
        box-sizing: border-box;
        transition: all .2s ease;
    }

    .field-position-dot::after {
        content: '';
        width: 7px;
        height: 7px;
        background: #2cab52;
        border-radius: 50%;
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
    }

    .field-position-dot:hover {
        transform: scale(1.15);
    }
</style>