<script setup>
    import { ref, onMounted, watch } from 'vue';
    import DBService from '@/Service/Utils/DBService.js'
    import LengthZero from '@/Components/NewComponents/LengthZero.vue';
    import CustomField from '@/Pages/RecruitmentTracker/RecruitmentPosition/CustomField.vue';

    const {reload, def_filters} = defineProps(['reload','def_filters']);
    const base_url = import.meta.env.VITE_APP_URL;

    onMounted(
        () => {
            getEnteredPositions();
        }
    )

    const loading = ref(false);
    const position_list = ref([]);
    const rec_pos_list = ref([]);
    const check_position_id = ref(0);

    function getEnteredPositions(){
        console.log(def_filters);
        loading.value = true;
        DBService.getData('/api/recruitment-tracker/recruitment-position/get-all-positions/' + def_filters.rec_id).then((data) => {
            if(data.success){
                position_list.value = data.position_list;
            }
            loading.value = false;
        });
    }

    function displayRecruitmentPositions(position_id){
        if(check_position_id.value == position_id){
            check_position_id.value = 0;
            rec_pos_list.value = [];
            return;
        } else{
            check_position_id.value = position_id;
        }
        loading.value = true;
        DBService.getData('/api/recruitment-tracker/recruitment-position/get-all-recruitment-positions/' + def_filters.rec_id + '/' + position_id).then((data) => {
            if(data.success){
                rec_pos_list.value = data.rec_pos_list;
            }
            loading.value = false;
        });
    }
</script>
<template>
    <div class="row g-4">
        <div class="col-md-8">
            <div class="card shadow-sm border-0">
                <div class="card-body p-3">
                    <CustomField :position_arr="position_list" width="800" @sideBox="(args) => displayRecruitmentPositions(args)" />
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                    <span>Players</span>
                    <span class="badge bg-primary" v-if="rec_pos_list.length">
                        {{ rec_pos_list.length }}
                    </span>
                </div>
                <div class="card-body p-0 position-relative">
                    <div v-if="loading" class="p-3">
                        <div class="skeleton mb-2" v-for="i in 5" :key="i"></div>
                    </div>
                    <div v-else-if="rec_pos_list.length > 0" class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>Name</th>
                                    <th>Position</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="rec_pos_obj in rec_pos_list" :key="rec_pos_obj.id">
                                    <td>
                                        <a :href="base_url + '/recruitment-tracker/recruitment-position/players/' + rec_pos_obj.id" class="player-link" target="_blank">
                                            {{ rec_pos_obj.name }}
                                        </a>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark">
                                            {{ rec_pos_obj.display_positions }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-else class="empty-state">
                        <p>Select a position to view players</p>
                    </div>

                </div>
            </div>
        </div>
    </div>
</template>
<style scoped>
    .card {
        border-radius: 12px;
    }

    .player-link {
        color: #2563eb;
        font-weight: 500;
        text-decoration: none;
    }

    .player-link:hover {
        text-decoration: underline;
    }

    .empty-state {
        height: 200px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        color: #9ca3af;
    }

    /* Skeleton Loader */
    .skeleton {
        height: 14px;
        background: linear-gradient(90deg, #eee, #f5f5f5, #eee);
        border-radius: 6px;
        animation: shimmer 1.2s infinite;
    }

    @keyframes shimmer {
        0% { background-position: -200px 0; }
        100% { background-position: 200px 0; }
    }

    /* Table hover effect */
    .table-hover tbody tr:hover {
        background-color: #f9fafb;
        cursor: pointer;
    }

</style>