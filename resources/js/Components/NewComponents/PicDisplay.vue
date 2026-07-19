<script setup>
    const {img_src, change_btn, title='Image', height='200px', width='200px'} = defineProps(['img_src','change_btn','title','height','width']);

    const emit = defineEmits(['callbackbtn']);

    function changeBtn(){
        emit('callbackbtn');
    }

    function editStudentProfileModal(){
    $('#change-profile-pic').modal('show');
  }
</script>

<template>
    <div class="avatar-upload" title="Upload photo" data-bs-toggle="modal" data-bs-target="change-profile-pic">
        <i class="bi bi-camera"></i>
        <span>Photo</span>
    </div>
    <div>
        <div class="pic-box" :style="'height :' + height + '; width :' + width">
            <img class="round" :src="img_src" v-if="img_src" style="border: 2px solid #404040;" :style="'height :' + height + '; width :' + width">
            <a href="#" v-if="change_btn" class="change-pic" @click.prevent="changeBtn()" style="background-color: #99ddff" :style="'width :' + width">Change</a>
        </div>
    </div>

    <Modal title="Change Picture" id="change-profile-pic">
    <input ref="fileInput" type="file" accept="image/*" @change="onFileChange" />

    <div v-if="imageUrl" class="cropper-wrapper mt-3" style="margin: 0 auto; max-width: 100%;">
      <cropper-canvas background>
        <cropper-image :src="imageUrl" alt="Picture" translatable scalable></cropper-image>

        <!-- IMPORTANT: without initial-coverage selection is 0x0 (invisible) :contentReference[oaicite:4]{index=4} -->
        <cropper-selection
          ref="cropperSelection"
          initial-coverage="0.4"
          movable
          resizable
          outlined
        >
          <cropper-grid covered></cropper-grid>
          <cropper-handle action="move" plain></cropper-handle>
          <cropper-handle action="n-resize"></cropper-handle>
          <cropper-handle action="e-resize"></cropper-handle>
          <cropper-handle action="s-resize"></cropper-handle>
          <cropper-handle action="w-resize"></cropper-handle>
          <cropper-handle action="ne-resize"></cropper-handle>
          <cropper-handle action="nw-resize"></cropper-handle>
          <cropper-handle action="se-resize"></cropper-handle>
          <cropper-handle action="sw-resize"></cropper-handle>
        </cropper-selection>
      </cropper-canvas>
    </div>

    <div class="text-right" v-if="imageUrl" style="margin-top:12px;">
      <Button2 cls="btn-primary" @clickFn="upload" :processing="processing">Upload</Button2>
      <button class="btn btn-light" @click="resetCropperState" style="margin-left:8px;">Discard</button>
    </div>

  </Modal>
</template>

<style scoped>
    .pic-box {
        position: relative;
        overflow: hidden;
        border-radius: 50%;
        margin: 0 auto;
        
    }
    .pic-box a.change-pic {
        position: absolute;
        bottom: 4px;
        color: black;
        opacity: 0.7;
        font-size: 12px;
        line-height: 1;
        left: 50%;
        margin-left: -100px;
        text-align: center;
        padding: 5px 0;
    }
    img.round{
        border-radius:50%;
    }
</style>