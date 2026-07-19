<script setup>
    import { computed, ref, watch, nextTick } from 'vue';
    import { useForm } from 'vee-validate';
    import DBService from '@/Service/Utils/DBService';
    import FormInputBox from '@/Components/NewComponents/FormInputBox.vue';
    import SectionToggle from '@/Components/NewComponents/SectionToggle.vue';
    import ChangePictureModal from '@/Components/Common/ChangePictureModal.vue';

    const { handleSubmit, resetForm } = useForm();

    const props = defineProps({
        params: {
            type: Array,
            default: () => [],
        },
        privileges: {
            type: Array,
            default: () => [],
        },
        parentOptions: {
            type: Object,
            default: () => ({
                priv_2_users: [],
                priv_3_users: [],
            }),
        },
        trigger: {
            type: Number,
            default: 0,
        },
    });

    const emit = defineEmits(['close']);

    const loading = ref(false);
    const processing_coach = ref(false);
    const processing = ref(false);
    const formData = ref({});
    const genders = [{ label: 'Male', value: 1 }, { label: 'Female', value: 2 }];

    watch(() => props.trigger, () => {
        formData.value = {};
        resetForm();
        nextTick(() => {
            const body = document.querySelector('#add_user_management_modal .offcanvas-body');
            body?.scrollTo({ top: 0 });
        });
    });

    watch(() => formData.value.privilege, (value) => {
        formData.value.parent_user_id = '';
    });

    const availableParents = computed(() => {
        if (Number(formData.value.privilege) === 3) {
            return props.parentOptions?.priv_2_users || [];
        }

        if (Number(formData.value.privilege) === 0) {
            return props.parentOptions?.priv_3_users || [];
        }

        return [];
    });

    const onSubmit = handleSubmit(() => {
        processing.value = true;
        DBService.postData('/api/user-management/store-user', formData.value).then((data) => {
            if (data.success) {
                formData.value = {};
                resetForm();
                emit('close');
            }

            bootbox.alert(data.message);
            processing.value = false;
        });
    });

    function handlePlayerPhoto() {
    }

    function findCoachRegistrationId(){
        if(formData.value.first_name || formData.value.last_name || formData.value.dob || formData.value.gender || formData.value.state){
            bootbox.confirm("Existing data will be replaced. Are you sure you wants to continue?", function(result){
                if(result){
                    searchForRegistrationId();
                }
            });
        } else{
            searchForRegistrationId();
        }
    }

    function searchForRegistrationId(){
        processing_coach.value = true;
        DBService.getData('/api/staff/find-registration-id/' + formData.value.registration_id).then( (data)=>{
            if(data.success){
                formData.value.first_name = data.coach.first_name;
                formData.value.last_name = data.coach.last_name;
                formData.value.dob = data.coach.dob;
                formData.value.gender = data.coach.gender;
                formData.value.state = data.coach.state_name;
            } else{
                bootbox.alert(data.message);
            }
            processing_coach.value = false;
        });
    }
</script>

