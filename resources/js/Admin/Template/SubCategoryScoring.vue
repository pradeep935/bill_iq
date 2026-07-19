<script setup>
    import { ref, onMounted, watch , computed} from 'vue';
    import DBService from '@/Service/Utils/DBService.js'

    const {sub_category_id} = defineProps(['sub_category_id']);
    const emit = defineEmits(['close']);
    watch(
        () => sub_category_id,
        () => {
            fetchScore();
        }
    )

    onMounted(() => {
        fetchScoreDropdowns();
    });

    const loading = ref(false);
    const processing = ref(false);
    const arr_list = ref([]);
    const scoring_obj = ref({
        criteria_id     :   0,
        parameter_id    :   '',
        score_arr       :   []
    });
    const parameter_type = ref('built_in');
    const custom_error_message = ref("");

    function fetchScoreDropdowns(){
        loading.value = true;
        DBService.getData('/api/template/fetch-score-dropdowns') .then( (data) => {
            if(data.success){
                arr_list.value = data.arr_list;
                loading.value = false;
            }
        });
    }

    function fetchScore(){
        if(sub_category_id == 0){return;}
        loading.value = true;
        DBService.getData('/api/template/fetch-score/' + sub_category_id) .then( (data) => {
            if(data.success){
                loading.value = false;
                if(data.score_obj){
                    if(data.score_obj.id){
                        parameter_type.value = data.score_obj.criteria_id == 0 ? 'built_in' : 'custom_made';
                    }
                    scoring_obj.value = {
                        id: data.score_obj.id ?? 0,
                        criteria_id     : data.score_obj.criteria_id ?? 0,
                        parameter_id    : data.score_obj.parameter_id ?? '',
                        score_arr       : Array.isArray(data.score_obj.score_arr) ? data.score_obj.score_arr : [],
                        min_value       : data.score_obj.min_value ? data.score_obj.min_value : [],
                        max_value       : data.score_obj.max_value ? data.score_obj.max_value : [],
                    };
                } else{
                    scoring_obj.value = {
                        criteria_id     :   0,
                        parameter_id    :   '',
                        score_arr       :   []
                    };
                }
            }
        });
    }

    function scoringCriteriaChange(score_id){
        if(score_id == scoring_obj.value.criteria_id){
            return;
        }
        scoring_obj.value.parameter_id = '';
        scoring_obj.value.criteria_id = score_id;
        parameter_type.value = score_id == 0 ? 'built_in' : 'custom_made';
    }

    function scoringParameterChange(){
        if(parameter_type.value == 'built_in' && scoring_obj.value.parameter_id){
            console.log(scoring_obj.value.parameter_id);
        } else if(parameter_type.value == 'custom_made' && scoring_obj.value.parameter_id){
            scoring_obj.value.score_arr = [];
            scoring_obj.value.score_arr[0] = '';
            scoring_obj.value.score_arr[1] = '';
            scoring_obj.value.score_arr[2] = '';
        }
    }

    function addNewCustomParameterEntry(add_index){
        let total_length = scoring_obj.value.score_arr.length;
        if(total_length == 11){
            bootbox.alert('Only 10 Entries Allowed In One Parameter');
            return;
        }
        scoring_obj.value.score_arr.splice((add_index + 1), 0, "");
    }

    function removeNewCustomParameterEntry(remove_index){
        scoring_obj.value.score_arr.splice(remove_index, 1);
    }

    function submitCriteriaParameterDetails(){
        processing.value = true;

        if(!scoring_obj.value.parameter_id){
            custom_error_message.value = "Select A Parameter";
        } else if(scoring_obj.value.criteria_id == 1 && scoring_obj.value.parameter_id == 1){
            if(scoring_obj.value.min_value == '' || scoring_obj.value.min_value == null){
                custom_error_message.value = "Enter Min Value";
            } else if(scoring_obj.value.max_value == '' || scoring_obj.value.max_value == null){
                custom_error_message.value = "Enter Max Value";
            }
        } else if(scoring_obj.value.criteria_id == 1 && scoring_obj.value.parameter_id == 2){
            let i = 1;
            for(i = 1; i < scoring_obj.value.score_arr.length; i++){
                if(scoring_obj.value.score_arr[i] == '' || scoring_obj.value.score_arr[i] == null){
                    custom_error_message.value = "Enter Custom Entry #" + (i + 1) + " Value";
                    break;
                }
            }
        }

        if(custom_error_message.value && custom_error_message.value != ''){
            bootbox.alert(custom_error_message.value);
            custom_error_message.value = '';
            processing.value = false;
            return;
        }
        
        scoring_obj.value.sub_category_id = sub_category_id;
        DBService.postData('/api/template/submit-criteria-parameter-details', scoring_obj.value) .then( (data) => {
            if(data.success){
                emit('close');
            }
            processing.value = false;
            bootbox.alert(data.message);
        });
    }
</script>

