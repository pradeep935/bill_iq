<script setup>
    import { computed, ref, watch } from 'vue';
    import DBService from '@/Service/Utils/DBService.js';

    const props = defineProps(['assignment_id','privilege']);
    const emit = defineEmits(['closePopup']);
    const s3_url_link = import.meta.env.VITE_S3_URL;

    const loading = ref(false);
    const submitting = ref(false);
    const obj = ref({});
    const timeline = ref([]);

    const STATUS_DRAFT = 0;
    const STATUS_SUBMITTED = 1;
    const STATUS_APPROVED = 2;
    const STATUS_NEED_REVISION = -1;

    const status_list = [
        {label: 'Draft', value: STATUS_DRAFT},
        {label: 'Submitted', value: STATUS_SUBMITTED},
        {label: 'Need Revision', value: STATUS_NEED_REVISION},
        {label: 'Approved', value: STATUS_APPROVED},
    ];

    // -----------------------------------------------------------------------------
    // Check logged-in user's role
    // -----------------------------------------------------------------------------
    const isLocalScout = computed(() => Number(props.privilege) === 0);
    const isRegionalScout = computed(() => Number(props.privilege) === 3);
    const isScoutManager = computed(() => Number(props.privilege) === 2);
    const isSuperAdmin = computed(() => Number(props.privilege) === 1);

    // -----------------------------------------------------------------------------
    // Current assignment status (Draft / Submitted / Approved / Need Revision)
    // -----------------------------------------------------------------------------
    const currentStatus = computed(() => Number(obj.value.status ?? STATUS_DRAFT));

    // -----------------------------------------------------------------------------
    // Privilege of the user to whom this assignment is assigned
    // -----------------------------------------------------------------------------
    const assignedToPrivilege = computed(() => Number(obj.value.assigned_to_privilege));

    // -----------------------------------------------------------------------------
    // Decide whether logged-in user can submit the assignment
    //
    // Regional Scout assignment  -> only Regional Scout can submit
    // Local Scout assignment     -> only Local Scout can submit
    // -----------------------------------------------------------------------------
    const canSubmit = computed(() => {
        if (assignedToPrivilege.value === 3 && isRegionalScout.value) {
            return true;
        }

        if (assignedToPrivilege.value === 0 && isLocalScout.value) {
            return true;
        }

        return false;
    });

    // -----------------------------------------------------------------------------
    // Response can be edited only before submission or after Need Revision
    // -----------------------------------------------------------------------------
    const canEditResponse = computed(() => {
        if (!canSubmit.value) {
            return false;
        }

        if ([STATUS_DRAFT, STATUS_NEED_REVISION].includes(currentStatus.value)) {
            return true;
        }

        return false;
    });


    // -----------------------------------------------------------------------------
    // Decide who can review the assignment
    //
    // Assignment -> Regional Scout
    //      Review by : Super Admin + Scout Manager
    //
    // Assignment -> Local Scout
    //      Review by : Super Admin + Scout Manager + Regional Scout
    // -----------------------------------------------------------------------------
    const canReview = computed(() => {
        if (assignedToPrivilege.value === 3) {
            if (isSuperAdmin.value || isScoutManager.value) {
                return true;
            }
        }

        if (assignedToPrivilege.value === 0) {
            if (isSuperAdmin.value || isScoutManager.value || isRegionalScout.value) {
                return true;
            }
        }

        return false;
    });

    // -----------------------------------------------------------------------------
    // Timeline list (returns empty array if no comments)
    // -----------------------------------------------------------------------------
    const timelineItems = computed(() => timeline.value || []);

    // -----------------------------------------------------------------------------
    // Badge shown at the top according to current assignment status
    // -----------------------------------------------------------------------------
    const workflowBadge = computed(() => {
        if (currentStatus.value === STATUS_APPROVED) {
            return { label: 'Approved', class: 'bg-success' };
        }

        if (currentStatus.value === STATUS_NEED_REVISION) {
            return { label: 'Need Revision', class: 'bg-warning text-dark' };
        }

        if (currentStatus.value === STATUS_SUBMITTED) {
            return { label: 'Submitted', class: 'bg-primary' };
        }

        return { label: 'Draft', class: 'bg-secondary' };
    });

    
    // -----------------------------------------------------------------------------
    // Get latest reviewer remark (Need Revision / Review comment)
    // -----------------------------------------------------------------------------
    const latestRevisionRemark = computed(() => {
        const revisions = timeline.value.filter(item => item.type == 2);
        return revisions.length ? revisions[revisions.length - 1] : null;
    });

    watch(
        () => props.assignment_id,
        fetchStatus,
        { immediate: true }
    );

    async function fetchStatus() {
        if (!props.assignment_id || Number(props.assignment_id) === 0) {
            return;
        }

        loading.value = true;

        try {
            const data = await DBService.postData('/api/assignments/fetch-status/' + props.assignment_id);
            if (data.success) {
                obj.value = data.assignment ?? {};
                obj.value.status = Number(obj.value.status ?? STATUS_DRAFT);
                obj.value.response_comment = '';
                obj.value.revision_comment = '';
                timeline.value = data.timeline || [];
            } else {
                bootbox.alert(data.message || 'Unable to load assignment.');
            }
        } finally {
            loading.value = false;
        }
    }

    function submitStatus(action = 'submit') {
        obj.value.assignment_id = props.assignment_id;
        obj.value.status = action === 'approve' ? STATUS_APPROVED : (action === 'revision' ? STATUS_NEED_REVISION : STATUS_SUBMITTED);

        if (canSubmit.value) {
            bootbox.confirm(
                'You can no longer change this after submission. Are you sure?',
                (result) => {
                    if (result) {
                        submitData();
                    }
                }
            );
        } else {
            if (action === 'revision' && !obj.value.revision_comment) {
                bootbox.alert('Please enter revision remarks.');
                return;
            }

            submitData();
        }
    }

    function submitData(){
        submitting.value = true;
        DBService.postData('/api/assignments/submit-change-status', obj.value).then((data) => {
            if (data.success) {
                obj.value.response_comment = '';
                obj.value.revision_comment = '';
                fetchStatus();
            } else {
                bootbox.alert(data.message || 'Unable to update assignment.');
            }
        }).finally(() => {
            submitting.value = false;
        });
    }

    function formatDate(date) {
        if (!date) return '';

        return new Date(date).toLocaleString('en-IN', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }
</script>

<template>
    <Loading :loading="loading" v-if="loading" />
    <div class="report-page" v-else>
        <div class="report-shell">
            <div class="report-main">
                <div class="report-card">
                    <div class="report-card-header">
                        <div>
                            <div class="section-kicker">
                                <i class="bi bi-clipboard-check"></i>
                                Report Details
                            </div>
                            <h5 class="section-title mb-0">
                                Assignment Report
                            </h5>
                        </div>
                        <span class="status-pill" :class="workflowBadge.class">
                            {{ workflowBadge.label }}
                        </span>
                    </div>

                    <div class="report-card-body">
                        <div class="alert alert-info mb-4" v-if="canSubmit && currentStatus === STATUS_SUBMITTED">
                            Your assignment has been submitted successfully. Waiting for Scout Manager review.
                        </div>

                        <div class="alert alert-success mb-4" v-if="canSubmit && currentStatus === STATUS_APPROVED">
                            This assignment has been approved and is permanently locked.
                        </div>

                        <div class="alert alert-warning mb-4" v-if="canSubmit && latestRevisionRemark && currentStatus === STATUS_NEED_REVISION">
                            <div class="fw-bold mb-1">
                                Revision Remarks
                            </div>
                            <div>
                                {{ latestRevisionRemark.comment }}
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">
                                    Assignment
                                </label>
                                <InputText v-model="obj.ques_text" :disabled="true"/>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">
                                    Due Date
                                </label>
                                <InputField type="date" v-model="obj.last_date" :disabled="true" />
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">
                                    Assignment File
                                </label>
                                <div class="file-box">
                                    <a v-if="obj.ques_file" :href="s3_url_link + obj.ques_file" target="_blank">View File</a>
                                    <span v-else>-</span>
                                </div>
                            </div>

                            <div class="col-12" v-if="canSubmit">
                                <label class="form-label">
                                    Response
                                </label>
                                <InputText v-model="obj.response_comment" :disabled="!canEditResponse"/>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">
                                    Submission Date
                                </label>
                                <InputField type="date" v-model="obj.submit_date" :disabled="!canEditResponse" />
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">
                                    Response Status
                                </label>
                                <SelectField :options="status_list" v-model="obj.status" :disabled="true" />
                            </div>

                            <FileUpload v-if="canEditResponse" v-model="obj.response_file" label="Response File" cls="col-md-6" />

                            <div class="col-md-6" v-else>
                                <label class="form-label">
                                    Response File
                                </label>
                                <div class="file-box">
                                    <a v-if="obj.response_file" :href="s3_url_link + obj.response_file" target="_blank">View File</a>
                                    <span v-else>-</span>
                                </div>
                            </div>

                            <div class="col-12" v-if="canReview" >
                                <label class="form-label">
                                    Revision Remarks
                                </label>
                                <InputText v-model="obj.revision_comment" />
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <aside class="report-sidebar">
                <div class="comments-card">
                    <div class="comments-header">
                        <div>
                            <h6 class="comments-title">
                                <i class="bi bi-chat-left-text"></i>
                                Comments Timeline
                            </h6>
                            <small class="text-muted">
                                Review notes and scout responses
                            </small>
                        </div>

                        <span class="count-pill">
                            {{ timelineItems.length }}
                        </span>
                    </div>

                    <div class="timeline" style="height: 620px; overflow-y: auto;">
                        <div v-for="item in timelineItems" :key="item.id" class="timeline-item" >
                            <div class="timeline-marker profile-marker">
                                <img v-if="item.profile_pic" :src="s3_url_link + item.profile_pic" @error="$event.target.style.display='none'" />
                                <div v-else class="profile-placeholder" >
                                    {{ item.user_name?.charAt(0)?.toUpperCase() }}
                                </div>
                            </div>

                            <div class="timeline-content">
                                <div class="timeline-meta">
                                    <div>
                                        <strong>{{ item.user_name }}</strong>
                                        <span class="role-badge" :class="item.type == 1 ? 'scout' : 'manager'">
                                            {{ item.type == 1 ? 'Scout' : 'Reviewer' }}
                                        </span>
                                    </div>
                                    <small>{{ formatDate(item.created_at) }}</small>
                                </div>

                                <div class="timeline-label">
                                    {{ item.type == 1 ? 'Response' : 'Revision Remark' }}
                                </div>

                                <p>{{ item.comment }}</p>

                                <a v-if="item.file_path" :href="s3_url_link + item.file_path" target="_blank" class="timeline-file">
                                    <i class="bi bi-paperclip"></i>
                                    View attachment
                                </a>
                            </div>
                        </div>

                        <div class="empty-timeline" v-if="!timelineItems.length">
                            <i class="bi bi-chat-square-text"></i>
                            <span>No comments yet</span>
                        </div>
                    </div>
                </div>
            </aside>
        </div>
        <div class="modal-footer report-actions mt-3">
            <Button2 v-if="canEditResponse" :processing="submitting" cls="btn btn-success px-4" @click="submitStatus()">
                Submit
            </Button2>
            <Button2 v-if="canReview" :processing="submitting" cls="btn btn-outline-warning px-4" @click="submitStatus('revision')">
                Need Revision
            </Button2>
            <Button2 v-if="canReview" :processing="submitting" cls="btn btn-success px-4" @click="submitStatus('approve')">
                Approve
            </Button2>
        </div>
    </div>
</template>

<style scoped>
    .report-page {
        background: #f8fafc;
        color: #111827;
        padding: 16px;
    }

    .report-shell {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 35%;
        gap: 18px;
        align-items: start;
    }

    .report-main {
        display: grid;
        gap: 14px;
        min-width: 0;
    }

    .report-card,
    .comments-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        box-shadow: 0 10px 30px rgba(15, 23, 42, 0.05);
    }

    .report-card-header,
    .comments-header {
        align-items: center;
        border-bottom: 1px solid #eef2f7;
        display: flex;
        justify-content: space-between;
        gap: 14px;
        padding: 18px 20px;
    }

    .report-card-body {
        padding: 20px;
    }

    .section-kicker,
    .comments-title {
        align-items: center;
        color: #111827;
        display: flex;
        gap: 8px;
        font-size: 15px;
        font-weight: 700;
        margin: 0;
    }

    .section-kicker {
        color: #475569;
        font-size: 13px;
        margin-bottom: 5px;
    }

    .section-title {
        font-size: 19px;
        font-weight: 800;
        letter-spacing: 0;
    }

    .status-pill,
    .count-pill,
    .role-badge {
        border-radius: 999px;
        font-size: 12px;
        font-weight: 700;
        line-height: 1;
        padding: 7px 10px;
        white-space: nowrap;
    }

    .count-pill {
        background: #eff6ff;
        color: #2563eb;
    }

    .form-label {
        color: #334155;
        font-size: 13px;
        font-weight: 700;
        margin-bottom: 7px;
    }

    .file-box {
        align-items: center;
        background: #f8fafc;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        display: flex;
        min-height: 38px;
        padding: 8px 10px;
    }

    .alert {
        border: 0;
        border-radius: 10px;
        font-size: 14px;
    }

    .comments-card {
        display: flex;
        flex-direction: column;
        max-height: calc(100vh - 120px);
        position: sticky;
        top: 16px;
    }
