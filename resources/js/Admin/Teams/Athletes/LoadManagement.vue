<script setup>
    import { ref, watch, onMounted, computed } from 'vue';
    import DBService from '@/Service/Utils/DBService';
    import Card from '@/Components/NewComponents/Card.vue';
    const { team_id , athlete } = defineProps(['team_id', 'athlete']);

    const loading = ref(false);
    const searchName = ref('');
    const loadness = ref([]);
    const base_url = import.meta.env.VITE_APP_URL;

    watch(() => athlete.id, () => {
        getLoadManagement();
    });

    onMounted(() => {
        getLoadManagement();
    });

    function getLoadManagement(){
        if (athlete.id > 0) {
            loading.value = true;
            DBService.postData('/api/teams/get-loadness/'+team_id + '/' + athlete.player_id)
            .then((data)=>{
                if (data.success) {
                    loadness.value = data.loadness;
                }
                loading.value = false;
            });
        }
    }
</script>
<template>
    <Loading :loading="loading" type="table" />
    <div class="col-md-12">
        <Card title="Daily Wellness"  header_class="px-3" >
            <div v-if="!loading" class="p-3">
                <LengthZero v-if="loadness.length == 0"/>
            </div>
        </Card>
    </div>
</template>