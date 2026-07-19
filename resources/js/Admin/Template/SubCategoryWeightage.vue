<script setup>
    import { ref, onMounted, watch } from 'vue';
    import DBService from '@/Service/Utils/DBService.js'

    const {category_id} = defineProps(['category_id']);
    const emit = defineEmits(['close']);

    watch(
        () => category_id,
        () => {
            fetchSubCategories();
        }
    )

    const loading = ref(false);
    const processing = ref(false);
    const sub_cat_list = ref([]);
    const selected_color = '#80bfff';
    const cat_obj = ref({
        criteria_id: 0
    });

    function fetchSubCategories(){
        if(category_id == 0)return;
        loading.value = true;
        DBService.getData('/api/template/fetch-sub-categories/' + category_id) .then( (data) => {
            if(data.success){
                sub_cat_list.value = data.sub_cat_list;
                cat_obj.value.criteria_id = data.eq_distribution ? 0 : 1;
                loading.value = false;
            }
        });
    }

    function weightageCriteriaChange(changed_id){
        if(changed_id == cat_obj.value.criteria_id){
            return;
        }
        cat_obj.value.criteria_id = changed_id;
    }

    function submitWeightage(){
        processing.value = true;
        cat_obj.value.sub_cat_list = sub_cat_list.value;

        if(cat_obj.value.criteria_id == 1){
            let total_weightage = 0;
            let i = 0;

            for(i; i < sub_cat_list.value.length; i++){
                total_weightage = total_weightage + sub_cat_list.value[i].weightage;
            }

            if(total_weightage != 100){
                bootbox.alert('Total Weightahe Should Be 100');
                processing.value = false;
                return;
            }
        }

        DBService.postData('/api/template/submit-assigned-weightage', cat_obj.value) .then( (data) => {
            if(data.success){
                emit('close');
            }
            bootbox.alert(data.message);
            processing.value = false;
        });
    }

</script>

<template>
    <div class="offcanvas-body modal-right__body">
        <div class="row">
            <div class="col-md-12 mb-4">
                <div class="section-title">Weightage Criteria</div>
                <div class="section-subtitle">Select how weightage will be distributed across sub categories.</div>
            </div>

            <div class="col-md-6">
                <div class="criteria-card" :class="{ 'is-selected': cat_obj.criteria_id == 0 }" @click.prevent="weightageCriteriaChange(0)">
                    <div class="criteria-title">Equal Distribution</div>
                    <div class="criteria-desc">Each sub category receives equal weightage.</div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="criteria-card" :class="{ 'is-selected': cat_obj.criteria_id == 1 }" @click.prevent="weightageCriteriaChange(1)">
                    <div class="criteria-title">Assign Weightage</div>
                    <div class="criteria-desc">Set weightage for each sub category (total must be 100).</div>
                </div>
            </div>

            <div class="col-md-12 mt-4 mb-3" v-if="cat_obj.criteria_id == 1">
                <div class="section-title">Assign Weightage</div>
                <div class="section-subtitle">Total weightage across all sub categories must equal 100.</div>
            </div>

            <div class="col-md-12" v-if="cat_obj.criteria_id == 1">
                <div class="weightage-list">
                    <div class="weightage-row" v-for="sub_cat_obj in sub_cat_list" :key="sub_cat_obj.id">
                        <div class="weightage-name">{{ sub_cat_obj.sub_cat_name }}</div>
                        <div class="weightage-input">
                            <FormInput label="Weightage" v-model="sub_cat_obj.weightage" type="number" name="weightage" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="oc-footer">
        <FormButton cls="btn-primary"  @click.prevent="submitWeightage()" :processing="processing" :disabled="loading">Submit</FormButton>
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
    .weightage-list{
        border: 1px solid rgba(0,0,0,0.06);
        border-radius: 12px;
        padding: 12px;
        background: #fff;
    }
    .weightage-row{
        display: grid;
        grid-template-columns: 1fr 260px;
        gap: 16px;
        align-items: center;
        padding: 10px 8px;
        border-bottom: 1px dashed rgba(0,0,0,0.06);
    }
    .weightage-row:last-child{
        border-bottom: 0;
    }
    .weightage-name{
        font-weight: 600;
        color: #1f2a3a;
    }

    @media (max-width: 768px){
        .weightage-row{
            grid-template-columns: 1fr;
        }
    }
</style>
