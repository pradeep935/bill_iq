<script setup>
    import { ref, onMounted, watch } from 'vue';
    import FileUpload from '../../Components/Common/FileUpload.vue';
    import DBService from '@/Service/Utils/DBService';
    import { useForm } from 'vee-validate';
    const { handleSubmit, resetForm } = useForm();
    const { sponsorships_id} = defineProps(['sponsorships_id']);
    const emit = defineEmits(['AddSponsorshipEmit']);
    const processing = ref(false);
    const intermidiaries = ref([]);
    const deliverables_type = ref([]);
    const formData = ref({
        deliverables: [],
        payments: [],
    });

    watch(
        () => sponsorships_id,
        () => {
            getEditDetail();
        }
        );

    onMounted(()=>{
        getParams();
    });

    function getParams(){
        DBService.getData('/api/sponsorships/get-params').then( function(data) {
            if(data.success){
                deliverables_type.value = data.deliverables_type;
                intermidiaries.value = data.intermidiaries;                
            }
        });
    };


    function getEditDetail(){
        if (sponsorships_id > 0) {
            DBService.getData('/api/sponsorships/get-sponsorship/'+sponsorships_id).then( (data)=>{
                if(data.success){
                    formData.value = data.sponsorship;
                }
            });
        } else{
            formData.value = {
                deliverables: [],
                payments: [],
            }
            resetForm();
        }
    };

    const onSubmit = handleSubmit((values) => {
        processing.value = true;
        DBService.postData('/api/sponsorships/submit',formData.value).then(function(data){
            if(data.success){
                formData.value = {
                    deliverables: [],
                    payments: [],
                }
                resetForm();
                emit('AddSponsorshipEmit');  
            }
            processing.value = false;
            bootbox.alert(data.message);
        });
    });

    function removeDoc(index) {
        formData.value.deliverables.splice(index, 1);
    };

    function removePay(index) {
        formData.value.payments.splice(index, 1);
    };

    const issue_invoice = ref([
        { id: 1, invoice: 'Yes' },
        { id: 0, invoice: 'No' },

    ]);


    function addDeliverables() {
        if (!formData.value.deliverables) {
            formData.value.deliverables = [];
        }
        formData.value.deliverables.push({
            deliverable_id: '',
            remarks: '',
            file: '',
        });
    };

    function addPayments() {
        if (!formData.value.payments) {
            formData.value.payments = [];
        }
        formData.value.payments.push({
            amount: '',
            remarks: '',
            payment_date: '',
        });
    };

