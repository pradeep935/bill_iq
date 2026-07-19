<script setup>
    import { ref, onMounted, watch } from 'vue';
    import DBService from '@/Service/Utils/DBService.js'
    import { useForm} from 'vee-validate';

    const { handleSubmit, resetForm} = useForm();
    const {def_filters, modal_rec_obj} = defineProps(['def_filters','modal_rec_obj']);
    const emit = defineEmits(['callback']);

    watch(
        () => modal_rec_obj.id,
        () => { assignObjectValue() }
    )

    onMounted(
        () => { fetchDropDowns(); }
    )

    const rec_obj = ref({
        period_id: '',
        id: 0
    });
    const loading = ref(false);
    const dropdown_obj = ref({});

    function assignObjectValue(){
        if(modal_rec_obj.id == 0){
            rec_obj.value = {
                period_id: '',
                id: 0
            };
            resetForm();
        } else{
            rec_obj.value = modal_rec_obj;
        }
    }

    function fetchDropDowns(){
        DBService.getData('/api/recruitment-tracker/fetch-dropdowns').then((data) => {
            if(data.success){
                dropdown_obj.value.period_list = data.period_list;
            }
        })
    }

    const submitRecruitmentDetails = handleSubmit((values, {resetForm})=> {
        loading.value = true;
        DBService.postData('/api/recruitment-tracker/save-recruitment-details',rec_obj.value) .then( (data) => {
            if(data.success){
                bootbox.alert(data.message);
                resetForm();
                emit('callback');
            } else{
                bootbox.alert(data.message);
            }
            loading.value = false;
        });
    });
</script>

<template>
    <div class="offcanvas-body modal-right__body">
        <form id="recruitment_details" @submit.prevent="submitRecruitmentDetails()">
            <div class="row g-3">
                <FormInput req="true" label="Title" name="rec_title" cls="col-md-12 mb-2" v-model="rec_obj.rec_title" placeholder="Name Of Recruitment Group" />

                <FormInput req="true" type="date" placeholder="Select Open Date" label="Open Date" name="open_date" cls="col-md-6 mb-2" v-model="rec_obj.open_date" />

                <FormInput req="true" type="date" placeholder="Select Close Date" label="Close Date" name="close_date" cls="col-md-6 mb-2" v-model="rec_obj.close_date" />

                <FormSelect label="Recruitment Period" name="period_id" :options="dropdown_obj.period_list" cls="col-md-12 mb-2" v-model="rec_obj.period_id" />
                
                <FormText name="note" v-model="rec_obj.note" cls="col-md-12 mb-2" label="Description" placeholder="General Agenda For Recruitment..." />
                
            </div>
        </form>
    </div>
    <div class="oc-footer">
        <button form="recruitment_details" class="btn btn-dark" :disabled="loading">Submit</button>
    </div>
</template>