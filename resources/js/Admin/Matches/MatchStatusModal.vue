<script setup>
    import { computed, ref, onMounted, watch } from 'vue';
    import DBService from '@/Service/Utils/DBService.js';
    import PhotoUpload from '@/Components/Common/PhotoUpload.vue';
    import VideoUpload from '@/Components/Common/VideoUpload.vue';

    const props = defineProps(['match_id','privilege']);
    const emit = defineEmits(['closePopup']);
    const s3_url_link = import.meta.env.VITE_S3_URL;
    const base_url = import.meta.env.VITE_APP_URL;
    const loading = ref(false);
    const submitting = ref(false);
    const approving = ref(false);
    const users = ref([]);
    const obj = ref({

    });
    const response_status_list = [{label: 'Draft', value: 1},{label: 'Submitted', value: 2},{label: 'Need Revision', value: 4}, {label: 'Approved', value: 3}, ];
    const status_list = [{label: 'Upcoming', value: 1},{label: 'Ongoing', value: 2}, {label: 'Completed', value: 3}, ];

    const photos = ref(['']);
    const videos = ref(['']);
    const reports = ref(['']);
    const isLocalScout = computed(() => Number(props.privilege) === 0);
    const isScoutManager = computed(() => Number(props.privilege) !== 0);
    const managerCanEdit = computed(() => isScoutManager.value);
    const STATUS_DRAFT = 1;
    const STATUS_SUBMITTED = 2;
    const STATUS_APPROVED = 3;
    const STATUS_NEED_REVISION = 4;
    const MATCH_STATUS_COMPLETED = 3;

    const addPhoto = () => {
        if (!localCanEdit.value) return;
        if (photos.value.length < 5) {
            photos.value.push('');
        } else {
            bootbox.alert("Maximum 5 photos allowed.");
        }
    };

    const removePhoto = (index) => {
        if (!localCanEdit.value) return;
        photos.value.splice(index, 1);
    };

    const addVideo = () => {
        if (!localCanEdit.value) return;
        if (videos.value.length < 2) {
            videos.value.push('');
        } else {
            bootbox.alert("Maximum 2 videos allowed.");
        }
    };

    const removeVideo = (index) => {
        if (!localCanEdit.value) return;
        videos.value.splice(index, 1);
    };

    const currentResponseStatus = computed(() => Number(obj.value.response_status || STATUS_DRAFT));
    const workflowBadge = computed(() => {
        if (currentResponseStatus.value === STATUS_APPROVED) {
            return { label: 'Approved', class: 'bg-success' };
        }

        if (currentResponseStatus.value === STATUS_NEED_REVISION) {
            return { label: 'Need Revision', class: 'bg-warning text-dark' };
        }

        if (currentResponseStatus.value === STATUS_SUBMITTED) {
            return { label: 'Submitted', class: 'bg-primary' };
        }

        return { label: 'Draft', class: 'bg-secondary' };
    });

    const isMatchCompleted = computed(() => Number(obj.value.status) === MATCH_STATUS_COMPLETED);
    const localCanEdit = computed(() => isLocalScout.value  && [STATUS_DRAFT, STATUS_NEED_REVISION].includes(currentResponseStatus.value));

    const latestRevisionRemark = computed(() => {
        const revisions = timeline.value.filter(item => item.type == 2);
        return revisions.length ? revisions[revisions.length - 1] : null;
    });

    const timelineItems = computed(() => timeline.value || []);

    watch(
        () => props.match_id,
            fetchStatus,
        {
            immediate: true
        }
    );

    const timeline = ref([]);

    async function fetchStatus() {
        if (!props.match_id || Number(props.match_id) === 0) {
            return;
        }

        loading.value = true;

        try {
            const data = await DBService.postData('/api/matches/fetch-status/' + props.match_id);
            if (data.success) {
                obj.value = data.calendar_event ?? {};
                obj.value.response_status = Number(obj.value.response_status || STATUS_DRAFT);
                obj.value.response_comment = '';
                obj.value.revision_comment = '';
                photos.value = data.photos?.length ? data.photos : [''];
                videos.value = data.videos?.length ? data.videos : [''];
                reports.value = data.reports?.length ? data.reports : [''];
                timeline.value = data.timeline || [];
            }
        } finally {
            loading.value = false;
        }
    }

    function submitStatus(action = 'submit') {
        showSuccess.value = false;
        if (isLocalScout.value) {
            if (action === 'submit' && !obj.value.response_comment) {
                bootbox.alert('Please enter response remarks.');
                return;
            } else{

                obj.value.event_id = props.match_id;
                obj.value.response_status = action === 'approve' ? STATUS_APPROVED : (action === 'revision' ? STATUS_NEED_REVISION : STATUS_SUBMITTED);

                obj.value.photos = photos.value.filter(item => item);
                obj.value.videos = videos.value.filter(item => item);
                obj.value.reports = reports.value.filter(item => item);

                bootbox.confirm('You can no longer change this after submission. Are you sure?',(result) => {
                    if (result) {
                        submitData();
                    }
                });
            }
            
        } else {
            obj.value.event_id = props.match_id;
            obj.value.response_status = action === 'approve' ? STATUS_APPROVED : (action === 'revision' ? STATUS_NEED_REVISION : STATUS_SUBMITTED);

            if (action === 'revision' && !obj.value.revision_comment) {
                bootbox.alert('Please enter revision remarks.');
                return;
            }

            submitData();
        }
    }

    const showSuccess = ref(false);

    function submitData(){
        submitting.value = true;
        DBService.postData('/api/matches/submit-change-status', obj.value).then((data) => {
            if (data.success) {
                obj.value.response_comment = '';
                obj.value.revision_comment = '';
                showSuccess.value = true;
                fetchStatus();
            } else {
                bootbox.alert(data.message || 'Unable to update status.');
            }
        }).finally(() => {
            submitting.value = false;
        });
    };

    const photosOpen = ref(false);
    const videosOpen = ref(false);
    const reportsOpen = ref(false);

    function formatDate(date) {
        if (!date) return '';

        return new Date(date).toLocaleString('en-IN', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    };

    function reportLink(rp_id) {
        if (!rp_id) return '#';

        const id = Number(String(rp_id).replace(/^PR0*/, ''));

        if (!id) return '#';

        return `${base_url}/player-report/info/${id}`;
    }
</script>
<template>
    <Loading :loading="loading" v-if="loading" />
    <div class="report-page">
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
                                Match Report
                            </h5>
                        </div>
                        <span class="status-pill" :class="workflowBadge.class">
                            {{ workflowBadge.label }}
                        </span>
                    </div>

                    <div class="report-card-body">
                        <div class="alert alert-info mb-4" v-if="isLocalScout && currentResponseStatus === STATUS_SUBMITTED">
                            Your report has been submitted successfully. Waiting for Scout Manager review.
                        </div>

                        <div class="alert alert-success mb-4" v-if="isLocalScout && currentResponseStatus === STATUS_APPROVED">
                            This report has been approved and is permanently locked.
                        </div>

                        <div class="alert alert-warning mb-4" v-if="isLocalScout && latestRevisionRemark && currentResponseStatus === STATUS_NEED_REVISION">
                            <div class="fw-bold mb-1">
                                Revision Remarks
                            </div>
                            <div>
                                {{ latestRevisionRemark.comment }}
                            </div>
                        </div>


                        <div class="row g-3">
                            <div class="col-12" v-if="privilege == 0">
                                <label class="form-label">
                                    Response
                                </label>
                                <InputText v-model="obj.response_comment" :disabled="!localCanEdit"/>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">
                                    Submission Date
                                </label>
                                <InputField type="date" v-model="obj.submit_date" :disabled="!localCanEdit" />
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">
                                    Response Status
                                </label>
                                <SelectField :options="response_status_list" v-model="obj.response_status" :disabled="!managerCanEdit" />
                            </div>

                            <div class="col-md-6" v-if="managerCanEdit" >
                                <label class="form-label">
                                    Match Status
                                </label>
                                <SelectField :options="status_list" v-model="obj.status" />
                            </div>

                            <div class="col-12" v-if="managerCanEdit" >
                                <label class="form-label">
                                    Revision Remarks
                                </label>
                                <InputText v-model="obj.revision_comment" />
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3 shadow-sm">
                    <div class="card-header d-flex justify-content-between align-items-center" @click="reportsOpen = !reportsOpen" style="cursor:pointer;">
                        <h6 class="mb-0 d-flex align-items-center">
                            <i class="bi bi-camera-video me-2"></i>
                            Player Reports

                            <span class="badge bg-primary rounded-pill ms-2">
                                {{ reports.filter(item => item).length }}
                            </span>
                        </h6>

                        <div class="d-flex align-items-center gap-2">
                            <button v-if="localCanEdit && reportsOpen && reports.length < 10" class="btn btn-outline-primary btn-sm me-3" @click.stop="reports.push('')">
                                <i class="bi bi-plus-lg me-1"></i>
                                Add
                            </button>

                            <i class="bi fs-5" :class="reportsOpen ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
                        </div>
                    </div>

                    <Transition name="collapse">
                        <div v-show="reportsOpen" class="card-body">
                            <div class="report-item" v-for="(report, index) in reports" :key="index">
                                <div class="report-item-header">
                                    <span class="report-title">
                                        <i class="bi bi-link-45deg me-1"></i>
                                        Report {{ index + 1 }}
                                    </span>

                                    <button v-if="localCanEdit && reports.length > 1" class="btn btn-outline-danger btn-sm" @click.stop="reports.splice(index,1)">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>

                                <InputField v-model="reports[index]" placeholder="Paste player report id..." :disabled="!localCanEdit"  v-if="localCanEdit" />

                                <a v-if="report" :href="reportLink(report)" target="_blank" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-box-arrow-up-right me-1"></i>
                                    {{ report }}
                                </a>
                            </div>
                        </div>
                    </Transition>
                </div>                

                
                <div class="card mb-3 shadow-sm">
                    <div class="card-header d-flex justify-content-between align-items-center" @click="photosOpen = !photosOpen" style="cursor:pointer;" >
                        <div>
                            <h6 class="mb-0 d-flex align-items-center">
                                <i class="bi bi-images me-2"></i>
                                Response Photos

                                <span class="badge bg-primary rounded-pill ms-2">
                                    {{ photos.filter(item => item).length }}
                                </span>
                            </h6>

                            <small class="text-muted">
                                Optional, maximum 5 photos
                            </small>
                        </div>

                        <div class="d-flex align-items-center">
                            <button class="btn btn-outline-primary btn-sm me-3" @click.stop="addPhoto" v-if="photosOpen && localCanEdit && photos.length < 5" >
                                <i class="bi bi-plus-lg me-1"></i>
                                Add
                            </button>

                            <i class="bi fs-5" :class="photosOpen ? 'bi-chevron-up' : 'bi-chevron-down'" ></i>
                        </div>
                    </div>

                    <Transition name="collapse">
                        <div v-show="photosOpen" class="card-body">
                            <div class="media-grid">
                                <div v-for="(photo,index) in photos" :key="index" class="media-tile" >

                                    <a v-if="photo" :href="s3_url_link + photo" target="_blank" class="media-preview photo-preview" >
                                        <img :src="s3_url_link + photo" alt="Match response photo" >
                                    </a>

                                    <div v-else class="empty-preview">
                                        <i class="bi bi-image"></i>
                                        <span>No photo uploaded</span>
                                    </div>

                                    <div class="upload-control" v-if="localCanEdit" >
                                        <PhotoUpload v-model="photos[index]"/>
                                    </div>

                                    <button class="remove-media-btn" type="button" @click.stop="removePhoto(index)" v-if="localCanEdit && photos.length > 1" >
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </Transition>
                </div>
                <div class="card mb-3 shadow-sm">
                    <div class="card-header d-flex justify-content-between align-items-center" @click="videosOpen = !videosOpen" style="cursor:pointer;" >
                        <div>
                            <h6 class="mb-0 d-flex align-items-center">
                                <i class="bi bi-camera-video me-2"></i>
                                Response Videos

                                <span class="badge bg-primary rounded-pill ms-2">
                                    {{ videos.filter(item => item).length }}
                                </span>
                            </h6>

                            <small class="text-muted">
                                Optional, maximum 2 videos
                            </small>
                        </div>

                        <div class="d-flex align-items-center">
                            <button class="btn btn-outline-primary btn-sm me-3" @click.stop="addVideo" v-if="videosOpen && localCanEdit && videos.length < 2">
                                <i class="bi bi-plus-lg me-1"></i>
                                Add
                            </button>

                            <i class="bi fs-5" :class="videosOpen ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
                        </div>
                    </div>

                    <Transition name="collapse">
                        <div v-show="videosOpen" class="card-body">
                            <div class="media-grid video-grid">
                                <div v-for="(video, index) in videos" :key="index" class="media-tile" >
                                    <div v-if="video" class="media-preview video-preview" >
                                        <video :src="s3_url_link + video" controls preload="metadata" controlsList="nodownload" disablepictureinpicture ></video>
                                    </div>

                                    <div v-else class="empty-preview">
                                        <i class="bi bi-play-circle"></i>
                                        <span>No video uploaded</span>
                                    </div>

                                    <div class="upload-control" v-if="localCanEdit">
                                        <VideoUpload v-model="videos[index]"/>
                                    </div>

                                    <button class="remove-media-btn" type="button" @click.stop="removeVideo(index)" v-if="localCanEdit && videos.length > 1" >
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </Transition>
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

                    <div class="timeline">
                        <div v-for="item in timelineItems" :key="item.id" class="timeline-item" >
                            <!-- Profile Picture -->
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

        <div class="modal-footer report-actions">
            <Button2 v-if="localCanEdit" :processing="submitting" cls="btn btn-success px-4" @click="submitStatus()">
                Submit
            </Button2>
            <Button2 v-if="managerCanEdit" :processing="submitting" cls="btn btn-outline-warning px-4" @click="submitStatus('revision')">
                Need Revision
            </Button2>
            <Button2 v-if="managerCanEdit" :processing="submitting" cls="btn btn-success px-4" @click="submitStatus('approve')">
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
    .media-card,
    .comments-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        box-shadow: 0 10px 30px rgba(15, 23, 42, 0.05);
    }

    .report-card-header,
    .media-card-header,
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
    .media-title,
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

    .alert {
        border: 0;
        border-radius: 10px;
        font-size: 14px;
    }

    .media-card-header {
        padding-bottom: 14px;
    }

    .media-grid {
        display: grid;
        gap: 14px;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        padding: 18px 20px 20px;
    }

    .video-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .media-tile {
        background: #f8fafc;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        min-width: 0;
        overflow: hidden;
        position: relative;
    }

    .media-preview,
    .empty-preview {
        align-items: center;
        aspect-ratio: 16 / 9;
        background: #e2e8f0;
        color: #64748b;
        display: flex;
        justify-content: center;
        overflow: hidden;
        text-decoration: none;
        width: 100%;
    }

    .media-preview img,
    .media-preview video {
        display: block;
        height: 100%;
        object-fit: cover;
        width: 100%;
    }

    .media-preview video {
        background: #0f172a;
    }

    .empty-preview {
        flex-direction: column;
        gap: 8px;
        font-size: 13px;
        font-weight: 600;
    }

    .empty-preview i {
        font-size: 24px;
    }

    .upload-control {
        background: #fff;
        border-top: 1px solid #e5e7eb;
        padding: 10px;
    }

    .remove-media-btn {
        align-items: center;
        background: rgba(255, 255, 255, 0.95);
        border: 1px solid #fecaca;
        border-radius: 8px;
        color: #dc2626;
        display: flex;
        height: 32px;
        justify-content: center;
        position: absolute;
        right: 8px;
        top: 8px;
        width: 32px;
    }

    .report-sidebar {
        min-width: 0;
    }

    .comments-card {
        display: flex;
        flex-direction: column;
        max-height: calc(100vh - 120px);
        position: sticky;
        top: 16px;
    }

    .timeline {
        flex: 1;
        min-height: 240px;
        overflow-y: auto;
        padding: 18px 18px 4px;
    }

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

    .timeline-marker {
        align-items: center;
        border: 3px solid #fff;
        border-radius: 50%;
        box-shadow: 0 8px 18px rgba(37, 99, 235, 0.18);
        color: #fff;
        display: flex;
        font-size: 12px;
        font-weight: 800;
        height: 38px;
        justify-content: center;
        width: 38px;
        z-index: 1;
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

    .comment-composer {
        background: #fff;
        border-top: 1px solid #eef2f7;
        padding: 16px 18px 18px;
    }

    .report-actions {
        background: #fff;
        border-top: 1px solid #e5e7eb;
        gap: 10px;
        margin: 16px -16px -16px;
        padding: 14px 16px;
    }

    .collapse-enter-active,
    .collapse-leave-active {
        transition: all 0.25s ease;
        overflow: hidden;
    }

    .collapse-enter-from,
    .collapse-leave-to {
        max-height: 0;
        opacity: 0;
    }

    .collapse-enter-to,
    .collapse-leave-from {
        max-height: 600px;
        opacity: 1;
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
        .media-card-header,
        .comments-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .media-grid,
        .video-grid {
            grid-template-columns: 1fr;
            padding: 14px;
        }

        .timeline-meta {
            flex-direction: column;
            gap: 4px;
        }

        .report-actions {
            margin: 14px -10px -10px;
        }
    }

    .report-item{
        padding:16px;
        margin-bottom:14px;
        border:1px solid #e5e7eb;
        border-radius:10px;
        background:#f8fafc;
        transition:.2s;
    }

    .report-item:last-child{
        margin-bottom:0;
    }

    .report-item:hover{
        border-color:#2563eb;
        box-shadow:0 3px 10px rgba(37,99,235,.08);
    }

    .report-item-header{
        display:flex;
        justify-content:space-between;
        align-items:center;
        margin-bottom:12px;
    }

    .report-title{
        font-size:14px;
        font-weight:600;
        color:#374151;
    }

    .collapse-enter-active,
    .collapse-leave-active{
        transition:all .25s ease;
    }

    .collapse-enter-from,
    .collapse-leave-to{
        opacity:0;
        max-height:0;
    }

    .collapse-enter-to,
    .collapse-leave-from{
        opacity:1;
        max-height:600px;
    }
</style>
