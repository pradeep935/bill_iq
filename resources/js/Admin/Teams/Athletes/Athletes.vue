<script setup>
    import { ref, watch, onMounted, computed } from 'vue';
    import DBService from '@/Service/Utils/DBService';
    import AddAthletes from '@/Admin/Teams/Athletes/AddAthletes.vue';
    import AthletesCradBody from '@/Admin/Teams/Athletes/AthletesCradBody.vue';
    import ModalRight from '@/Components/NewComponents/ModalRight.vue';
    import ShadowTeams from '@/Pages/PlayerInformation/ShadowTeams.vue';
    import Field from '@/Components/NewComponents/Field.vue';
    import TextDesc from '@/Components/NewComponents/TextDesc.vue';
    import InfoBox from '@/Components/NewComponents/InfoBox.vue';
    import LoadManagement from '@/Admin/Teams/Athletes/LoadManagement.vue';
    import PerceivedExertion from '@/Admin/Teams/Athletes/PerceivedExertion.vue';
    import Wellness from '@/Admin/Teams/Athletes/Wellness.vue';

    const { team_id } = defineProps(['team_id']);
    const emit = defineEmits(['athletes-loaded']);
    const base_url = import.meta.env.VITE_APP_URL;
    const loading = ref(false);
    const athletes = ref([]);
    const showCard = ref(false);
    const athlete = ref({id: -1});
    const searchName = ref('');
    const name = ref("");
    const switchContent = ref(0);
    const initialsName = ref(null);
    const colorAvtar = ref(null);
    const s3_url_link = import.meta.env.VITE_S3_URL;
    const active_sub_tab = ref(0);
    const stats = ref([]);
    
    onMounted(() => {
        teamAthletes();
    });

    function teamAthletes(){
        if (team_id > 0) {
            loading.value = true;
            DBService.getData("/api/teams/get-athletes/"+ team_id).then((data)=>{
                if (data.success) {
                    athletes.value = data.athletes;
                    if (data.athletes && data.athletes.length > 0) {
                        switchContentFun(0,athletes.value[0]);
                       
                    }
                    emit('athletes-loaded', data.athletes);
                }
                loading.value = false;
            });
        } else{

        }
    };

    function getBirthQuartile(dob){
        const month = new Date(dob).getMonth() + 1;
        if(month <= 3) return 'Q1';
        if(month <= 6) return 'Q2';
        if(month <= 9) return 'Q3';
        return 'Q4';
    }

    function getPotenial(p_id){
        if(p_id == 1) return 'Low';
        if(p_id == 2) return 'Medium';
        if(p_id == 3) return 'High';
    }

    function addAthletes(){
    };

    const offcanvasRef = ref(null);

    function closePopUp(){
        offcanvasRef.value.close();
        teamAthletes();

    };

    function switchContentFun(content, ath_lete) {
        showCard.value = true;
        athlete.value = ath_lete;
        initialsName.value = initials(ath_lete.name);
        colorAvtar.value = avtarColor(ath_lete.id);
        switchContent.value = content;

        stats.value = [
            {label: 'Gender', value: athlete.value.gender == 1 ? 'Male' : 'Female' , type: 'text'},
            {label: 'Foot', value: athlete.value.foot, type: 'text'},
            {label: 'Birth Quartile', value: getBirthQuartile(athlete.value.dob), type: 'text'},
            {
                label: 'Age',
                value: athlete.value.age != null ? `${athlete.value.age} yrs` : '-',
                type: 'text'
            },
            {label: 'Height', value: athlete.value.height + ' cm', type: 'text'},
            {label: 'Weight', value: athlete.value.weight + ' kg', type: 'text'},
        ]
    };


    const filteredAthletes = computed(() => {
        return athletes.value.filter(athlete =>
            athlete.name.toLowerCase().includes(searchName.value.toLowerCase())
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

    function updateActiveSubTab(tab_id){
        active_sub_tab.value = tab_id;
    };


    const sub_tabs = ref(["Wellness"]);


</script>

<template>
    <Loading :loading="loading" />
    <div v-if="!loading">
        <div v-if="athletes.length > 0" >
            <div class="row">
                <div class="col-md-3">
                    <div class="card modern-card h-100">
                        <div class="card-header modern-header">
                            <h6>Players</h6>
                            <button class="btn btn-outline btn-sm" @click="addAthletes()" data-bs-toggle="offcanvas" data-bs-target="#add_athletes">
                                <i class="bi bi-plus"></i> Add Players
                            </button>
                        </div>

                        <div class="card-body">
                            <div class="search-box">
                                <i class="bi bi-search"></i>
                                <input type="text" v-model="searchName" placeholder="Search players..." class="form-control modern-input" />
                            </div>

                            <div class="player-list">
                                <div  v-for="(athlete, index) in filteredAthletes"  :key="index" @click="switchContentFun(index, athlete)" :class="['player-item-modern', { active: switchContent === index }]"  >
                                    <img v-if="athlete.profile_pic" :src="s3_url_link + athlete.profile_pic" />
                                    <div v-else class="player-avatar" :style="{ background: avtarColor(athlete.id) }">
                                        {{ initials(athlete.name) }}
                                    </div>
                                    <div class="player-info">
                                        <div class="d-flex align-items-center gap-1">
                                            <span class="name">{{ athlete.name }}</span>
                                            <span v-if="athlete.is_national_team_selected" class="nt-badge" :title="athlete.national_teams">
                                                NT
                                            </span>
                                        </div>

                                        <small class="text-muted">
                                            <TextDesc :text="athlete.club_name" :max="15"/>
                                        </small>
                                    </div>
                                    <!-- <div class="player-info">
                                        <span class="name">{{ athlete.name }}</span><br>
                                          <span v-if="athlete.is_national_team_selected" class="nt-badge" :title="athlete.national_teams">NT</span>
                                        <small class="text-muted">
                                            <TextDesc :text="athlete.club_name" :max="15"/>
                                        </small>
                                    </div> -->

                                    <i class="bi bi-chevron-right"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>


                <div class="col-md-9"  >
                    <div class="row">
                        <div class="col-md-12">
                            <InfoBox 
                            :subtitle="'AIFF ID: ' + athlete.aiff_id" 
                            @tab-click="(args) => updateActiveSubTab(args)" 
                            :tabs="sub_tabs" :activeTab="active_sub_tab" 
                            :avatarSrc="athlete.profile_pic ? athlete.profile_pic : null"
                            :stats="stats"
                            :title="athlete.name" 
                            :location="athlete.state + ', ' + athlete.country_name" 
                            :position_first="athlete.first_pos" 
                            :position_second="athlete.second_pos" 
                            :player_id="Number(athlete.player_id)"
                            :national_teams="athlete.national_teams" 
                            :is_national_team_selected="athlete.is_national_team_selected"
                            :national_team_count ="athlete.national_team_count"
                            >

                            <Field :position_arr="athlete.position_short" width="300"></Field>

                            </InfoBox>
                        </div>
                    </div>
                    <div class="mt-4">
                        <!-- <LoadManagement :team_id="team_id" :athlete="athlete" v-if="active_sub_tab == 0" /> -->
                        <!-- <PerceivedExertion :team_id="team_id" v-if="active_sub_tab == 1" /> -->
                        <Wellness :team_id="team_id" v-if="active_sub_tab == 0" :athlete_id="athlete.player_id" />
                    </div>
                </div>
            </div>
        </div>
        <div v-if="athletes.length == 0" class="alert text-center  mt-5" >
            <div class="text-secondary mt-4">
                <h4>No Athletes on the Team</h4>
                <p class="mt-2" >No athletes have been assigned to this team. Register athletes to monitor them.</p>
            </div>
            <button class="btn-outline-success btn mt-2" type="button" @click="addAthletes()" data-bs-toggle="offcanvas" data-bs-target="#add_athletes" > <i class="bi bi-plus"></i>   Register Athletes</button>
        </div>
    </div>
    <ModalRight id="add_athletes" title="Add Athletes" ref="offcanvasRef" >
        <AddAthletes :team_id="team_id" @close="closePopUp()" />
    </ModalRight>
</template>
<style scoped>
    .modern-card {
        border-radius: 16px;
        border: none;
        box-shadow: 0 8px 24px rgba(0,0,0,0.06);
        background: #ffffff;
    }

    .modern-header {
        background: transparent;
        border-bottom: none;
        padding: 16px 20px;
        font-weight: 600;
    }

    .search-box {
        position: relative;
        margin-bottom: 15px;
    }

    .search-box i {
        position: absolute;
        left: 12px;
        top: 11px;
        color: #999;
    }

    .modern-input {
        padding-left: 35px;
        border-radius: 10px;
        border: 1px solid #eee;
        transition: 0.3s;
    }

    .modern-input:focus {
        border-color: #4caf50;
        box-shadow: 0 0 0 2px rgba(76,175,80,0.1);
    }

    .player-list {
        max-height: 500px;
        overflow-y: auto;
    }

    .player-item-modern {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px;
        border-radius: 12px;
        cursor: pointer;
        transition: all 0.25s ease;
    }

    .player-item-modern:hover {
        background: #f5f7f9;
        transform: translateX(5px);
    }

    .player-item-modern.active {
        background: linear-gradient(135deg, #4caf50, #66bb6a);
        color: white;
    }

    .player-item-modern img {
        width: 40px;
        height: 40px;
        border-radius: 50%;
    }

    .player-info {
        flex: 1;
    }

    .avatar {
        width: 70px;
        height: 70px;
        border-radius: 50%;
    }

    .nt-badge{
        background:#dcfce7;
        color:#15803d;
        border:1px solid #86efac;
        padding:1px 6px;
        border-radius:10px;
        font-size:9px;
        font-weight:700;
    }
 
</style>