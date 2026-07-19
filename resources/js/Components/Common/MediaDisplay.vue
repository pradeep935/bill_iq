<script setup>
    import { computed, ref, watch } from 'vue';
    import DBService from '@/Service/Utils/DBService.js';

    // const props = defineProps({
    //     match_id: {
    //         type: [Number, String],
    //         default: null
    //     },
    //     show: {
    //         type: Boolean,
    //         default: false
    //     }
    // });

    const { photos = [], videos = [], pic_class = 'col-12 col-sm-6 col-lg-4', vid_class = 'col-12 col-lg-6' } = defineProps(['photos', 'videos', 'pic_class', 'vid_class'])

    const s3_url_link = import.meta.env.VITE_S3_URL;
    const loading = ref(false);
</script>

<template>
    <div>
        <div v-if="loading" class="gallery-loading">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>

        <div v-else-if="(!photos || !photos.length || (photos.length == 1 && photos[0] == '')) && (!videos || !videos.length || (videos.length == 1 && videos[0]== ''))" class="gallery-empty">
            <div class="gallery-empty__icon">
                <i class="bi bi-images"></i>
            </div>
            <h6>No attachments found.</h6>
        </div>

        <template v-else>
            <section class="gallery-section">
                <div class="gallery-section__header">
                    <h6>Photos</h6>
                    <span class="badge bg-light text-dark border">{{ photos.length == 1 ? (photos[0] == '' ? 0 : 1) : photos.length }}</span>
                </div>

                <div v-if="photos.length > 1 || (photos.length == 1 && photos[0] != '')" class="row g-3">
                    <div v-for="(photo, index) in photos" :key="`photo-${index}`" :class="pic_class">
                        <a :href="s3_url_link + photo" target="_blank" class="photo-card">
                            <img :src="s3_url_link + photo" alt="Match attachment photo" loading="lazy">
                        </a>
                    </div>
                </div>

                <div v-else class="text-muted small">
                    No photo uploaded.
                </div>
            </section>

            <section class="gallery-section mt-4">
                <div class="gallery-section__header">
                    <h6>Videos</h6>
                    <span class="badge bg-light text-dark border">{{ videos.length == 1 ? (videos[0] == '' ? 0 : 1) : videos.length }}</span>
                </div>

                <div v-if="videos.length > 1 || (videos.length == 1 && videos[0] != '')" class="row g-3">
                    <div v-for="(video, index) in videos" :key="`video-${index}`" :class="vid_class">
                        <div class="video-card">
                            <video :src="s3_url_link + video" controls controlsList="nodownload" disablepictureinpicture preload="metadata"></video>
                        </div>
                    </div>
                </div>

                <div v-else class="text-muted small">
                    No video uploaded.
                </div>
            </section>
        </template>
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