<template>
    <div class="offcanvas-body modal-right__body">
        <div class="row">
            <div class="col-md-12 mb-4">
                <div class="section-title">Scoring Criteria</div>
                <div class="section-subtitle">Select how scoring will be calculated for this sub category.</div>
            </div>

            <div class="col-md-6">
                <div class="criteria-card" :class="{ 'is-selected': scoring_obj.criteria_id == 0 }" @click.prevent="scoringCriteriaChange(0)" >
                    <div class="criteria-title">Built-In</div>
                    <div class="criteria-desc">Examples: 1–5, 1–10, A–E, Bad–Excellent.</div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="criteria-card" :class="{ 'is-selected': scoring_obj.criteria_id == 1 }" @click.prevent="scoringCriteriaChange(1)" >
                    <div class="criteria-title">Custom Scoring</div>
                    <div class="criteria-desc">Examples: 20–100, -10–10, X/Y/Z.</div>
                </div>
            </div>


            <div class="col-md-12 mt-4 mb-2">
                <div class="section-title">Scoring Parameter</div>
                <div class="section-subtitle">Choose the parameter type to apply scoring rules.</div>
            </div>

            <div class="col-md-12 form-group mb-4">
                <div class="select-wrap">
                    <select class="form-select" v-model="scoring_obj.parameter_id" @change="scoringParameterChange()">
                        <option value="">Select</option>
                        <option v-for="(arr_obj, index) in (arr_list?.[parameter_type] || [])" :key="index" :value="index" >
                            {{ arr_obj }}
                        </option>
                    </select>
                </div>
            </div>

            <div class="col-md-12" v-if="parameter_type == 'custom_made' && scoring_obj.parameter_id == 1">
                <div class="section-title">Range Selection</div>
                <div class="section-subtitle">Enter the minimum and maximum values.</div>
                <div class="row mt-2">
                    <InputField label="Min Value" v-model='scoring_obj.min_value' cls="col-md-6 "></InputField>
                    <InputField label="Max Value" v-model='scoring_obj.max_value' cls="col-md-6"></InputField>
                </div>
            </div>

            <div class="col-md-12" v-if="parameter_type == 'custom_made' && scoring_obj.parameter_id == 2">
                <div class="section-title">Build Custom Parameters</div>
                <div class="section-subtitle">Enter values from least preferred to most preferred.</div>

                <div class="custom-list">
                    <div class="custom-row">
                        <div class="custom-label">Least Preferred</div>
                        <div class="custom-input">
                            <InputField v-model='scoring_obj.score_arr[1]'></InputField>
                        </div>
                        <div class="custom-actions">
                            <button class="btn btn-sm btn-secondary" @click.prevent="addNewCustomParameterEntry(1)">
                                <i class="bi bi-plus"></i>
                            </button>
                        </div>
                    </div>
                    <div  class="custom-row"  v-for="(score_arr_obj, key) in (scoring_obj.score_arr || [])"  v-show="key != 0 && key != 1 && (key != scoring_obj.score_arr.length - 1)" :key="key" > 
                        <div class="custom-label muted"># {{ key }}</div>
                        <div class="custom-input">
                            <InputField v-model='scoring_obj.score_arr[key]'></InputField>
                        </div>
                        <div class="custom-actions">
                            <button class="btn btn-sm btn-secondary" @click.prevent="removeNewCustomParameterEntry(key)">
                                <i class="bi bi-dash"></i>
                            </button>
                        </div>
                    </div>

                    <div class="custom-row">
                        <div class="custom-label">Most Preferred</div>
                        <div class="custom-input">
                            <InputField v-model='scoring_obj.score_arr[scoring_obj.score_arr.length-1]'></InputField>
                        </div>
                        <div class="custom-actions"></div>
                    </div>
                </div>
            </div>

        </div>
    </div>
    <div class="oc-footer" v-if="scoring_obj.parameter_id">
        <FormButton cls="btn-primary" @click.prevent="submitCriteriaParameterDetails()" :processing="processing" :disabled="loading">Submit</FormButton>
    </div>
</template>

<style scoped>
    .section-title{
        font-weight: 700;
        font-size: 20px;
        color: #1f2a3a;
    }
    .section-subtitle{
        font-size: 12px;
        color: #6b7785;
        margin-top: 4px;
    }
    .criteria-card{
        cursor: pointer;
        border: 2px solid #d8e6ff;
        border-radius: 12px;
        padding: 16px 18px;
        background: #f9fbff;
        transition: all 0.15s ease;
        height: 100%;
    }
    .criteria-card:hover{
        border-color: #1a8cff;
        box-shadow: 0 6px 14px rgba(26, 140, 255, 0.12);
    }
    .criteria-card.is-selected{
        background: #e9f2ff;
        border-color: #1a8cff;
        box-shadow: 0 8px 18px rgba(26, 140, 255, 0.18);
    }
    .criteria-title{
        font-weight: 700;
        color: #1f2a3a;
        margin-bottom: 6px;
    }
    .criteria-desc{
        font-size: 12px;
        color: #6b7785;
    }
    .select-wrap{
        border: 1px solid rgba(0,0,0,0.06);
        border-radius: 10px;
        padding: 8px;
        background: #fff;
    }
    .custom-list{
        margin-top: 12px;
        border: 1px solid rgba(0,0,0,0.06);
        border-radius: 12px;
        padding: 12px;
        background: #fff;
    }
    .custom-row{
        display: grid;
        grid-template-columns: 160px 1fr 120px;
        gap: 12px;
        align-items: center;
        padding: 8px 6px;
        border-bottom: 1px dashed rgba(0,0,0,0.06);
    }
    .custom-row:last-child{
        border-bottom: 0;
    }
    .custom-label{
        font-weight: 600;
        color: #1f2a3a;
    }
    .custom-label.muted{
        color: #8c8c8c;
        font-weight: 500;
    }
    .custom-actions{
        display: flex;
        gap: 8px;
        justify-content: flex-end;
    }

    @media (max-width: 768px){
        .custom-row{
            grid-template-columns: 1fr;
        }
        .custom-actions{
            justify-content: flex-start;
        }
    }
</style>
