<script setup>
    const {img_src, btn_color, title='', height='200px', width='200px', img_style=''} = defineProps(['img_src','btn_color','title','height','width','img_style']);

    const emit = defineEmits(['callbackbtn']);

    function changeBtn(){
        emit('callbackbtn');
    }
</script>

<template>
    <div>
        <h4>{{title}}</h4>
        <div class="pic-box" :style="'height :' + height + '; width :' + width">
            <img class="round" :src="img_src" v-if="img_src" :style="'height :' + height + '; width :' + width + '; ' + img_style">
            <a href="#" v-if="btn_color" class="change-pic" @click.prevent="changeBtn()" style="" :style="'width :' + width + '; ' + 'background-color: ' + btn_color">Change</a>
        </div>
    </div>
</template>

<style scoped>
    .pic-box {
        position: relative;
        overflow: hidden;
        border-radius: 50%;
        margin: 0 auto;
        
    }
    .pic-box a.change-pic {
        position: absolute;
        bottom: 4px;
        color: black;
        opacity: 0.7;
        font-size: 12px;
        line-height: 1;
        left: 50%;
        margin-left: -100px;
        text-align: center;
        padding: 5px 0;
    }
    img.round{
        border-radius:50%;
    }
</style>