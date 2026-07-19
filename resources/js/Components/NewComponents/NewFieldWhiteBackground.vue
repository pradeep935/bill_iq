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
    <div class="football-pitch" :class="rotate ? 'rotate-this' : ''" :style="{width: width + 'px', height: height + 'px'}">
        <!-- Center Line -->
        <div class="center-line"></div>

        <!-- Center Circle -->
        <div class="center-circle"></div>

        <!-- Left Penalty -->
        <div class="penalty-box left-box"></div>

        <div class="penalty-box right-box"></div>

        <!-- Player Positions -->
        <template v-for="position in position_list" :key="position.id">
            <span v-if="checkPosition(position.id)" class="player-position" :style="{ top: position.position_top + '%', left: position.position_left + '%'}" :class="(hover_pos_id == position.id) ? 'z-index1' : 'z-index2'" >
                <HoverFieldMessage :position_id="position.id" @callback="(args) => hoverIdChange(args)" :message="position.position_short" :class="rotate ? 'counter-rotate' : ''" >
                    <span class="player-dot"></span>
                </HoverFieldMessage>
            </span>
        </template>
    </div>
</template>
<style scoped>

.football-pitch{
    position: relative;
    background: #ffffff;;
    border: 2px solid #000;
    border-radius: 10px;
    overflow: hidden;
}

/* CENTER LINE */

.center-line{
    position: absolute;
    top: 0;
    left: 50%;
    width: 2px;
    height: 100%;
    background: #000;
    transform: translateX(-50%);
}

/* CENTER CIRCLE */

.center-circle{
    position: absolute;
    width: 42px;
    height: 42px;
    border: 2px solid #000;
    border-radius: 50%;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
}

/* PENALTY BOXES */

.penalty-box{
    position: absolute;
    width: 18%;
    height: 42%;
    border: 2px solid #000;
    top: 50%;
    transform: translateY(-50%);
}

.left-box{
    left: 0;
    border-left: none;
}

.right-box{
    right: 0;
    border-right: none;
}

/* PLAYER POSITION */

.player-position{
    position: absolute;
    transform: translate(-50%, -50%);
}

/* BLUE DOT */

.player-dot{
    width: 11px;
    height: 11px;
    background: #0066ff;
    border-radius: 50%;
    display: inline-block;
    border: 1.5px solid #fff;
    box-shadow: 0 2px 6px rgba(0,0,0,0.25);
    transition: 0.2s ease;
    cursor: pointer;
}

.player-dot:hover{
    transform: scale(1.25);
}

/* ROTATION */

.rotate-this {
    transform: rotate(-90deg);
    transform-origin: center center;
    display: inline-block;
}

.counter-rotate {
    transform: rotate(90deg);
    display: inline-block;
}

/* Z INDEX */

.z-index1{
    z-index: 9999;
}

.z-index2{
    z-index: 9;
}

</style>