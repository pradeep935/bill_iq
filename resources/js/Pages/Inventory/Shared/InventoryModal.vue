<script setup>
import { computed } from 'vue';

const props = defineProps({ title: { type: String, required: true }, subtitle: { type: String, default: '' }, wide: Boolean, errors: { type: Object, default: () => ({}) } });
const emit = defineEmits(['close']);
const errorList = computed(() => Object.values(props.errors || {}).flatMap((messages) => Array.isArray(messages) ? messages : [messages]).filter(Boolean));
</script>

<template>
    <div class="modal-backdrop" @click.self="emit('close')">
        <section class="modal-box" :class="{wide}">
            <button class="close" type="button" @click="emit('close')">Close</button>
            <header>
                <h2>{{ title }}</h2>
                <p>{{ subtitle }}</p>
            </header>
            <div v-if="errorList.length" class="modal-error-summary">
                <strong>Please check these fields</strong>
                <span v-for="(error, index) in errorList" :key="index">{{ error }}</span>
            </div>
            <slot />
        </section>
    </div>
</template>

<style scoped>
.modal-backdrop{align-items:center;background:rgba(15,23,42,.55);bottom:0;display:flex;justify-content:center;left:0;padding:20px;position:fixed;right:0;top:0;z-index:50}.modal-box{background:#fff;border-radius:8px;box-shadow:0 24px 60px rgba(15,23,42,.28);max-height:88vh;max-width:640px;overflow:auto;padding:22px;position:relative;width:min(94vw,640px)}.modal-box.wide{max-width:1040px;width:min(94vw,1040px)}.close{position:absolute;right:16px;top:16px}header{border-bottom:1px solid #edf1f5;margin-bottom:16px;padding-bottom:12px}h2{font-size:22px;margin:0}p{color:#758197;margin:4px 0 0}.modal-error-summary{background:#fff3f4;border:1px solid #ffd4d8;border-radius:8px;color:#96333a;display:grid;font-size:11px;gap:4px;margin-bottom:14px;padding:10px 12px}.modal-error-summary strong{color:#7d2730;font-size:12px}button{background:#fff;border:1px solid #d8e0eb;border-radius:8px;color:#344159;cursor:pointer;font-size:12px;font-weight:750;min-height:34px;padding:7px 10px}
</style>
