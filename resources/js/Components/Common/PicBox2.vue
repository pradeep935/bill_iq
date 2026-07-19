<script setup>
const { pic, color, s3=false, width=40, showChange=false } = defineProps(['pic','color','s3','width','showChange'])
const s3_url_link = import.meta.env.VITE_S3_URL
</script>

<template>
    <div class="table-row-pic" :style="{'height': width+'px', 'width': width+'px', 'position': 'relative','overflow':'hidden','border-radius':'50%'}">
        <img :src="pic" v-if="!s3" :style="{'height': width+'px', 'width': width+'px',  border: '3px solid' + color}" />
        <img :src="s3_url_link+'/'+pic" v-if="s3" :style="{'height': width+'px', 'width': width+'px',  border: '3px solid' + color}" />
        <a v-if="showChange" href="#" class="change-pic" :style="{'background-color': color, 'width': width+'px', 'margin-left' : (width*-1/2) +'px'}">Change</a>
    </div>

</template>

<style scoped>
    a.change-pic {
        position: absolute;
        bottom: 4px;
        color: black;
        opacity: 0.7;
        font-size: 12px;
        line-height: 1;
        left: 50%;
        text-align: center;
        padding: 5px 0;
    }
</style>