<template>
    <div class="offcanvas-body modal-right__body">
        <div class="row">
            <div class="col-md-12">
                <div class="profile-upload-section">
                    <ChangePictureModal modalId="user-management-photo" v-model="formData.profile_pic" @uploaded="handlePlayerPhoto" />
                    <div class="upload-title">Upload User Photo</div>
                </div>
            </div>
        </div>

        <form @submit.prevent="onSubmit()" id="userManagementAddSubmit">
            <SectionToggle icon_cls="bi bi-shield-lock" title="Privilege Details" :defaultOpen="true">
                <div class="row g-1">
                    <FormSelect :options="privileges" v-model="formData.privilege" name="privilege" label="Privilege" cls="col-md-12" req="true" />

                    <FormSelect
                        v-if="Number(formData.privilege) === 3 || Number(formData.privilege) === 0"
                        :options="availableParents"
                        v-model="formData.parent_user_id"
                        opt_id="id"
                        opt_name="name"
                        name="parent_user_id"
                        label="Parent User"
                        cls="col-md-12"
                        req="true"
                    />

                    <FormInput :disabled="processing_coach" name="registration_id" v-model="formData.registration_id" cls="col-md-12 mt-3" placeholder="Registration ID"></FormInput>
                    <div class="col-md-12 text-end">
                        <Button2 :processin="processing_coach" @click="findCoachRegistrationId()" cls="btn btn-sm btn-success"><i class="bi bi-search me-1"></i> Search</Button2>
                    </div>
                </div>
            </SectionToggle>

            <SectionToggle icon_cls="bi bi-person" title="Personal Details" :defaultOpen="true" cls="mt-3">
                <div class="row g-3">
                    <FormInput :disabled="processing_coach" label="First Name" placeholder="First Name" name="first_name" v-model="formData.first_name" cls="col-md-6" req="true" />
                    <FormInput :disabled="processing_coach" label="Last Name" placeholder="Last Name" name="last_name" v-model="formData.last_name" cls="col-md-6" />
                    <FormInput label="Email" placeholder="Email" name="email" v-model="formData.email" cls="col-md-6" req="true" validate="email" />
                    <FormInput label="Phone Number" placeholder="Phone Number" name="mobile" v-model="formData.mobile" cls="col-md-6" req="true" />
                    <FormSelect :disabled="processing_coach" :options="genders" v-model="formData.gender" name="gender" label="Gender" :req="true" cls="col-md-6" />
                    <FormInput :disabled="processing_coach" label="Birth Date" type="date" name="dob" v-model="formData.dob" cls="col-md-6" />
                    <FormInput label="Nick Name" placeholder="Commonly used nickname" name="nick_name" v-model="formData.nick_name" cls="col-md-6" />
                    <FormInput label="Studies" placeholder="Studies" name="studies" v-model="formData.studies" cls="col-md-6" />
                    <FormSelect label="Nationality" name="nationality" :options="params" v-model="formData.nationality" cls="col-md-6" req="true" />
                    <FormSelect label="Second Nationality" :options="params" name="second_nationality" v-model="formData.second_nationality" cls="col-md-6" />
                    <FormInput :disabled="processing_coach" label="State" placeholder="State" name="state" v-model="formData.state" cls="col-md-6" />
                    <FormInput label="District" placeholder="District" name="district" v-model="formData.district" cls="col-md-6" />
                    <FormInput label="City" placeholder="City" name="city" v-model="formData.city" cls="col-md-6" />
                    <FormText label="Address" placeholder="Address" name="address" v-model="formData.address" cls="col-md-12" />
                </div>
            </SectionToggle>

            <SectionToggle icon_cls="bi bi-briefcase text-success" title="Professional Details" :defaultOpen="true" cls="mt-2">
                <div class="row g-3">
                    <FormInput label="Profession" placeholder="Profession" name="profession" v-model="formData.profession" cls="col-md-6" />
                    <FormInput label="Year of Experience" name="year_of_experience" v-model="formData.year_of_experience" cls="col-md-6" type="number" />
                    <FormInput label="Achievements" placeholder="Achievements" name="achievements" v-model="formData.achievements" cls="col-md-6" />
                    <FormInput label="Current Job" placeholder="Current Job" name="current_job" v-model="formData.current_job" cls="col-md-6" />
                    <FormInputBox box_entry="EUR" label="Annual Net Salary" placeholder="Annual Net Salary" name="annual_net_salary" v-model="formData.annual_net_salary" cls="col-md-12" type="number" />
                    <FormInput label="Contract Expiry Date" type="date" name="contract_expiry_date" v-model="formData.contract_expiry_date" cls="col-md-6" />
                    <FormInput label="Preferred Tactical Formations" name="preferred_tactical_formations" v-model="formData.preferred_tactical_formations" cls="col-md-6" />
                    <FormText label="Languages Spoken" name="languages" v-model="formData.languages" cls="col-md-12" />
                </div>
            </SectionToggle>

            <SectionToggle icon_clr="#d97706" icon_cls="bi bi-person-lines-fill" title="Representative Details" :defaultOpen="true" cls="mt-2">
                <div class="row g-2">
                    <FormInput label="Representative" placeholder="Representative of the" name="rep_name" v-model="formData.rep_name" cls="col-md-6" />
                    <FormInput label="Representative Contract Expires" type="date" name="rep_contract_expiry_date" v-model="formData.rep_contract_expiry_date" cls="col-md-6" />
                    <FormInput type="number" label="Representative's Contact" placeholder="Representative's Contact" name="rep_mobile" v-model="formData.rep_mobile" cls="col-md-6" />
                    <FormInput label="Representative Link" placeholder="Hyperlink for Representative Link" name="rep_link" v-model="formData.rep_link" cls="col-md-6" />
                </div>
            </SectionToggle>

            <SectionToggle icon_clr="#0891b2" icon_cls="bi bi-share" title="Social Media" :defaultOpen="true" cls="mt-2">
                <div class="row g-2">
                    <FormInput left_box_text="bi bi-facebook" box_type="icon" label="Facebook" name="facebook" v-model="formData.facebook" cls="col-md-12" />
                    <FormInput left_box_text="bi bi-instagram" box_type="icon" label="Instagram" name="instagram" v-model="formData.instagram" cls="col-md-12" />
                    <FormInput left_box_text="bi bi-twitter-x" box_type="icon" label="X (Twitter)" name="twitter" v-model="formData.twitter" cls="col-md-12" />
                    <FormInput left_box_text="bi bi-linkedin" box_type="icon" label="LinkedIn" name="linkedin" v-model="formData.linkedin" cls="col-md-12" />
                </div>
            </SectionToggle>

            <SectionToggle icon_cls="bi bi-journal-text text-muted" title="Notes" :defaultOpen="false" cls="mt-2">
                <div class="row">
                    <FormText label="Notes" placeholder="Other Information" name="other_information" v-model="formData.other_information" cls="col-md-12" />
                </div>
            </SectionToggle>
        </form>
    </div>

    <div class="oc-footer">
        <FormButton cls="btn-primary" form="userManagementAddSubmit" :disabled="loading" :processing="processing">Save</FormButton>
    </div>
</template>

<style scoped>
    .profile-upload-section {
        display: flex;
        flex-direction: column;
        align-items: center;
        margin-bottom: 20px;
    }

    .upload-title {
        margin-top: 10px;
        font-size: 13px;
        font-weight: 600;
        color: #64748b;
    }
</style>
