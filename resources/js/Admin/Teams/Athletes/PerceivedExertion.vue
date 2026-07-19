<script setup>
    import { ref, watch, onMounted } from 'vue';
    import Card from '@/Components/NewComponents/Card.vue';

    const { team_id } = defineProps(['team_id']);

    const loading = ref(false);


</script>

<template>
    <Loading :loading="loading" type="table" />
    <div class="col-md-12">
        <Card title="Daily Wellness" subtitle="Monitor athlete condition & recovery" header_class="px-3" >

            <div v-if="!loading">
                <div class="scout-font-color text-center mt-5 mb-5" style="min-height: 150px;">
                    <h4>No RPE Questionnaire</h4>
                    <p>Currently this team does not have a rate of perceived exertion questionnaire associated.</p>
                </div>
            </div>
        </Card>
    </div>
</template>
