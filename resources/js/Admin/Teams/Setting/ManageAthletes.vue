<script setup>
    import { ref, watch, onMounted, computed } from 'vue';
    import DBService from '@/Service/Utils/DBService';
    import AddAthletes from '@/Admin/Teams/Athletes/AddAthletes.vue';
    import ModalRight from '@/Components/NewComponents/ModalRight.vue';
    import HoverMessage from '@/Components/NewComponents/HoverMessage.vue';
    import { useForm } from 'vee-validate';

    const { handleSubmit, resetForm } = useForm();

    const { team_id } = defineProps(['team_id']);
    const emit = defineEmits(['close']);

    const loading = ref(false);
    const modal_loading = ref(false);
    const s3_url_link = import.meta.env.VITE_S3_URL;
    const processing = ref(false);

    const base_url = import.meta.env.VITE_APP_URL;
    const athletes = ref([]);
    const player_name = ref('Athlete');
    const player_id = ref(0);
    const teams = ref([]);
    const formData = ref({});

    const searchName = ref('');

    onMounted(() => {
        teamAthletes();
    });

    function teamAthletes(){
        if (team_id > 0) {
            loading.value = true;
            DBService.getData("/api/teams/get-athletes/"+ team_id).then((data)=>{
                if (data.success) athletes.value = data.athletes;
                loading.value = false;
            });
        }
    }

    function addAthletes(){

    }

    const offcanvasRef = ref(null)

    function refreshCanvas() {
        offcanvasRef.value.close() // 👈 reusable close
        teamAthletes();
    };

    function removePlayer(player_id){
        bootbox.confirm("Are you sure you want to delete this ?", function(result){
            if (result) {
                loading.value = true;
                DBService.getData('/api/teams/remove-player/'+player_id).then((data)=>{
                    if (data.success) teamAthletes();
                    bootbox.alert(data.message);
                });
                loading.value = false;
            }
        });
    }

    const filteredAthletes = computed(() => {
        return athletes.value.filter(a =>
            a.name.toLowerCase().includes(searchName.value.toLowerCase())
        );
    });

    function transferAthlete(id, name){
        modal_loading.value = true;
        DBService.getData('/api/teams/get-transfer-params/'+team_id).then((data)=>{
            modal_loading.value = false;
            if (data.success) {
                teams.value = data.transfer_params;
                $('#transfer-athlete').modal('show');
            }
        });
        player_id.value = id;
        player_name.value = name;  
    };

    const onSubmit = handleSubmit(() => {
        processing.value = true;
        formData.value.player_id = player_id;
        bootbox.confirm(
            "Are you sure you want to transfer this athlete? The athlete will be removed from the current team and assigned to the selected team.",
            function(result) {
                if(result){
                    DBService.postData('/api/teams/store-transfer-athlete/'+team_id, formData.value).then((data)=>{
                        if(data.success){
                            resetForm();
                            $('#transfer-athlete').modal('hide');
                            emit('close');
                        }
                        processing.value = false;
                        bootbox.alert(data.message);
                    });
                }
            }   
        );
    });


    function initials(name) {
        if (!name) return '?';
        const parts = name.trim().split(' ');
        return parts.length > 1
            ? (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
            : name.slice(0, 2).toUpperCase();
    };

    function avtarColor(index) {
        const colors = [
            [''],
            ['var(--primary)', '#2563eb'],
            ['linear-gradient(135deg,#0d9488,#0891b2)'],
            ['linear-gradient(135deg,#7c3aed,#9333ea)'],
            ['linear-gradient(135deg,#d97706,#f59e0b)'],
            ['linear-gradient(135deg,#be185d,#e11d48)'],
        ];
        const randomIndex = (index * 5) % colors.length;
        return colors[randomIndex] ;
    };

    function positionColor(pos){
        if(!pos) return 'pos-default'

        const defenders = ['CB','LCB','RCB','LB','RB']
        const midfielders = ['CM','DM','LM','RM','LWB','RWB']
        const forwards = ['CF','SS','LW','RW','AW']

        pos = pos.toUpperCase()

        if(pos === 'GK') return 'pos-gk'
        if(defenders.includes(pos)) return 'pos-df'
        if(midfielders.includes(pos)) return 'pos-mf'
        if(forwards.includes(pos)) return 'pos-fw'

        return 'pos-default'
    }; 
</script>
<template>
    <Loading :loading="loading" />

    <div v-if="!loading" class="modern-card p-3 ">
        <div class="header">
            <div>
                <h5>Manage Athletes</h5>
                <p class="subtitle">Add, transfer or manage your team players</p>
            </div>

            <div class="actions">
                <div class="search-box">
                    <i class="bi bi-search"></i>
                    <input v-model="searchName" placeholder="Search athlete..." />
                </div>
                <button class="btn-outline-primary btn mt-1" type="button" @click="addAthletes()" data-bs-toggle="offcanvas" data-bs-target="#add_athletes" > <i class="bi bi-plus"></i> Add Athletes</button>

                <button class="btn btn-outline-secondary modern-btn" @click="addAthletes()">
                    Invite
                </button>
            </div>
        </div>
        <div v-if="filteredAthletes.length > 0">

            <TableCont>
                <thead>
                    <tr>
                        <th width="60">SN</th>
                        <th>Player</th>
                        <th>Position</th>
                        <th>Foot</th>
                        <th>Club</th>
                        <th class="text-end">#</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(player_obj,index) in filteredAthletes" :key="player_obj.player_id">
                        <td>{{ index + 1  }}</td>
                        <td>
                            <div class="player-cell">
                                <div class="player-avatar-wrapper" :class="positionColor(player_obj.first_pos)">
                                    <img v-if="player_obj.profile_pic" class="player-avatar" :src="s3_url_link + player_obj.profile_pic" />

                                    <div v-else class="player-avatar player-avatar-initials" :style="{ background: avtarColor(index) }">
                                        {{ initials(player_obj.name) }}
                                    </div>

                                    <span class="position-mini">
                                        {{ player_obj.first_pos }}
                                    </span>
                                </div>

                                <div class="player-info">
                                    <a :href="base_url + '/player-information/' + player_obj.id" class="player-name-link text-capitalize">
                                        {{ player_obj.name }}
                                    </a>

                                    <div class="player-meta">
                                        <span>{{ player_obj.age }} yrs</span>

                                        <span v-if="player_obj.height">
                                            • {{ player_obj.height }} cm
                                        </span>

                                        <span v-if="player_obj.weight">
                                            • {{ player_obj.weight }} kg
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="d-flex flex-wrap gap-1">
                                <span class="badge" :class="positionColor(player_obj.first_pos)">
                                    {{ player_obj.first_pos}}
                                </span>
                                <span v-if="player_obj.second_pos" class="badge" :class="positionColor(player_obj.second_pos)">
                                    {{ player_obj.second_pos }}
                                </span>
                            </div>
                        </td>
                        <td>
                            <span class="foot-badge">
                                {{ player_obj.foot }}
                            </span>
                        </td>
                        <td>
                            <span class="text-truncate d-inline-block" style="max-width:180px">
                                {{ player_obj.club_name || '-' }}
                            </span>
                        </td>
                        <td class="text-end">
                            <button class="icon-btn" @click="transferAthlete(player_obj.player_id, player_obj.name)">
                                <i class="bi bi-arrow-left-right"></i>
                            </button>

                            <button class="btn-danger btn-sm btn ms-1" @click="removePlayer(player_obj.player_id)">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                </tbody>
            </TableCont>
        </div>
        <div v-else class="empty-state">
            <i class="bi bi-people"></i>
            <h5>No Players</h5>
            <p>No athletes have been added yet</p>
        </div>

    </div>
    <ModalRight id="add_athletes" title="Add Athletes" ref="offcanvasRef" >
        <AddAthletes :team_id="team_id" @close="refreshCanvas()" />
    </ModalRight>

    <Modal id="transfer-athlete" :title="`Transfer ${player_name}`">
        <Loading :loading="modal_loading" />
        <form @submit.prevent="onSubmit">
            <div class="row">
                <FormSelect label="Select Team" name="team_id" v-model="formData.team_id" :options="teams" req="true" />
            </div>
            <div class="modal-footer mt-3">
                <FormButton cls="btn btn-primary" :processing="processing">
                    Save Changes
                </FormButton>
            </div>
        </form>
    </Modal>

</template>
<style scoped>
   /* .modern-card {
        background: #fff;
        border-radius: 16px;
        box-shadow: 0 8px 24px rgba(0,0,0,0.06);
    }*/

    /* HEADER */
    .header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }

    .subtitle {
        font-size: 12px;
        color: #888;
    }

    /* ACTIONS */
    .actions {
        display: flex;
        gap: 10px;
        align-items: center;
    }

    .search-box {
        position: relative;
    }

    .search-box i {
        position: absolute;
        left: 10px;
        top: 8px;
        color: #aaa;
    }

    .search-box input {
        padding: 6px 10px 6px 30px;
        border-radius: 8px;
        border: 1px solid #eee;
    }

    /* BUTTON */
    .modern-btn {
        border-radius: 8px;
        font-size: 13px;
    }

    /* TABLE */
    .modern-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0 10px;
    }

    .modern-table tbody tr {
        background: #fff;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        border-radius: 10px;
        transition: 0.2s;
    }

    .modern-table tbody tr:hover {
        transform: scale(1.01);
    }

    .modern-table td {
        padding: 12px;
    }

    .icon-btn {
        border: none;
        background: #f5f7f9;
        padding: 6px 10px;
        border-radius: 8px;
        margin-left: 5px;
        cursor: pointer;
    }

    .icon-btn:hover {
        background: #e0e0e0;
    }

    .icon-btn.danger {
        background: #fdecea;
        color: #c62828;
    }  







     .player-cell{
        display:flex;
        align-items:center;
        gap:14px;
    }

    .player-avatar-wrapper{
        position:relative;
        padding:3px;
        border-radius:50%;
        flex-shrink:0;
    }

    .player-avatar-wrapper.pos-gk{
        background:linear-gradient(135deg,#f59e0b,#fbbf24);
    }

    .player-avatar-wrapper.pos-df{
        background:linear-gradient(135deg,#2563eb,#60a5fa);
    }

    .player-avatar-wrapper.pos-mf{
        background:linear-gradient(135deg,#10b981,#34d399);
    }

    .player-avatar-wrapper.pos-fw{
        background:linear-gradient(135deg,#ef4444,#fb7185);
    }

    .player-avatar{
        width:56px;
        height:56px;
        border-radius:50%;
        object-fit:cover;
        background:#fff;
        border:2px solid #fff;
        transition:.25s ease;
        box-shadow:0 4px 12px rgba(0,0,0,.12);
    }

    .player-cell:hover .player-avatar{
        transform:scale(1.08);
    }

    .player-avatar-initials{
        display:flex;
        align-items:center;
        justify-content:center;
        color:#fff;
        font-size:15px;
        font-weight:700;
    }

    .position-mini{
        position:absolute;
        bottom:-2px;
        right:-2px;
        background:#0f172a;
        color:#fff;
        font-size:10px;
        font-weight:700;
        padding:2px 6px;
        border-radius:20px;
        border:2px solid #fff;
    }

    .player-info{
        display:flex;
        flex-direction:column;
        min-width:0;
    }

    .player-name-link{
        font-size:14px;
        font-weight:700;
        color:#1e3a8a;
        text-decoration:none;
    }

    .player-name-link:hover{
        color:#2563eb;
    }

    .player-meta{
        display:flex;
        flex-wrap:wrap;
        gap:4px;
        margin-top:2px;
        font-size:12px;
        color:#6b7280;
    }

    .player-tags{
        display:flex;
        flex-wrap:wrap;
        gap:4px;
        margin-top:5px;
    }

    .player-tags .badge{
        font-size:10px;
        font-weight:600;
    }

    .foot-badge{
        background:#f1f5f9;
        color:#334155;
        padding:5px 10px;
        border-radius:20px;
        font-size:12px;
        font-weight:600;
    }

    tbody tr{
        transition:.2s ease;
    }

    tbody tr:hover{
        background:#f8fafc;
    }
    .created-name{
        font-size:13px;
        font-weight:600;
        color:#111827;
    }

    .created-date{
        font-size:11px;
        color:#6b7280;
        margin-top:2px;
    }   
</style>