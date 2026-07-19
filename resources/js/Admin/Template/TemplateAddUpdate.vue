<script setup>
    import { ref, onMounted, watch } from 'vue';
    import DBService from '@/Service/Utils/DBService.js'
    import { useForm} from 'vee-validate';

    const {template_obj} = defineProps(['template_obj']);
    const { handleSubmit, resetForm} = useForm();
    const emit = defineEmits(['callback']);

    watch(
        () => template_obj.id,
        () => {assignTemplateData();}
    )

    const template_data = ref({
        template_name : '',
        description : '',
    });
    const loading = ref(false);

    const submitTemplateDetails = handleSubmit((values, {resetForm})=> {
        loading.value = true;
        DBService.postData('/api/template/save-template',template_data.value) .then( (data) => {
            if(data.success){
                bootbox.alert(data.message);
                resetForm();
                $("#template_add_update").modal('hide');
                emit('callback');
            } else{
                bootbox.alert(data.message);
            }
            loading.value = false;
        });
    });

    function assignTemplateData(){
        if(template_obj.id == 0){
            resetForm();
            template_data.value = template_obj
        } else{
            template_data.value = template_obj
        }
    }

</script>

<template>
    <form @submit.prevent="submitTemplateDetails()">
        <div class="row">
            <FormInput label="Template Name" name="template_name" req="true" v-model="template_data.template_name" cls="col-md-12"/>
            <FormText label="Template Description" name="description" v-model="template_data.description" cls="col-md-12 mt-3"/>
            
            <div class="modal-footer mt-2">
                <FormButton cls="btn-primary" :disabled="loading" :processing="loading">Submit</FormButton>
            </div>
        </div>
    </form>
</template>