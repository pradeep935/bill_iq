<script setup>
    import { ref } from "vue";
    import axios from "axios";

    const props = defineProps({
        label: String,
        cls: {
            type: String,
            default: ''
        }
    });

    const model = defineModel();
    const s3_url_link = import.meta.env.VITE_S3_URL;
    const uploading = ref(false);

    // const onFileChanged = async (event) => {
    //     const file = event.target.files[0];
    //     if (!file) return;
    //     const formData = new FormData();
    //     formData.append("file", file);
    //     formData.append("temp", 0);
    //     uploading.value = true;
    //     try {
    //         const response = await axios.post(
    //             base_url + "/uploads-new/file",
    //             formData,
    //             {
    //                 headers: {
    //                     "Content-Type": "multipart/form-data"
    //                 }
    //             }
    //         );

    //         if (response.data.success) {
    //             model.value = response.data.path;
    //         }

    //     } finally {
    //         uploading.value = false;
    //     }
    // }


    const onFileChanged = async (event) => {
        const file = event.target.files[0];
        if (!file) return;
        const maxSize = 100 * 1024 * 1024; // 100 MB
        if (file.size > maxSize) {
            bootbox.alert("Maximum video size is 100 MB.");
            return;
        }

        const formData = new FormData();
        formData.append("file", file);
        formData.append("temp", 0);
        uploading.value = true;
        try {
            const response = await axios.post(
                base_url + "/uploads-new/file",
                formData,
                {
                    headers: {
                        "Content-Type": "multipart/form-data"
                    }
                }
            );

            if (response.data.success) {
                model.value = response.data.path;
            }

        } finally {
            uploading.value = false;
        }
    };
</script>

<template>
    <div :class="`form-group ${cls}`">
        <label v-if="label">{{ label }}</label>
        <div v-if="uploading">
            Uploading...
        </div>
        <div v-else>
            <template v-if="model">
                <a :href="s3_url_link + model" target="_blank" class="btn btn-success btn-sm me-2">
                    View
                </a>

                <button class="btn btn-danger btn-sm" type="button" @click="model=''">
                    Remove
                </button>
            </template>
            <input v-else type="file" class="form-control" accept="video/*" @change="onFileChanged">
        </div>
    </div>
</template>