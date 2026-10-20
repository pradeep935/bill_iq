<script setup>
import { formatDate } from '../../Support/dateFormat.js';
defineProps(['date']);
</script>
<template><span>{{ formatDate(date, '') }}</span></template>
