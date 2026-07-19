<script setup>
    import { computed, ref, onMounted, watch } from 'vue';
    import DBService from '@/Service/Utils/DBService.js';
    import PhotoUpload from '@/Components/Common/PhotoUpload.vue';
    import VideoUpload from '@/Components/Common/VideoUpload.vue';

    const props = defineProps(['match_id','privilege']);
    const emit = defineEmits(['closePopup']);
    const s3_url_link = import.meta.env.VITE_S3_URL;
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

    watch(
        () => props.match_id,
            fetchStatus,
        {
            immediate: true
        }
    );

    async function fetchStatus() {
        loading.value = true;

        if (!props.match_id || Number(props.match_id) === 0) {
            return;
        }
        try {
            const data = await DBService.postData('/api/matches/fetch-status/' + props.match_id);
            if (data.success) {
                obj.value = data.calendar_event ?? {};
                obj.value.response_status = Number(obj.value.response_status || STATUS_DRAFT);
                photos.value = data.photos?.length ? data.photos : [''];
                videos.value = data.videos?.length ? data.videos : [''];
            }
        } finally {
            loading.value = false;
        }
    }

    function submitStatus(action = 'submit') {
        obj.value.event_id = props.match_id;
        obj.value.response_status = action === 'approve' ? STATUS_APPROVED : (action === 'revision' ? STATUS_NEED_REVISION : STATUS_SUBMITTED);

        if (isLocalScout.value) {
            obj.value.photos = photos.value.filter(item => item);
            obj.value.videos = videos.value.filter(item => item);
        }

        if (isLocalScout.value) {
            bootbox.confirm(
                'You can no longer change this after submission. Are you sure?',
                (result) => {
                    if (result) {
                        submitData();
                    }
                }
            );
        } else {
            if (action === 'revision' && !obj.value.revision_remarks) {
                bootbox.alert('Please enter revision remarks.');
                return;
            }

            submitData();
        }
    }

    function submitData(){
        submitting.value = true;
        DBService.postData('/api/matches/submit-change-status', obj.value).then((data) => {
            if (data.success) {
                obj.value = {};
                photos.value = [''];
                videos.value = [''];
                emit('closePopup');
            } else {
                bootbox.alert(data.message || 'Unable to update status.');
            }
        }).finally(() => {
            submitting.value = false;
        });
    };

</script>
<template>
    <Loading :loading="loading" v-if="loading" />
    <div class="row g-3">
        <div class="col-md-12">
            <span class="badge" :class="workflowBadge.class">
                {{ workflowBadge.label }}
            </span>
        </div>

        <div class="col-md-12" v-if="isLocalScout && currentResponseStatus === STATUS_SUBMITTED">
            <div class="alert alert-info mb-0">
                Your report has been submitted successfully. Waiting for Scout Manager review.
            </div>
        </div>

        <div class="col-md-12" v-if="isLocalScout && currentResponseStatus === STATUS_APPROVED">
            <div class="alert alert-success mb-0">
                This report has been approved and is permanently locked.
            </div>
        </div>

        <div class="col-md-12" v-if="isLocalScout && obj.revision_remarks && currentResponseStatus === STATUS_NEED_REVISION" >
            <div class="alert alert-warning mb-0">
                <div class="fw-bold mb-1">
                    Revision Remarks
                </div>
                <div>
                    {{ obj.revision_remarks }}
                </div>
            </div>
        </div>

        <InputText v-model="obj.ans_text" label="Response" cls="col-md-12" :disabled="!localCanEdit" />

        <InputField type="date" v-model="obj.submit_date" label="Submission Date" cls="col-md-6" :disabled="!localCanEdit" />

        <SelectField :disabled="!managerCanEdit" :options="response_status_list" v-model="obj.response_status" label="Response Status" cls="col-md-6" />

        <InputText v-model="obj.revision_remarks" label="Revision Remarks" v-if="obj.revision_remarks || managerCanEdit" :disabled="!managerCanEdit" cls="col-md-12" />

        <SelectField v-if="managerCanEdit" :options="status_list" v-model="obj.status" label="Match Status" cls="col-md-6" />

        <!-- Photos -->
        <div class="col-md-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h6 class="mb-0 fw-bold">
                                <i class="bi bi-images me-2"></i>
                                Response Photos
                            </h6>
                            <small class="text-muted">
                                Optional • Maximum 5 Photos
                            </small>
                        </div>

                        <button class="btn btn-outline-primary btn-sm" @click="addPhoto" v-if="localCanEdit && photos.length < 5">
                            <i class="bi bi-plus"></i>
                            Add Photo
                        </button>
                    </div>

                    <div v-for="(photo,index) in photos" :key="index" class="row align-items-center mb-2">
                        <div class="col" v-if="localCanEdit">
                            <PhotoUpload v-model="photos[index]" />
                        </div>

                        <div class="col" v-else-if="photo">
                            <a :href="s3_url_link + photo" target="_blank" class="btn btn-success btn-sm">
                                View
                            </a>
                        </div>

                        <div class="col" v-else>
                            <span class="text-muted">No photo uploaded.</span>
                        </div>

                        <div class="col-auto" v-if="localCanEdit">
                            <button class="btn btn-outline-danger btn-sm" @click="removePhoto(index)" v-if="photos.length > 1">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Videos -->
        <div class="col-md-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h6 class="mb-0 fw-bold">
                                <i class="bi bi-camera-video me-2"></i>
                                Response Videos
                            </h6>

                            <small class="text-muted">
                                Optional • Maximum 2 Videos
                            </small>
                        </div>
                        <button class="btn btn-outline-primary btn-sm" @click="addVideo" v-if="localCanEdit && videos.length < 2">
                            <i class="bi bi-plus"></i>
                            Add Video
                        </button>
                    </div>
                    <div v-for="(video,index) in videos" :key="index" class="row align-items-center mb-2">
                        <div class="col" v-if="localCanEdit">
                            <VideoUpload v-model="videos[index]" />
                        </div>

                        <div class="col" v-else-if="video">
                            <a :href="s3_url_link + video" target="_blank" class="btn btn-success btn-sm">
                                View
                            </a>
                        </div>

                        <div class="col" v-else>
                            <span class="text-muted">No video uploaded.</span>
                        </div>

                        <div class="col-auto" v-if="localCanEdit">
                            <button class="btn btn-outline-danger btn-sm" @click="removeVideo(index)" v-if="videos.length > 1">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="modal-footer mt-4">
        <Button2 v-if="localCanEdit" :processing="submitting" cls="btn btn-success px-4" @click="submitStatus()">
            Submit
        </Button2>
        <Button2 v-if="managerCanEdit"  :processing="submitting" cls="btn btn-outline-warning px-4" @click="submitStatus('revision')">
            Need Revision
        </Button2>
        <Button2 v-if="managerCanEdit"  :processing="submitting" cls="btn btn-success px-4" @click="submitStatus('approve')">
            Approve
        </Button2>
    </div>
</template>
