<script setup>
    import { ref, onMounted, watch } from 'vue';
    import DBService from '@/Service/Utils/DBService.js'
    import { useForm} from 'vee-validate';
    import TextDesc from '@/Components/NewComponents/TextDesc.vue';
    import Field from '@/Components/NewComponents/Field.vue';
    import HoverMessage from '@/Components/NewComponents/HoverMessage.vue';

    const {report_for_obj, built_in_array} = defineProps(['report_for_obj','built_in_array']);
    const { handleSubmit, resetForm} = useForm();
    const emit = defineEmits(['callback']);
    const base_url = import.meta.env.VITE_APP_URL;
    const s3_url_link = import.meta.env.VITE_S3_URL;

    onMounted(() => {
        fetchPlayerTemplateData();
    });

    const loading = ref(true);
    const comp_player = ref(true);
    const media_display = ref(true);
    const eval_player = ref(true);
    const in_main_shortlist = ref(false);
    const player_obj = ref({});
    const player_list = ref({});
    const template_obj = ref({});
    const media_obj = ref({
        pictures: [''],
        videos: [''],
        links: ['']
    });
    const min_sub_cat_id = ref(0);
    const report_obj = ref({
        sub_cat_score : {

        },
        sub_cat_comment : {

        },
        comp_player_obj : {
            better: [],
            similar: [],
            worse: [],
        }
    });
    const array_title = ref('Better Than');
    const search_player = ref('');
    const arr = ref('better');
    const not_defined = ref('N/A');

    const maturation_dropdown = [{label: -2}, {label: -1}, {label: 0}, {label: 1}, {label: 2}];

    function fetchPlayerTemplateData(){
        loading.value = true;
        DBService.getData('/api/player-report/player-template-details/' + report_for_obj.player_id + '/' + report_for_obj.template_id) .then( (data) => {
            if(data.success){
                player_obj.value = data.player_obj;
                min_sub_cat_id.value = data.min_id;
                player_list.value = data.player_list;
                template_obj.value = data.template_obj;
                in_main_shortlist.value = data.in_main_shortlist;
                loading.value = false;
                if(report_for_obj.id){
                    fetchReportDetails();
                }
            } else{
                bootbox.alert(data.message);
            }
        });
    }

    function fetchReportDetails(){
        loading.value = true;
        DBService.getData('/api/player-report/fetch-report-detail/' + report_for_obj.id) .then( (data) => {
            if(data.success){
                report_obj.value = data.report_obj;
                media_obj.value = data.media_obj;
                loading.value = false;
            } else{
                emit('callback');
                bootbox.alert(data.message);
            }
        });
    }

    const submitReportDetails = handleSubmit((values, {resetForm})=> {
        if(!in_main_shortlist.value && (report_obj.value.grade_id == 5 || report_obj.value.potential_id == 3)){
            let msg = report_obj.value.grade_id == 5 ? 'Player has performance rating Recommended Plus, and will be added to shortlist `Players With Maximum Rating`. Are you sure?' : 'Player has potential HIGH, and will be added to shortlist `Players With Maximum Rating`. Are you sure?';
            bootbox.confirm(msg,(check)=> {
                if(check){
                    loading.value = true;
                    report_obj.value.player_id = report_for_obj.player_id;
                    report_obj.value.template_id = report_for_obj.template_id;
                    console.log(media_obj.value);
                    DBService.postData('/api/player-report/save-player-report',{...report_obj.value, ...media_obj.value}) .then( (data) => {
                        if(data.success){
                            alert(data.message);
                            resetForm();
                            $("#player_add_update").modal('hide');
                            emit('callback');
                        } else{
                            alert(data.message);
                        }
                        loading.value = false;
                    });
                }
            })
        } else{
            loading.value = true;
            report_obj.value.player_id = report_for_obj.player_id;
            report_obj.value.template_id = report_for_obj.template_id;
            DBService.postData('/api/player-report/save-player-report',{...report_obj.value, ...media_obj.value}) .then( (data) => {
                if(data.success){
                    alert(data.message);
                    resetForm();
                    $("#player_add_update").modal('hide');
                    emit('callback');
                } else{
                    alert(data.message);
                }
                loading.value = false;
            });
        }
    });

    function addPlayerInArray(array_type_id){
        switch(array_type_id){
            case 0:
                array_title.value = 'Better Than';
                arr.value = 'better';
                break;
            case 1:
                array_title.value = 'Similar To';
                arr.value = 'similar';
                break;
            case 2:
                array_title.value = 'Worse Than';
                arr.value = 'worse';
                break;
        }
    }

    function addDataInArray(player_id){
        const index = report_obj.value.comp_player_obj[arr.value].indexOf(player_id);

        if (index !== -1) {
            report_obj.value.comp_player_obj[arr.value].splice(index, 1);
        } else {
            report_obj.value.comp_player_obj[arr.value].push(player_id);
        }
    }

    function checkSltCondition(player){
        let temp_player_id = player.id;
        let player_name = player.name;
        if(search_player.value && search_player.value!='' && !player_name.toLowerCase().includes(search_player.value.toLowerCase())){
            console.log(player_name)
            return false;
        }
        if(arr.value == 'better' && (report_obj.value.comp_player_obj['similar'].includes(temp_player_id) || report_obj.value.comp_player_obj['worse'].includes(temp_player_id))){
            return false;
        }
        if(arr.value == 'worse' && (report_obj.value.comp_player_obj['similar'].includes(temp_player_id) || report_obj.value.comp_player_obj['better'].includes(temp_player_id))){
            return false;
        }
        if(arr.value == 'similar' && (report_obj.value.comp_player_obj['better'].includes(temp_player_id) || report_obj.value.comp_player_obj['worse'].includes(temp_player_id))){
            return false;
        }
        return true
    }
