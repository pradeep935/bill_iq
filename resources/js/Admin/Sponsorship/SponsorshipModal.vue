<script setup>
    import { ref, watch } from 'vue';
    import DBService from '@/Service/Utils/DBService';
    const { sponsorships_id } = defineProps(['sponsorships_id']);
    const loading = ref(false);
    const sponsorship = ref(null);

    watch(
        () => sponsorships_id,
        () => {
            if (sponsorships_id) getDetail()
        },
        { immediate: true }
    );

    function getDetail() {
        loading.value = true
        DBService.getData('/api/sponsorships/get-detail/' + sponsorships_id)
        .then((data) => {
            if (data.success) {
                sponsorship.value = data.sponsorship
            }
            loading.value = false
        });
    };

    const formatCurrency = (val) => {
        if (!val) return '₹0'
            return '₹' + Number(val).toLocaleString('en-IN')
    };

</script>

<template>
    <Portlet>
        <Loading :loading="loading" />
        <div v-if="sponsorship" class="card shadow-sm p-4">
            <h5 class="mb-4">Sponsorship Details</h5>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="text-muted ">Name</label>
                    <div class="fw-bold">{{ sponsorship.name }}</div>
                </div>
                <div class="col-md-6">
                    <label class="text-muted">Amount</label>
                    <div class="fw-bold text-success">
                        <Money :amount="sponsorship.amount" />
                        <!-- {{ formatCurrency(sponsorship.amount) }} -->
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="text-muted">Bonus Condition</label>
                    <div>{{ sponsorship.bonus_condition }}</div>
                </div>
                <div class="col-md-6">
                    <label class="text-muted">Bonus Amount</label>
                    <div>
                        <Money :amount="sponsorship.bonus_amount" />
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="text-muted">Start Date</label>
                    <div>{{ sponsorship.start_date }}</div>
                </div>
                <div class="col-md-6">
                    <label class="text-muted">End Date</label>
                    <div>{{ sponsorship.end_date }}</div>
                </div>
                <div class="col-md-6">
                    <label class="text-muted">Intermediary</label>
                    <div>{{ sponsorship.intermidiary_name }}</div>
                </div>
                <div class="col-md-6">
                    <label class="text-muted">Jersey Spot Allocation</label>
                    <div>{{ sponsorship.jersey_spot }}</div>
                </div>
                <div class="col-md-6">
                    <label class="text-muted">Added By</label>
                    <div>{{ sponsorship.added_by_name }}</div>
                </div>
                <div class="col-md-6 mt-3" v-if="sponsorship.agreement">
                    <FileLink :link="sponsorship.agreement" title="View Agreement" />
                </div>
                
                <div v-if="sponsorship.detail" class="col-12">
                    <label class="text-muted">Details</label>
                    <div>{{ sponsorship.details }}</div>
                </div>
            </div>
        </div>
        <div v-if="sponsorship?.deliverables?.length" class="card shadow-sm mt-4 p-3" >
            <h6 class="mb-3">Deliverables</h6>
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>SN</th>
                            <th>Type</th>
                            <th>Remark</th>
                            <th>File</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(data, index) in sponsorship.deliverables" :key="index" >
                            <td>{{ index + 1 }}</td>
                            <td>{{ data.deliverables_type }}</td>
                            <td>{{ data.remark }}</td>
                            <td>
                                <FileLink v-if="data.file" :link="data.file" />
                                <span v-else class="text-muted">N/A</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div v-if="sponsorship?.payments?.length" class="card shadow-sm mt-4 p-3" >
            <h6 class="mb-3">Payments</h6>
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>SN</th>
                            <th>Amount</th>
                            <th>Due Date</th>
                            <th>Invoice Issue</th>
                            <th>Payment Received</th>
                            <th>Payment Date</th>
                            <th>Balance</th>
                            <th>Remark</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(data, index) in sponsorship.payments" :key="index" >
                            <td>{{ index + 1 }}</td>
                            <td class="text-success fw-bold">
                                {{ formatCurrency(data.amount) }}
                            </td>
                            <td>{{ data.due_date }}</td>
                            <td>{{ data.invoice_issue }}</td>
                            <td>
                                <span v-if="data.payment_received && !data.balance" class="badge bg-success" >
                                    Yes
                                </span>
                                <span v-if="data.payment_received && data.balance" class="badge bg-warning text-dark" >
                                    Yes
                                </span>
                                <span v-if="!data.payment_received && data.balance" class="badge bg-danger text-dark" >
                                    No
                                </span>
                            </td>
                            <td>{{ data.payment_date }}</td>
                            <td class="text-danger fw-bold">
                                {{ formatCurrency(data.balance) }}
                            </td>
                            <td>{{ data.remark }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </Portlet>
</template>
