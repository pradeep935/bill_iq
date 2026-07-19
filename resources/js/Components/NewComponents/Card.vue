<script setup>
const { title, show_footer = false, header_class='', body_class='', footer_class='', type='card' } = defineProps(['title','show_footer','header_class','body_class','footer_class','type'])
</script>

<template>
    <div :class=" type == 'table' ? 'table-card' : 'card'">
        <div :class="`card-header ${header_class}`">
            <h5 class="card-title"><strong>{{ title }}</strong></h5>
            <slot name="header_slot"></slot>
        </div>
        <div :class="body_class">
            <slot></slot>
        </div>
        <div class="card-footer" :class="footer_class" v-if="show_footer">
            <slot name="footer_slot"></slot>
        </div>
    </div>
</template>