</script>

<template>
    <div class="report-shell">
        <div class="row mx-5 py-4 player-border report-hero" v-if="!report_for_obj.is_viewing">
            <div class="col-md-4">
                <div class="hero-label">Player Name</div>
                <div class="hero-value hero-name text-capitalize">{{ player_obj.name || 'N/A' }}</div>
            </div>
            <div class="col-md-2 col-6 mt-3 mt-md-0">
                <div class="hero-label">Player Age</div>
                <div class="hero-value">{{ player_obj.age || 'N/A' }}</div>
            </div>
            <div class="col-md-2 col-6 mt-3 mt-md-0">
                <div class="hero-label">Dominant Foot</div>
                <div class="hero-value">{{ player_obj.foot || 'N/A' }}</div>
            </div>
            <div class="col-md-2 mt-3 mt-md-0 text-md-end">
                <div class="hero-label">Player Added On</div>
                <div class="hero-value">{{ player_obj.display_created_at || 'N/A' }}</div>
            </div>
        </div>
        <div class="row mx-5 py-4 player-border report-hero report-hero--view" v-else>
            <div class="col-sm-12 col-lg-5">
                <div class="hero-profile">
                    <div class="hero-avatar-wrap">
                        <img v-if="player_obj.profile_pic" :src="s3_url_link + player_obj.profile_pic" :alt="player_obj.name || 'Player'" class="hero-avatar">
                        <div v-else class="hero-avatar hero-avatar--placeholder">
                            {{ player_obj.name ? player_obj.name.charAt(0) : 'P' }}
                        </div>
                    </div>
                    <div class="hero-profile-copy">
                        <div class="hero-label">Player Name (By: {{report_obj.user_name}})</div>
                        <div class="hero-value hero-name text-capitalize"><a :href="base_url + '/player-information/' + player_obj.id">{{ player_obj.name || 'N/A' }}</a></div>
                        <div class="hero-tags">
                            <span class="hero-tag">{{ player_obj.position_short || 'Position N/A' }}</span>
                            <span class="hero-tag hero-tag--muted">{{ player_obj.foot || 'Foot N/A' }}</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-sm-3 col-lg-1 mt-3 mt-lg-0 mt-sm-4">
                <div class="hero-label">Player Age</div>
                <div class="hero-value">{{ player_obj.age || 'N/A' }}</div>
            </div>
            <div class="col-sm-3 col-lg-1 mt-3 mt-lg-0 mt-sm-4">
                <div class="hero-label">Total Score</div>
                <div class="hero-value">{{ report_obj.total_score || 'N/A' }}</div>
            </div>
            <div class="col-sm-3 col-lg-2 mt-3 mt-lg-0 text-lg-end mt-sm-4">
                <div class="hero-label">Player Added On</div>
                <div class="hero-value">{{ player_obj.display_created_at || 'N/A' }}</div>
            </div>
            <div class="col-sm-3 col-lg-2 mt-3 mt-lg-0 text-lg-end mt-sm-4">
                <div class="hero-label">Report Added On</div>
                <div class="hero-value">{{ report_obj.display_created_at || 'N/A' }}</div>
            </div>
        </div>

        <form class="pb-5" @submit.prevent="submitReportDetails()">
            <div class="row mx-5 mt-4 py-4 player-grade report-card">
                <div class="col-12 mb-3">
                    <div class="section-heading">Summary</div>
                    <div class="section-subtitle">Performance, potential, and the overall note for this player report.</div>
                </div>

                <div class="col-md-6 row">
                    <div class="col-md-6">
                        <div class="row">
                            <FormSelect :options="built_in_array[6]" :req="report_for_obj.is_viewing ? false : true" label="Performance" name="grade" v-model="report_obj.grade_id" cls="col-md-12" :disabled="report_for_obj.is_viewing"></FormSelect>
                            <!-- <FormSelect :options="built_in_array[3]" :req="report_for_obj.is_viewing ? false : true" label="Grade" name="grade" v-model="report_obj.grade_id" cls="col-md-12" :disabled="report_for_obj.is_viewing"></FormSelect> -->
                        </div>
                        <div class="row">
                            <FormSelect :options="built_in_array[5]" :req="report_for_obj.is_viewing ? false : true" label="Potential" name="potential" v-model="report_obj.potential_id" cls="col-md-12" :disabled="report_for_obj.is_viewing"></FormSelect>
                        </div> 
    
                        <div class="row">
                            <FormSelect :options="built_in_array[5]" :req="report_for_obj.is_viewing ? false : true" label="Opponent level" name="opponent_level" v-model="report_obj.opponent_level_id" cls="col-md-12" :disabled="report_for_obj.is_viewing"></FormSelect>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="row">
                             <FormSelect :options="[
                                    { value: 1, label: 'District' },
                                    { value: 2, label: 'State' },
                                    { value: 3, label: 'National' }
                                ]" label="Competition Level" name="competition_level" v-model="report_obj.competition_level_id" cls="col-md-12" :disabled="report_for_obj.is_viewing" />
    
                            <FormInput right_box_text="cm" label="Height" type="number" name="height" v-model="report_obj.height" cls="col-md-12" :disabled="report_for_obj.is_viewing" />
    
                            <FormInput right_box_text="kg" label="Weight" type="number" name="weight" v-model="report_obj.weight" cls="col-md-12" :disabled="report_for_obj.is_viewing" />
                        </div>
                    </div>
                    <FormSelect label="Maturation" name="maturation" cls="col-md-6" :options="maturation_dropdown" opt_name="label" opt_id="label" v-model="report_obj.maturation" :disabled="report_for_obj.is_viewing"></FormSelect>
                    <FormInput label="Jersey No" name="jersey_no" cls="col-md-6" v-model="report_obj.jersey_no" :disabled="report_for_obj.is_viewing"></FormInput>
                    <FormText name="note" v-model="report_obj.note" cls="col-md-12" label="Note" rows="7" v-if="!report_for_obj.is_viewing"></FormText>
    
                    <div v-else class="col-md-12">
                        <label class="view-label">Comment</label>
                        <div class="view-note-box">
                            <TextDesc :text='report_obj.note ? report_obj.note : "No Comment"' :max="60"></TextDesc>
                            <!-- {{ report_obj.note ? report_obj.note : "No Comment" }} -->
                        </div>
                    </div>
                </div>
                <div class="col-md-6" style="display: flex; justify-content: center;">
                    <Field width="400" :position_arr="[player_obj.position_first_id]"></Field>
                </div>
            </div>

            <div class="row mx-5 mt-4 py-4 player-grade report-card">
                <div class="col-md-10">
                    <div class="section-heading section-toggle" @click="media_display = !media_display">
                        Media
                    </div>
                    <div class="section-subtitle">Pictures/Video for this player report.</div>
                </div>
                <div class="col-md-2 text-end pt-2">
                    <i :class="media_display ? 'bi bi-arrow-up-circle' : 'bi bi-arrow-down-circle'" class="section-arrow" @click="media_display = !media_display"></i>
                </div>
                <div class="col-12"><hr class="section-divider"></div>
                <div class="col-md-12">
                    <transition name="fade" class="p-0 m-0">
                        <div class="row" v-show="media_display" v-if="!report_for_obj.is_viewing">
                            <div class="col-md-12">
                                <label style="font-weight: bold;">Pictures <span style="font-weight: normal; color: #8c8c8c;">(Max 5 pictures can be uploaded)</span></label>
                            </div>
                            <div class="col-md-4 mt-2 text-end" v-for="(pic_model,index) in media_obj.pictures">
                                <div class="text-start" :class="media_obj.pictures[index] && media_obj.pictures[index] != '' ? 'd-flex' : ''">
                                    <span v-if="media_obj.pictures[index] && media_obj.pictures[index] != ''" style="color: #737373;" class="ms-1 me-1">Image-{{ index + 1 }} has been added</span>
                                    <PhotoUpload v-model="media_obj.pictures[index]"></PhotoUpload>
                                </div>
                                <button v-if="media_obj.pictures.length > 1" type="button" class="btn btn-sm btn-danger mt-1" @click.prevent="media_obj.pictures.splice(index, 1)"><i class="bi bi-trash"></i></button>
                                <button type="button" v-if="index == (media_obj.pictures.length - 1) && media_obj.pictures[index] && media_obj.pictures[index] != '' && index < 5" :class="index == 0 ? '' : 'ms-1'" class="mt-1 btn btn-sm btn-primary" @click.prevent="media_obj.pictures[index + 1] = ''"><i class="bi bi-file-earmark-plus-fill"></i></button>
                            </div>
                            <div class="col-md-12 mt-4">
                                <label style="font-weight: bold;">Videos <span style="font-weight: normal; color: #8c8c8c;">(Max video size - 100MB)</span></label>
                            </div>
                            <div class="col-md-4 mt-2 text-end" v-for="(vid_model,index) in media_obj.videos">
                                <div class="text-start" :class="media_obj.videos[index] && media_obj.videos[index] != '' ? 'd-flex' : ''">
                                    <span v-if="media_obj.videos[index] && media_obj.videos[index] != ''" style="color: #737373;" class="ms-1 me-1">Video-{{ index + 1 }} has been added</span>
                                    <VideoUpload v-model="media_obj.videos[index]"></VideoUpload>
                                </div>
                                <button v-if="media_obj.videos.length > 1" type="button" class="btn btn-sm btn-danger mt-1" @click.prevent="media_obj.videos.splice(index, 1)"><i class="bi bi-trash"></i></button>
                                <button type="button" v-if="index == (media_obj.videos.length - 1) && media_obj.videos[index] && media_obj.videos[index] != '' && index < 1" :class="index == 0 ? '' : 'ms-1'" class="mt-1 btn btn-sm btn-primary" @click.prevent="media_obj.videos[index + 1] = ''"><i class="bi bi-file-earmark-plus-fill"></i></button>
                            </div>
                            <div class="col-md-12 mt-4">
                                <label style="font-weight: bold;">Links <span style="font-weight: normal; color: #8c8c8c;">(Youtube / Other sites link...)</span></label>
                            </div>
                            <div class="col-md-4 mt-2 text-end" v-for="(vid_model,index) in media_obj.links">
                                <InputField v-model="media_obj.links[index]"></InputField>
                                <button v-if="media_obj.links.length > 1" type="button" class="btn btn-sm btn-danger mt-1" @click.prevent="media_obj.links.splice(index, 1)"><i class="bi bi-trash"></i></button>
                                <button type="button" v-if="index == (media_obj.links.length - 1) && media_obj.links[index] && media_obj.links[index] != '' && index < 2" :class="index == 0 ? '' : 'ms-1'" class="mt-1 btn btn-sm btn-primary" @click.prevent="media_obj.links[index + 1] = ''"><i class="bi bi-plus-circle"></i></button>
                            </div>
                        </div>
                        <div v-show="media_display" v-else>
                            <MediaDisplay :photos="media_obj.pictures" :videos="media_obj.videos"></MediaDisplay>
                            <div class="d-flex justify-content-between mt-2">
                                <h6>Links</h6>
                                <span class="badge bg-light text-dark border">{{ media_obj.links.length == 1 ? (media_obj.links[0] == '' ? 0 : 1) : media_obj.links.length }}</span>
                            </div>

                            <div v-if="media_obj.links.length > 1 || (media_obj.links.length == 1 && media_obj.links[0] != '')" class="row g-3">
                                <div v-for="(video, index) in media_obj.links" :key="`video-${index}`" class="col-4">
                                    <HoverMessage :message="video" position="bottom">
                                        <a :href="video" target="_blank">Link ({{ video.slice(0, 10) + '' + (video.length > 10 ? '...' : '') }})</a>
                                    </HoverMessage>
                                </div>
                            </div>

                            <div v-else class="text-muted small">
                                No link added.
                            </div>
                        </div>
                    </transition>
                </div>
            </div>

            <div class="row mx-5 mt-4 py-4 report-card">
                <div class="col-md-10">
                    <div class="section-heading section-toggle" @click="comp_player = !comp_player">
                        Other Player Comparision
                    </div>
                    <div class="section-subtitle">Group comparable players by stronger, similar, or weaker profile.</div>
                </div>
                <div class="col-md-2 text-end pt-2">
                    <i :class="comp_player ? 'bi bi-arrow-up-circle' : 'bi bi-arrow-down-circle'" class="section-arrow" @click="comp_player = !comp_player"></i>
                </div>
                <div class="col-12"><hr class="section-divider"></div>

                <div class="col-md-12">
                    <transition name="fade" class="p-0 m-0">
                        <div class="row" v-show="comp_player">
                            <div class="col-md-8 mt-3">
                                <div class="comparison-list">
                                    <div class="comparison-row">
                                        <div class="comparison-title" :class="arr == 'better' ? 'active' : ''" @click.prevent="addPlayerInArray(0)">
                                            Player Better Than
                                            <span class="comparison-count">{{ report_obj.comp_player_obj.better.length }}</span>
                                        </div>
                                        <div class="comparison-tags">
                                            <template v-for="player in player_list" :key="'better_' + player.id">
                                                <span class="px-2 me-2 mb-2 player-name-box" v-show="report_obj.comp_player_obj.better.includes(player.id)">
                                                    {{ player.name }}
                                                </span>
                                            </template>
                                            <span class="empty-chip" v-if="report_obj.comp_player_obj.better.length == 0">No players added</span>
                                        </div>
                                    </div>
                                    <div class="comparison-row">
                                        <div class="comparison-title" :class="arr == 'similar' ? 'active' : ''" @click.prevent="addPlayerInArray(1)">
                                            Player Similar To
                                            <span class="comparison-count">{{ report_obj.comp_player_obj.similar.length }}</span>
                                        </div>
                                        <div class="comparison-tags">
                                            <template v-for="player in player_list" :key="'similar_' + player.id">
                                                <span class="px-2 me-2 mb-2 player-name-box player-name-box--neutral" v-show="report_obj.comp_player_obj.similar.includes(player.id)">
                                                    {{ player.name }}
                                                </span>
                                            </template>
                                            <span class="empty-chip" v-if="report_obj.comp_player_obj.similar.length == 0">No players added</span>
                                        </div>
                                    </div>
                                    <div class="comparison-row comparison-row--last">
                                        <div class="comparison-title" :class="arr == 'worse' ? 'active' : ''" @click.prevent="addPlayerInArray(2)">
                                            Player Worse Than
                                            <span class="comparison-count">{{ report_obj.comp_player_obj.worse.length }}</span>
                                        </div>
                                        <div class="comparison-tags">
                                            <template v-for="player in player_list" :key="'worse_' + player.id">
                                                <span class="px-2 me-2 mb-2 player-name-box player-name-box--danger" v-show="report_obj.comp_player_obj.worse.includes(player.id)">
                                                    {{ player.name }}
                                                </span>
                                            </template>
                                            <span class="empty-chip" v-if="report_obj.comp_player_obj.worse.length == 0">No players added</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                
                            <div class="col-md-4 mt-3"  v-if="!report_for_obj.is_viewing">
                                <div class="player-picker-card">
                                    <div class="row align-items-center g-2">
                                        <div class="col-md-8">
                                            <h4 class="picker-title mb-0">Select Player {{ array_title }}</h4>
                                        </div>
                                        <div class="col-md-4 form-group">
                                            <input type="text" class="form-control picker-search" placeholder="Search player..." v-model="search_player">
                                        </div>
                                    </div>
                                    <TableCont table_class="table-bordered table-hover align-middle mt-3" style="height: 240px;">
                                        <thead>
                                            <tr>
                                                <th class="text-center" style="width: 20%;">Select</th>
                                                <th class="text-capitalize">Player Name</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="(player_data,index) in player_list" :key="'picker_' + player_data.id" v-show="checkSltCondition(player_data)">
                                                <td class="text-center" v-if="player_data.id != report_for_obj.player_id">
                                                    <input type="checkbox" @change="addDataInArray(player_data.id)" :checked="report_obj.comp_player_obj[arr].includes(player_data.id)">
                                                </td>
                                                <td v-if="player_data.id != report_for_obj.player_id" class="text-capitalize">{{ player_data.name }}</td>
                                            </tr>
                                        </tbody>
                                    </TableCont>
                                </div>
                            </div>
                        </div>
                    </transition>
                </div>
            </div>

            <div class="row mx-5 mt-4 py-4 report-card">
                <div class="col-md-10">
                    <div class="section-heading section-toggle" @click="eval_player = !eval_player">
                        Evaluate Player
                        <span class="template-pill text-capitalize">{{ template_obj.template_name }}</span>
                    </div>
                    <div class="section-subtitle">Review each criteria area and add scores with supporting notes.</div>
                </div>
                <div class="col-md-2 text-end pt-2">
                    <i :class="eval_player ? 'bi bi-arrow-up-circle' : 'bi bi-arrow-down-circle'" class="section-arrow" @click="eval_player = !eval_player"></i>
                </div>
                <div class="col-12"><hr class="section-divider"></div>
                <transition name="fade" class="p-0 m-0">
                    <div class="row ps-3 pe-3" v-show="eval_player">
                        <div class="col-md-12" v-for="(cat_data, criteria_no) in template_obj.cats" :key="'cat_' + cat_data.id">
                            <div class="criteria-card">
                                <div class="criteria-header">
                                    <div class="criteria-title-wrap">
                                        <span class="criteria-index">Criteria {{ criteria_no + 1 }}</span>
                                        <span class="criteria-title">{{ cat_data.cat_name }}</span>
                                    </div>
                                    <span class="criteria-count">{{ cat_data.sub_cats.length }} areas</span>
                                </div>
                                <div class="sub-criteria-list">
                                    <div class="sub-criteria-row" v-for="(sub_cat_data,sub_index) in cat_data.sub_cats" :key="'sub_cat_' + sub_cat_data.id">
                                        <div class="sub-criteria-name">
                                            {{ sub_cat_data.sub_cat_name }}
                                        </div>
                                        <div class="sub-criteria-score">
                                            <FormSelect :disabled="report_for_obj.is_viewing" cls="col-md-12" :name="'score_' + sub_cat_data.id" :options="built_in_array[sub_cat_data.parameter_id]" label="Score" v-model="report_obj.sub_cat_score[sub_cat_data.id]" v-if="sub_cat_data.criteria_id == 0" />

                                            <FormSelect :disabled="report_for_obj.is_viewing" cls="col-md-12" :name="'score_' + sub_cat_data.id" :options="sub_cat_data.custom_arr" label="Score" v-model="report_obj.sub_cat_score[sub_cat_data.id]" v-if="sub_cat_data.criteria_id == 1 && sub_cat_data.parameter_id == 2" />

                                            <FormInput :disabled="report_for_obj.is_viewing" cls="col-md-12" type="number" :name="'score_' + sub_cat_data.id" label="Score" v-model="report_obj.sub_cat_score[sub_cat_data.id]" v-if="sub_cat_data.criteria_id == 1 && sub_cat_data.parameter_id == 1" />

                                            <FormInput disabled="true" cls="col-md-12" :name="'score_' + sub_cat_data.id" label="Score" v-model="not_defined" v-if="(!sub_cat_data.criteria_id && sub_cat_data.criteria_id != 0) || !sub_cat_data.parameter_id" />
                                        </div>
                                        <div class="sub-criteria-comment" v-if="!report_for_obj.is_viewing">
                                            <FormText label="Comment" :name="'comment_' + sub_cat_data.id" cls="col-md-12" v-model="report_obj.sub_cat_comment[sub_cat_data.id]"></FormText>
                                        </div>
                                        <div class="sub-criteria-comment" v-else>
                                            <label class="view-label">Comment</label>
                                            <div class="comment-display-box">
                                                <TextDesc :text='report_obj.sub_cat_comment[sub_cat_data.id] ? report_obj.sub_cat_comment[sub_cat_data.id] : "No Comment"' :max="60"></TextDesc>
                                                <!-- {{ report_obj.sub_cat_comment[sub_cat_data.id] ? report_obj.sub_cat_comment[sub_cat_data.id] : "No Comment" }} -->
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </transition>
            </div>

            <div class="row mx-5 mt-4 py-4 report-action-bar" v-if="!report_for_obj.is_viewing">
                <div class="col-md-12 text-end">
                    <button class="btn btn-primary px-4" type="submit" :disabled="loading">Submit</button>
                </div>
            </div>
        </form>
    </div>
