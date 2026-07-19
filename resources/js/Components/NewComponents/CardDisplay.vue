<script setup>
    const {cls, arr = [], obj = {}, title, show_footer = false, header_class='', body_class='', footer_class='', type='card'} = defineProps(['cls', 'arr', 'obj', 'title','show_footer','header_class','body_class','footer_class','type']);

    function displayDate(date_time, type){
        if(!date_time){
            return '';
        }

        const date = new Date(date_time);

        if(Number.isNaN(date.getTime())){
            return date_time;
        }

        const month = date.toLocaleString('en-US', { month: 'short' });
        const day = String(date.getDate()).padStart(2, '0');
        const year = date.getFullYear();
        const hour12 = date.getHours() % 12 || 12;
        const hours = String(hour12).padStart(2, '0');
        const minutes = String(date.getMinutes()).padStart(2, '0');
        const seconds = String(date.getSeconds()).padStart(2, '0');
        const meridiem = date.getHours() >= 12 ? 'pm' : 'am';

        if(type == 1){
            return `${month} ${day}, ${year}`;
        } else if(type == 2){
            return `${month} ${day}, ${year} ${hours}:${minutes}:${seconds} ${meridiem}`;
        } else{
            return `${hours}:${minutes}:${seconds} ${meridiem}`;
        }
    }
</script>

<template>
    <div :class="cls">
        <div :class=" type == 'table' ? 'table-card' : 'card'">
            <div :class="`card-header ${header_class}`">
                <h5 class="card-title"><strong>{{ title }}</strong></h5>
                <slot name="header_slot"></slot>
            </div>
            <div :class="body_class">
                <div class="card-info" v-for="(info, idx) in arr">
                    <span class="card-label">{{ info.label }}</span>
                    <span class="card-value" v-if="info.type == 'text'">
                        <i v-if="info.show_icon" :class="info.icon" :style="'color: ' + (info.icon_color ? info.icon_color : 'black')"></i>
                        {{ obj[info.value] ? obj[info.value] : '-' }}
                    </span>
                    <span class="card-value" v-if="info.type == 'money'">
                        <i v-if="info.show_icon" :class="info.icon" :style="'color: ' + (info.icon_color ? info.icon_color : 'black')"></i>
                        <Money :amount="obj[info.value]"></Money>
                    </span>
                    <span class="card-value" v-else-if="info.type == 'link'">
                        <i v-if="info.show_icon" :class="info.icon" :style="'color: ' + (info.icon_color ? info.icon_color : 'black')"></i>
                        <a :href="obj[info.value]" v-if="obj[info.value]" target="_blank">
                            {{ info.link_text ? info.link_text : obj[info.value] }}
                        </a>
                        <span v-else>-</span>
                    </span>
                    <span class="card-value" v-else-if="info.type == 'options'">
                        <i v-if="info.show_icon" :class="info.icon" :style="'color: ' + (info.icon_color ? info.icon_color : 'black')"></i>
                        {{ obj[info.value] ? info.opts[obj[info.value]] : '-' }}
                    </span>
                    <span class="card-value" v-else-if="info.type == 'date'">
                        <i v-if="info.show_icon" :class="info.icon" :style="'color: ' + (info.icon_color ? info.icon_color : 'black')"></i>
                        <span v-if="obj[info.value]">{{ displayDate(obj[info.value], info.format_type) }}</span>
                        <span v-else>-</span>
                    </span>
                </div>
            </div>
            <div class="card-footer" :class="footer_class" v-if="show_footer">
                <slot name="footer_slot"></slot>
            </div>
        </div>
    </div>
</template>

<style scoped>
    .card-label{
        color: #8c8c8c;
    }
    .card-value{
        color: #000000;
        font-weight: 700;
        padding: 0px 4px;
    }
    .card-info{
        display: flex;
        justify-content: space-between;
        flex-direction: column;
        padding: 5px 30px;
    }
    
    .insta-icon {
        background: linear-gradient(
        45deg,
        #833AB4,
        #C13584,
        #FD1D1D,
        #FCAF45
        );
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
    }
</style>