/*
    .timeline {
        flex: 1;
        min-height: 240px;
        overflow-y: auto;
        padding: 18px 18px 4px;
    }
*/
    .timeline-item {
        display: grid;
        gap: 12px;
        grid-template-columns: 38px minmax(0, 1fr);
        padding-bottom: 22px;
        position: relative;
    }

    .timeline-item::before {
        background: #dbeafe;
        bottom: 0;
        content: "";
        left: 18px;
        position: absolute;
        top: 38px;
        width: 2px;
    }

    .timeline-item:last-child::before {
        display: none;
    }

    .timeline-content {
        min-width: 0;
        padding-top: 3px;
    }

    .timeline-meta {
        align-items: flex-start;
        display: flex;
        gap: 10px;
        justify-content: space-between;
        margin-bottom: 8px;
    }

    .timeline-meta strong {
        color: #111827;
        font-size: 13px;
        margin-right: 8px;
    }

    .timeline-meta small {
        color: #64748b;
        font-size: 12px;
        white-space: nowrap;
    }

    .role-badge {
        padding: 4px 8px;
    }

    .role-badge.scout {
        background: #dcfce7;
        color: #15803d;
    }

    .role-badge.manager {
        background: #dbeafe;
        color: #2563eb;
    }

    .timeline-label {
        color: #64748b;
        font-size: 12px;
        font-weight: 700;
        margin-bottom: 4px;
    }

    .timeline-content p {
        color: #111827;
        font-size: 14px;
        line-height: 1.55;
        margin: 0;
        overflow-wrap: anywhere;
    }

    .timeline-file {
        align-items: center;
        display: inline-flex;
        gap: 6px;
        font-size: 13px;
        font-weight: 700;
        margin-top: 8px;
        text-decoration: none;
    }

    .empty-timeline {
        align-items: center;
        color: #64748b;
        display: flex;
        flex-direction: column;
        font-weight: 600;
        gap: 10px;
        justify-content: center;
        min-height: 200px;
    }

    .empty-timeline i {
        font-size: 28px;
    }

    /*.report-actions {
        background: #fff;
        border-top: 1px solid #e5e7eb;
        gap: 10px;
        margin: 16px -16px -16px;
        padding: 14px 16px;
    }*/

    .report-actions {
        margin: 0;
        padding: 16px;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
    }

    .profile-marker {
        width: 42px;
        height: 42px;
        border-radius: 50%;
        overflow: hidden;
        flex-shrink: 0;
        background: #eef3ff;
        border: 2px solid #dbe7ff;
    }

    .profile-marker img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .profile-placeholder {
        width: 100%;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        color: #2563eb;
        background: #eaf2ff;
    }

    @media (max-width: 991.98px) {
        .report-shell {
            grid-template-columns: 1fr;
        }

        .comments-card {
            max-height: none;
            position: static;
        }
    }

    @media (max-width: 767.98px) {
        .report-page {
            padding: 10px;
        }

        .report-card-header,
        .comments-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .timeline-meta {
            flex-direction: column;
            gap: 4px;
        }

        .report-actions {
            margin: 14px -10px -10px;
        }
    }

    .status-pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 90px;
    }

    .timeline-marker {
        position: relative;
        z-index: 2;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .timeline-file:hover {
        text-decoration: underline;
    }

    .file-box a {
        font-weight: 600;
        text-decoration: none;
    }

    .file-box a:hover {
        text-decoration: underline;
    }

    .timeline::-webkit-scrollbar {
        width: 6px;
    }

    .timeline::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 20px;
    }

    .timeline::-webkit-scrollbar-track {
        background: transparent;
    }

   /* .report-actions {
        display: flex;
        justify-content: flex-end;
        align-items: center;
    }*/

    .timeline-file,
    .file-box a {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .report-card-body .form-control,
    .report-card-body .form-select {
        min-height: 42px;
        border-radius: 8px;
    }

    .report-card-body textarea.form-control {
        min-height: 100px;
    }

    .timeline-file i {
        font-size: 14px;
    }

    .profile-placeholder {
        font-size: 15px;
        text-transform: uppercase;
    }

    @media (max-width: 576px) {
        .report-actions {
            flex-wrap: wrap;
        }

        .report-actions .btn {
            flex: 1 1 100%;
        }
    }

    .timeline-item {
        animation: fadeIn .25s ease;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(6px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

</style>