</template>

<style scoped>
    .report-shell{
        padding-bottom: 16px;
    }

    .player-border{
        border: 1px solid #c8def7;
        border-radius: 20px;
    }

    .report-hero{
        background: linear-gradient(135deg, #e6f4ff 0%, #f5fbff 100%);
        box-shadow: 0 14px 30px rgba(23, 65, 120, 0.08);
    }

    .hero-label{
        color: #6482a6;
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        margin-bottom: 6px;
    }

    .hero-value{
        color: #112847;
        font-size: 18px;
        font-weight: 700;
        line-height: 1.4;
    }

    .hero-name{
        font-size: 24px;
    }

    .hero-profile{
        display: flex;
        align-items: center;
        gap: 18px;
    }

    .hero-avatar-wrap{
        flex-shrink: 0;
    }

    .hero-avatar{
        width: 88px;
        height: 88px;
        border-radius: 24px;
        object-fit: cover;
        border: 3px solid rgba(255, 255, 255, 0.95);
        box-shadow: 0 10px 24px rgba(17, 40, 71, 0.14);
        background: #dbeafe;
    }

    .hero-avatar--placeholder{
        display: flex;
        align-items: center;
        justify-content: center;
        color: #1d4f91;
        font-size: 28px;
        font-weight: 800;
        text-transform: uppercase;
        background: linear-gradient(135deg, #d8ebff 0%, #edf6ff 100%);
    }

    .hero-profile-copy{
        min-width: 0;
    }

    .hero-tags{
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 12px;
    }

    .hero-tag{
        display: inline-flex;
        align-items: center;
        min-height: 30px;
        padding: 0 12px;
        border-radius: 999px;
        background: rgba(16, 35, 61, 0.08);
        color: #17385f;
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 0.02em;
    }

    .hero-tag--muted{
        background: rgba(255, 255, 255, 0.82);
        color: #4d6482;
    }

    .player-grade{
        border: 1px solid #dce3ee;
        background: linear-gradient(180deg, #ffffff 0%, #f7f9fc 100%);
    }

    .player-name-box{
        display: inline-flex;
        align-items: center;
        min-height: 32px;
        background: #dff4ea;
        color: #156446;
        border: 1px solid #b5e4cf;
        border-radius: 999px;
        font-size: 13px;
        font-weight: 600;
    }

    .player-name-box--neutral{
        background: #eef4ff;
        color: #29519b;
        border-color: #cddcff;
    }

    .player-name-box--danger{
        background: #fff0ef;
        color: #bd3e34;
        border-color: #f2c8c4;
    }

    .report-card{
        border: 1px solid #dce3ee;
        border-radius: 18px;
        background: #ffffff;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
    }

    .section-heading{
        color: #10233d;
        font-size: 20px;
        font-weight: 700;
        line-height: 1.3;
    }

    .section-toggle{
        display: inline-flex;
        align-items: center;
        gap: 10px;
        cursor: pointer;
    }

    .section-subtitle{
        color: #6c7d94;
        font-size: 13px;
        margin-top: 6px;
    }

    .section-arrow{
        cursor: pointer;
        font-size: 18px;
        color: #335ea8;
        width: 36px;
        height: 36px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        background: #eef4ff;
    }

    .section-divider{
        margin-top: 10px;
        border-color: #e6edf5;
    }

    .view-label{
        display: inline-block;
        color: #54657d;
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        margin-bottom: 8px;
    }

    .view-note-box,
    .comment-display-box{
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 14px 16px;
        color: #334155;
        line-height: 1.65;
        min-height: 54px;
    }

    .comparison-list{
        display: flex;
        flex-direction: column;
        gap: 18px;
    }

    .comparison-row{
        padding-bottom: 18px;
        border-bottom: 1px solid #ecf1f6;
    }

    .comparison-row--last{
        border-bottom: 0;
        padding-bottom: 0;
    }

    .comparison-title{
        display: inline-flex;
        align-items: center;
        gap: 10px;
        color: #2157a5;
        font-size: 15px;
        font-weight: 700;
        cursor: pointer;
        margin-bottom: 12px;
    }

    .comparison-title {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 10px 14px;
        border-radius: 8px;
        background: #f5f7fb;
        color: #555;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.25s ease;
    }

    .comparison-title:hover {
        background: #e0e7ff;
        color: #1e3a8a;
        transform: translateY(-1px);
        box-shadow: 0 3px 8px rgba(0,0,0,0.08);
    }

    /* ACTIVE STATE */
    .comparison-title.active {
        background: #4f46e5; /* Indigo */
        color: #fff;
        font-weight: 600;
        box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
    }

    /* Count badge */
    .comparison-count {
        background: rgba(0, 0, 0, 0.08);
        padding: 3px 8px;
        border-radius: 20px;
        font-size: 12px;
    }

    /* Active me badge */
    .comparison-title.active .comparison-count {
        background: rgba(255, 255, 255, 0.2);
        color: #fff;
    }

    .comparison-count{
        background: #edf3ff;
        color: #335ea8;
        border-radius: 999px;
        padding: 3px 10px;
        font-size: 12px;
        font-weight: 700;
    }

    .comparison-tags{
        display: flex;
        flex-wrap: wrap;
        align-items: center;
    }

    .empty-chip{
        display: inline-flex;
        align-items: center;
        min-height: 32px;
        background: #f8fafc;
        color: #7b8798;
        border: 1px dashed #d4dde8;
        border-radius: 999px;
        padding: 0 12px;
        font-size: 13px;
    }

    .player-picker-card{
        background: #f9fbff;
        border: 1px solid #d9e5f4;
        border-radius: 16px;
        padding: 16px;
    }

    .picker-title{
        color: #10233d;
        font-size: 18px;
        font-weight: 700;
    }

    .picker-search{
        border-radius: 10px;
        border-color: #d6e0ec;
    }

    .template-pill{
        display: inline-flex;
        align-items: center;
        min-height: 28px;
        padding: 0 12px;
        border-radius: 999px;
        background: #eef4ff;
        color: #2b5299;
        font-size: 12px;
        font-weight: 700;
    }

    .criteria-card{
        border: 1px solid #dce6f2;
        border-radius: 18px;
        background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
        padding: 18px;
        margin-bottom: 18px;
    }

    .criteria-header{
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 14px;
        padding-bottom: 12px;
        border-bottom: 1px solid #ecf1f6;
    }

    .criteria-title-wrap{
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 10px;
    }

    .criteria-index{
        background: #10233d;
        color: #ffffff;
        border-radius: 999px;
        padding: 5px 12px;
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 0.03em;
    }

    .criteria-title{
        color: #10233d;
        font-size: 18px;
        font-weight: 700;
    }

    .criteria-count{
        color: #6c7d94;
        font-size: 13px;
        font-weight: 600;
    }

    .sub-criteria-list{
        display: flex;
        flex-direction: column;
        gap: 14px;
    }

    .sub-criteria-row{
        display: grid;
        grid-template-columns: minmax(160px, 1fr) minmax(220px, 1.1fr) minmax(280px, 1.4fr);
        gap: 16px;
        align-items: start;
        background: #f8fafc;
        border: 1px solid #e8eef5;
        border-radius: 16px;
        padding: 16px;
    }

    .sub-criteria-name{
        color: #10233d;
        font-size: 15px;
        font-weight: 700;
        padding-top: 10px;
    }

    .report-action-bar{
        border: 0;
        background: transparent;
        box-shadow: none;
    }

    .fade-enter-active, .fade-leave-active {
        transition: opacity 0.4s ease-in-out 0.1s;
    }
    .fade-enter-from, .fade-leave-to {
        opacity: 0;
    }
    .fade-enter-to, .fade-leave-from {
        opacity: 1;
    }

    @media (max-width: 991px) {
        .hero-profile{
            align-items: flex-start;
        }

        .hero-avatar{
            width: 72px;
            height: 72px;
            border-radius: 20px;
        }

        .sub-criteria-row{
            grid-template-columns: 1fr;
        }

        .sub-criteria-name{
            padding-top: 0;
        }
    }
</style>