</script>
<template>
    <form @submit.prevent="onSubmit">
        <div class="container-fluid">
            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="fw-semibold border-bottom pb-2 mb-3">
                        Sponsorship Details
                    </h5>

                    <div class="row g-3">
                        <FormInput label="Name" v-model="formData.name" name="name" req="true" cls="col-md-4" />

                        <FormSelect label="Intermidiary" v-model="formData.intermidiary_id" name="intermidiary_id" :options="intermidiaries" opt_id="intermidiary_id" opt_name="intermidiary_name" cls="col-md-4" />

                        <FormInput type="number" label="Amount" v-model="formData.amount" name="amount" req="true" cls="col-md-4" />

                        <FormInput label="Start Date" req="true" type="date" v-model="formData.start_date" name="start_date" cls="col-md-4" />

                        <FormInput label="End Date" req="true" type="date" v-model="formData.end_date" name="end_date" cls="col-md-4" />

                        <FileUpload label="Agreement" v-model="formData.agreement"cls="col-md-4" />

                        <FormInput label="Jersey Spot Allocation" v-model="formData.jersey_spot" name="jersey_spot" cls="col-md-4" />

                        <FormInput label="Bonus Amount" type="number" v-model="formData.bonus_amount" name="bonus_amount" cls="col-md-4" />
                        
                        <InputText label="Bonus Condition" v-model="formData.bonus_condition" name="bonus_condition" cls="col-md-4" />

                        <InputText label="Details" v-model="formData.details" cls="col-md-12" />
                    </div>
                </div>
            </div>
            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="fw-semibold border-bottom pb-2 mb-3">
                        Deliverables
                    </h5>

                    <div class="row g-3 align-items-end mb-3" v-for="(deliverable, key) in formData.deliverables" :key="key">

                        <FormSelect :label="`Deliverable Type ${key + 1}`" v-model="deliverable.deliverable_id" :name="`deliverable_${key}_id`" :options="deliverables_type" opt_id="type_id" opt_name="deliverables_type" cls="col-md-3" />

                        <FormInput label="Remarks" v-model="deliverable.remarks"  :name="`deliverable_${key}_remarks`" cls="col-md-3" />

                        <FileUpload label="File" v-model="deliverable.file" cls="col-md-3" />

                        <div class="col-md-3 d-flex justify-content-end">
                            <button type="button" @click="removeDoc(key)" class="btn btn-outline-danger btn-sm"> Remove </button>
                        </div>
                    </div>

                    <button class="btn btn-outline-primary btn-sm" @click.prevent="addDeliverables" type="button">
                         <i class="bi bi-plus"></i> {{ formData.deliverables.length == 0 ? "Add Deliverables" : "Add More"}}
                    </button>
                </div> 
            </div>
            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="fw-semibold border-bottom pb-2 mb-3">
                        Payments
                    </h5>
                    <div class="table-responsive"  v-if="formData.payments.length > 0" >
                        <table class="table table-bordered align-middle">
                            <thead class="table-light text-center">
                                <tr>
                                    <th></th>
                                    <th>Amount Due</th>
                                    <th>Due Date</th>
                                    <th>Invoice Issue</th>
                                    <th>Payment Received</th>
                                    <th>Payment Date</th>
                                    <th>Balance</th>
                                    <th>Remarks</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(payment, key) in formData.payments" :key="key">
                                    <td class="text-center text-muted fw-bold">
                                        {{ key + 1 }}
                                    </td>
                                    <td class="p-2">                                        
                                        <FormInput type="number" v-model="payment.amount" :name="`payment_${key}_amount`" />
                                    </td>
                                    <td class="p-2">
                                        <FormInput type="date" v-model="payment.due_date" :name="`payment_${key}_due_date`" />
                                    </td>
                                    <td class="p-2">
                                        <FormSelect v-model="payment.invoice_issue_id" :name="`payment_${key}_invoice_issue_id`" :options="issue_invoice" opt_id="id" opt_name="invoice" />
                                    </td>
                                    <td class="p-2">
                                        <FormInput type="number" v-model="payment.payment_received" :name="`payment_${key}_payment_received`" />
                                    </td>
                                    <td class="p-2">
                                        <FormInput type="date" v-model="payment.payment_date" :name="`payment_${key}_payment_date`" />
                                    </td>
                                    <td class="p-2">
                                        <FormInput type="number" v-model="payment.balance" :name="`payment_${key}_balance`" />
                                    </td>
                                    <td class="p-2">
                                        <FormInput v-model="payment.remark" :name="`payment_${key}_remark`" />
                                    </td> 
                                    <td class="text-center">
                                        <button type="button" @click.prevent="removePay(key)" class="btn btn-outline-danger btn-sm">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <button class="btn btn-outline-primary btn-sm mt-2" @click.prevent="addPayments" type="button">
                         <i class="bi bi-plus"></i> {{ formData.payments.length == 0 ? "Add Payments" : "Add More"}}
                    </button>
                </div>
            </div>
            <div class="modal-footer justify-content-end">
                <FormButton cls="btn-primary" :processing="processing" :disabled="processing" type="submit">Save</FormButton>
            </div>
        </div>
    </form>
</template>
<style scoped>
    .card {
        border-radius: 8px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.05);
    }
    .table th {
        font-weight: 600;
    }
</style>