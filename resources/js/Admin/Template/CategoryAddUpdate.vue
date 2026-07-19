
<script setup>
    import { ref, onMounted, watch } from 'vue';
    import DBService from '@/Service/Utils/DBService.js'
    import { useForm} from 'vee-validate';

    const {category_obj, template_id} = defineProps(['category_obj','template_id']);
    const { handleSubmit, resetForm} = useForm();
    const emit = defineEmits(['callback']);

    watch(
        () => category_obj.id,
        () => {assignCategoryData();}
    )

    const category_data = ref({
        cat_name : '',
        description : '',
    });
    const loading = ref(false);

    const submitCategoryDetails = handleSubmit((values, {resetForm})=> {
        loading.value = true;
        category_data.value.template_id = template_id;
        DBService.postData('/api/template/save-category',category_data.value) .then( (data) => {
            if(data.success){
                bootbox.alert(data.message);
                resetForm();
                $("#category_add_update").modal('hide');
                emit('callback');
            } else{
                bootbox.alert(data.message);
            }
            loading.value = false;
        });
    });

    function assignCategoryData(){
        if(category_obj.id == 0){
            resetForm();
            category_data.value = category_obj
        } else{
            category_data.value = category_obj
        }
    }
</script>

<template>
    <form @submit.prevent="submitCategoryDetails()">
        <div class="row">
            <FormInput label="Category Name" name="cat_name" req="true" v-model="category_data.cat_name" cls="col-md-12"/>
            <FormText label="Category Description" name="description" v-model="category_data.description" cls="col-md-12 mt-3"/>

        </div>
            <div class="modal-footer mt-3">
                <button class="btn btn-success" type="submit" :disabled="loading">Submit</button>
            </div>
    </form>
</template>