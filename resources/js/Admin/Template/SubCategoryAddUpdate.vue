<script setup>
    import { ref, onMounted, watch } from 'vue';
    import DBService from '@/Service/Utils/DBService.js'
    import { useForm} from 'vee-validate';
    const { handleSubmit, resetForm } = useForm();
    const { sub_category_obj, category_id } = defineProps(['sub_category_obj','category_id']);
    const emit = defineEmits(['callback']);
    const loading = ref(false);

    const sub_category_data = ref({
        sub_cat_name : '',
        description : '',
        cat_id: 0
    });

    watch(
        () => sub_category_obj,
        () => {
            assignCategoryData();
        },
        { deep: true, immediate: true }
    );

    function assignCategoryData(){
        if(sub_category_obj.id == 0){
            resetForm();
            sub_category_data.value = {
                sub_cat_name: '',
                description: '',
                cat_id: sub_category_obj.cat_id || category_id || 0
            };
        } else{
            sub_category_data.value = { ...sub_category_obj };
        }
    }

    const submitCategoryDetails = handleSubmit(() => {
        loading.value = true;

        DBService.postData('/api/template/save-sub-category', sub_category_data.value)
        .then((data) => {
            if(data.success){
                bootbox.alert(data.message);
                resetForm();
                $("#sub_category_add_update").modal('hide');
                emit('callback');
            } else{
                bootbox.alert(data.message);
            }
            loading.value = false;
        });
    });
</script>
<template>
    <form @submit.prevent="submitCategoryDetails()">
        <div class="row">
            <FormInput label="Sub Category Name" name="sub_cat_name" req="true" v-model="sub_category_data.sub_cat_name" cls="col-md-12"/>
            <FormText label="Category Description" name="description" v-model="sub_category_data.description" cls="col-md-12 mt-3"/>
        </div>
        <div class="modal-footer mt-3">
            <button class="btn btn-success" type="submit" :disabled="loading">Submit</button>
        </div>
    </form>
</template>