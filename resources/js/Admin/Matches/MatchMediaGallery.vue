<script setup>
    import { computed, ref, watch } from 'vue';
    import DBService from '@/Service/Utils/DBService.js';

    const props = defineProps({
        match_id: {
            type: [Number, String],
            default: null
        },
        show: {
            type: Boolean,
            default: false
        }
    });

    const emit = defineEmits(['close']);
    const s3_url_link = import.meta.env.VITE_S3_URL;
    const loading = ref(false);
    const photos = ref([]);
    const videos = ref([]);

    const attachmentCount = computed(() => photos.value.length + videos.value.length);
    const hasAttachments = computed(() => attachmentCount.value > 0);

    watch(
        () => [props.show, props.match_id],
        ([show, matchId]) => {
            if (show && matchId) {
                fetchMedia();
            }
        },
        { immediate: true }
    );

    async function fetchMedia() {
        loading.value = true;
        photos.value = [];
        videos.value = [];

        try {
            const data = await DBService.getData('/api/matches/fetch-media/' + props.match_id);

            if (data.success) {
                photos.value = data.photos || [];
                videos.value = data.videos || [];
            } else {
                bootbox.alert(data.message || 'Unable to fetch attachments.');
            }
        } finally {
            loading.value = false;
        }
    }

    function closeModal() {
        emit('close');
    }
</script>

<template>
    <div v-if="show" class="modal fade show d-block match-gallery-modal" tabindex="-1" role="dialog" aria-modal="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title">Match Attachments</h5>
                        <small class="text-muted">{{ attachmentCount }} attachment{{ attachmentCount === 1 ? '' : 's' }}</small>
                    </div>
                    <button type="button" class="btn-close" aria-label="Close" @click="closeModal"></button>
                </div>

                <div class="modal-body">
                    <div v-if="loading" class="gallery-loading">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>

                    <div v-else-if="!hasAttachments" class="gallery-empty">
                        <div class="gallery-empty__icon">
                            <i class="bi bi-images"></i>
                        </div>
                        <h6>No attachments found.</h6>
                    </div>

                    <template v-else>
                        <section class="gallery-section">
                            <div class="gallery-section__header">
                                <h6>Photos</h6>
                                <span class="badge bg-light text-dark border">{{ photos.length }}</span>
                            </div>

                            <div v-if="photos.length" class="row g-3">
                                <div v-for="(photo, index) in photos" :key="`photo-${index}`" class="col-12 col-sm-6 col-lg-4">
                                    <a :href="s3_url_link + photo" target="_blank" class="photo-card">
                                        <img :src="s3_url_link + photo" alt="Match attachment photo" loading="lazy">
                                    </a>
                                </div>
                            </div>

                            <div v-else class="text-muted small">
                                No photos uploaded.
                            </div>
                        </section>

                        <section class="gallery-section mt-4">
                            <div class="gallery-section__header">
                                <h6>Videos</h6>
                                <span class="badge bg-light text-dark border">{{ videos.length }}</span>
                            </div>

                            <div v-if="videos.length" class="row g-3">
                                <div v-for="(video, index) in videos" :key="`video-${index}`" class="col-12 col-lg-6">
                                    <div class="video-card">
                                        <video :src="s3_url_link + video" controls controlsList="nodownload" disablepictureinpicture preload="metadata"></video>
                                    </div>
                                </div>
                            </div>

                            <div v-else class="text-muted small">
                                No videos uploaded.
                            </div>
                        </section>
                    </template>
                </div>
            </div>
        </div>
        <div class="modal-backdrop fade show" @click="closeModal"></div>
    </div>
</template>

<style scoped>
    .match-gallery-modal {
        background: rgba(15, 23, 42, 0.42);
    }

    .match-gallery-modal .modal-dialog {
        z-index: 1060;
    }

    .match-gallery-modal .modal-backdrop {
        z-index: 1050;
    }

    .gallery-loading,
    .gallery-empty {
        min-height: 260px;
        display: grid;
        place-items: center;
        text-align: center;
    }

    .gallery-empty {
        align-content: center;
        gap: 12px;
        color: #6b7280;
    }

    .gallery-empty__icon {
        width: 72px;
        height: 72px;
        border-radius: 50%;
        display: grid;
        place-items: center;
        background: #f1f5f9;
        color: #2563eb;
        font-size: 30px;
        margin: 0 auto;
    }

    .gallery-section__header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 12px;
    }

    .gallery-section__header h6 {
        margin: 0;
        font-weight: 700;
        color: #111827;
    }

    .photo-card,
    .video-card {
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        overflow: hidden;
        background: #fff;
        box-shadow: 0 8px 20px rgba(15, 23, 42, 0.06);
    }

    .photo-card {
        display: block;
        aspect-ratio: 4 / 3;
    }

    .photo-card img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.2s ease, filter 0.2s ease;
    }

    .photo-card:hover img {
        transform: scale(1.04);
        filter: brightness(0.95);
    }

    .video-card {
        padding: 10px;
        background: #f8fafc;
    }

    .video-card video {
        width: 100%;
        max-height: 360px;
        display: block;
        border-radius: 6px;
        background: #0f172a;
    }
</style>
