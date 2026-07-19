<script setup>
    import { ref, watch, onMounted, computed } from 'vue';
	const { team_id } = defineProps(['team_id']);
    const emit = defineEmits(['close']);
</script>
<template>
    <div class="card-header">
        <div class="text-start" >
           <h5>Configure GPS</h5>
        </div>
    </div>
    <div class="card-body ">
        
    </div>
</template>