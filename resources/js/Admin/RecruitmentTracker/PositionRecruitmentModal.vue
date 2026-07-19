<script setup>
    import { ref, onMounted, watch } from 'vue';
    import DBService from '@/Service/Utils/DBService.js'
    import LengthZero from '@/Components/NewComponents/LengthZero.vue';
    import FormMultiSelect from '@/Components/NewComponents/FormMultiSelect.vue';
    import FormInputBox from '@/Components/NewComponents/FormInputBox.vue';
    import { useForm} from 'vee-validate';

    const {dropdown_obj, rec_id, edit_obj} = defineProps(['dropdown_obj','rec_id','edit_obj']);
    const { handleSubmit, resetForm} = useForm();
    const emit = defineEmits(['callback']);

    watch(
        () => edit_obj.id,
        () => {
            assignValueToObject();
        }
    )

    const loading = ref(false);
    const rec_pos_obj = ref({
        temp_pos_id: '',
        position_arr: []
    });

    const submitRecruitmentDetails = handleSubmit((values, {resetForm})=> {
        loading.value = true;
        rec_pos_obj.value.rec_id = rec_id;
        DBService.postData('/api/recruitment-tracker/recruitment-position/save-recruitment-position-details',rec_pos_obj.value) .then( (data) => {
            if(data.success){
                resetForm();
                emit('callback');
            }
            bootbox.alert(data.message);
            loading.value = false;
        });
    });

    function assignValueToObject(){
        if(edit_obj.id == 0){
            resetForm();
        }
        rec_pos_obj.value = JSON.parse(JSON.stringify(edit_obj));
    }
</script>

<template>
    <div class="offcanvas-body modal-right__body">
        <form id="submit_recruiment_form" @submit.prevent="submitRecruitmentDetails()">
            <div class="row g-3">
                <FormInput req="true" placeholder="General Agenda..." cls="col-md-12" label="Name" name="name" v-model="rec_pos_obj.name" />

                <FormMultiSelect req="true" cls="col-md-12" cls2="col-md-12" label="Position(s)" name="position" :options="dropdown_obj.position_list" opt_id="id" opt_name="position_short" v-model="rec_pos_obj.position_arr" field_name="Position(s)" />

                <FormInputBox req="true" cls="col-md-6" type="number" label="Expected Transfer Value" name="transfer_value" v-model="rec_pos_obj.transfer_value" box_entry="&#8377;" display_position="left" />

                <FormInputBox req="true" cls="col-md-6" type="number" label="Expected Annual Salary" name="annual_salary" v-model="rec_pos_obj.annual_salary" box_entry="&#8377;" display_position="left" />

                <FormText label="Description" cls="col-md-6" placeholder="Information About The Request..." name="note" v-model="rec_pos_obj.note" />

                <FormText label="Requirements" cls="col-md-6" placeholder="Selection Criteria And Other Requirements..." name="requirement" v-model="rec_pos_obj.requirement" />

            </div>
        </form>
    </div>
    <div class="oc-footer">
        <button class="btn btn-dark" form="submit_recruiment_form" :disabled="loading">Submit</button>
    </div>
</template>