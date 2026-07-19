<script setup>
    import { ref, watch, onMounted } from 'vue';
    import LoadManagement from '@/Admin/Teams/Athletes/LoadManagement.vue';
    import PerceivedExertion from '@/Admin/Teams/Athletes/PerceivedExertion.vue';
    import Wellness from '@/Admin/Teams/Athletes/Wellness.vue';

    const { team_id, athlete } = defineProps(['team_id','athlete']);

    watch(()=>athlete,
        (newVal)=>{
            if (newVal) {
                 switchContent.value = 'loadManagement';
            }
        });

    const loading = ref(false);

    const switchContent = ref('loadManagement');

    function switchContentFun(content){
        switchContent.value = content;
    }

    const tabs = [
        { key: 'loadManagement', label: 'Load Management', icon: 'bi bi-activity' },
        // { key: 'perceivedExertion', label: 'Exertion', icon: 'bi bi-speedometer2' },
        { key: 'wellness', label: 'Wellness', icon: 'bi bi-heart-pulse' },
        // { key: 'media', label: 'Media', icon: 'bi bi-image' }
    ];
</script>

<template>
    <Loading :loading="loading" type="table" />
 <!--    <div class="modern-tabs">
        <button  v-for="tab in tabs"  :key="tab.key" @click="switchContentFun(tab.key)" :class="['tab-btn', { active: switchContent === tab.key }]">
            <i :class="tab.icon"></i>
            {{ tab.label }}
        </button>
    </div> -->
    <LoadManagement :team_id="team_id" v-if="switchContent == 'loadManagement'"  :athlete="athlete" />
    <PerceivedExertion :team_id="team_id" v-if="switchContent == 'perceivedExertion'" />
    <!-- <Wellness :team_id="team_id" v-if="switchContent == 'wellness'" :athlete_id="athlete.player_id" /> -->

</template>
<style>
    .modern-tabs {
        display: flex;
        gap: 10px;
        background: #f5f7f9;
        padding: 6px;
        border-radius: 12px;
        width: fit-content;
    }

    .tab-btn {
        border: none;
        background: transparent;
        padding: 8px 16px;
        border-radius: 10px;
        font-size: 13px;
        color: #777;
        display: flex;
        align-items: center;
        gap: 6px;
        transition: all 0.25s ease;
        cursor: pointer;
    }

    .tab-btn i {
        font-size: 14px;
    }

    .tab-btn:hover {
        background: rgba(0,0,0,0.05);
        color: #333;
    }

    .tab-btn.active {
        background: #ffffff;
        color: #000;
        font-weight: 600;
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    }

    .tab-btn {
        transform: scale(0.95);
    }

    .tab-btn.active {
        transform: scale(1);
    }
            
</style>
