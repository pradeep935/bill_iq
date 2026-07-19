<script setup>
    import { ref } from 'vue';
    import DeleteTeam from '@/Admin/Teams/Setting/DeleteTeam.vue';
    import EditTeam from '@/Admin/Teams/Setting/EditTeam.vue';
    import ConfigureGps from '@/Admin/Teams/Setting/ConfigureGps.vue';
    import ManageAthletes from '@/Admin/Teams/Setting/ManageAthletes.vue';

    const { team_id } = defineProps(['team_id']);
    const emit = defineEmits(['close']);

    const switchContent = ref('athletes');

    function switchContentFun(content) {
        switchContent.value = content;
    }

    function closePopUp(){
        emit('close');
    }

    const tabs = [
        { key: 'athletes', label: 'Athletes', icon: 'bi bi-people' },
        { key: 'editTeam', label: 'Edit Team', icon: 'bi bi-pencil-square' },
        // { key: 'configureGps', label: 'Configure GPS', icon: 'bi bi-geo-alt' }
    ];

    const dangerTabs = [
        { key: 'deleteTeam', label: 'Delete Team', icon: 'bi bi-trash' }
    ];
</script>
<template>
    <div class="row">
        <div class="col-md-3">
            <div class="sidebar-card">
                <div class="sidebar-group">
                    <button v-for="tab in tabs" :key="tab.key" @click="switchContentFun(tab.key)" :class="['sidebar-item', { active: switchContent === tab.key }]" >
                        <i :class="tab.icon"></i>
                        {{ tab.label }}
                    </button>
                </div>
                <div class="divider"></div>
                <div class="sidebar-group">
                    <button v-for="tab in dangerTabs" :key="tab.key" @click="switchContentFun(tab.key)" :class="['sidebar-item danger', { active: switchContent === tab.key }]" >
                        <i :class="tab.icon"></i>
                        {{ tab.label }}
                    </button>
                </div>

            </div>
        </div>
        <div class="col-md-9">
            <div class="content-card" >
                <DeleteTeam v-if="switchContent == 'deleteTeam'" :team_id="team_id" />

                <EditTeam v-if="switchContent == 'editTeam'" :team_id="team_id" />

                <ManageAthletes v-if="switchContent == 'athletes'" :team_id="team_id" @close="closePopUp" />

                <ConfigureGps v-if="switchContent == 'configureGps'" :team_id="team_id" @close="closePopUp" />
            </div>
        </div>
    </div>
</template>
<style scoped>
    .sidebar-card {
        background: #ffffff;
        /*background: #e6e7e9;*/
        border-radius: 16px;
        padding: 12px;
        box-shadow: 0 8px 24px rgba(0,0,0,0.06);
    }

    .sidebar-group {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .sidebar-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 14px;
        border-radius: 10px;
        border: none;
        background: transparent;
        font-size: 14px;
        color: #555;
        cursor: pointer;
        transition: all 0.25s ease;
    }

    .sidebar-item i {
        font-size: 16px;
    }

    /* Hover */
    .sidebar-item:hover {
        background: #f5f7f9;
        /*background: #ffffff;*/
        color: #222;
        transform: translateX(5px);
    }

    /* Active */
    .sidebar-item.active {
        background: linear-gradient(135deg, #4caf50, #66bb6a);
        color: white;
        box-shadow: 0 6px 14px rgba(76,175,80,0.25);
    }

    /* Divider */
    .divider {
        height: 1px;
        background: #eee;
        margin: 10px 0;
    }

    /* Danger */
    .sidebar-item.danger:hover {
        background: #fdecea;
        color: #c62828;
    }

    .sidebar-item.danger.active {
        background: #c62828;
        color: #fff;
    }

    /* RIGHT CONTENT */
    .content-card {
        background: #fff;
        border-radius: 16px;
        padding: 16px;
        box-shadow: 0 8px 24px rgba(0,0,0,0.05);
    }
</style>