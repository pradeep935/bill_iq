<script setup>
    import { ref, watch, onMounted } from 'vue';
    import DBService from '@/Service/Utils/DBService.js'
    import ShadowField from '@/Pages/ShadowTeam/ShadowField.vue';
    import { useForm} from 'vee-validate';
    const { handleSubmit, resetForm} = useForm();

	const { shadow_obj, dropdown_obj } = defineProps(['shadow_obj', 'dropdown_obj']);
	const emit = defineEmits(['close']);

	watch(
	    () => shadow_obj,
	    (newVal) => {
	        if (newVal) {
	            resetForm({
	                values: {
	                    shadow_name: newVal.shadow_name || '',
	                    formation_id: newVal.formation_id || '',
	                    description: newVal.note || ''
	                }
	            });
	        }
	    },
	    { immediate: true, deep: true }
	);

	const loading = ref(false);

	const addShadowTeam = handleSubmit((values, {resetForm})=> {
        loading.value = true;
        DBService.postData('/api/shadow-team/save-shadow-team',shadow_obj) .then( (data) => {
            if(data.success){
                resetForm();
                emit('close');
            }
            bootbox.alert(data.message);
            loading.value = false;
        });
    });
	
</script>
<template>
	<div class="offcanvas-body modal-right__body">
		<form id="shadow_team_form" @submit.prevent="addShadowTeam()">
			<div class="row g-3">
				<FormInput name="shadow_name" req="true" v-model="shadow_obj.shadow_name" cls="col-md-12" label="Shadow Team Name" placeholder="Assign A Name To Shadow Team"></FormInput>

				<FormSelect name="formation_id" req="true" v-model="shadow_obj.formation_id" cls="col-md-12" label="Team Formation" :options="dropdown_obj.formation_list" opt_id="id" opt_name="format"></FormSelect>

				<FormText name="description" label="Description" v-model="shadow_obj.note" cls="col-md-12" rows="3" placeholder="Add Description..."></FormText>

				<div class="col-md-12 mt-2 mb-0" v-if="shadow_obj.formation_id && shadow_obj.formation_id!=''">
					<div class="section-title">Formation Preview</div>
					<div class="section-subtitle">Preview the selected formation layout.</div>
				</div>

				<div class="col-md-12 shadowF" v-if="shadow_obj.formation_id && shadow_obj.formation_id!=''">
					<ShadowField  :width="520" :rotate_field="false" :hoverable="false" :shadow_mapping_obj="shadow_obj" :editable="false"></ShadowField>
				</div>
			</div>
		</form>
	</div>
	<div class="oc-footer">
		<button class="btn btn-dark" type="submit" form="shadow_team_form" :disabled="loading">Submit</button>
	</div>
</template>

<style scoped>
	.section-title {
		font-weight: 700;
		font-size: 16px;
		color: #1f2a3a;
	}
	.section-subtitle {
		font-size: 12px;
		color: #6b7785;
		margin-top: 2px;
	}
	.shadowF {
		margin-top: 0px !important;
	}
</style>
