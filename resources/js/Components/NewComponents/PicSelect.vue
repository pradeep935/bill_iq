<script setup>
    import { ref } from 'vue'
    import DBService from '@/Service/Utils/DBService.js'
    import PicDisplay from '@/Components/NewComponents/PicDisplay.vue';

    const emit = defineEmits(['callbackbtn']);
    const {submit_link} = defineProps(['submit_link']);

    const currentCursor = ref('crosshair')
    const unclicked = ref(false);

    const canvasWidth = 500
    const canvasHeight = 400

    let cropSize = 100
    const minCropSize = 50
    const maxCropSize = 300

    const imageSrc = ref(null)
    const canvas = ref(null)
    const croppedImage = ref(null)

    let ctx = null
    let img = new Image()

    let cropX = 150
    let cropY = 100
    let isResizing = false
    let boxMoving = false
    let boxPlaced = ref(false)

    const loadImage = (event) => {
    const file = event.target.files[0]
    if (!file) return

    const reader = new FileReader()
        reader.onload = () => {
            imageSrc.value = reader.result
            img.onload = () => {
            drawCanvas()
            }
            img.src = reader.result
        }
        reader.readAsDataURL(file)
    }

    const drawCanvas = () => {
        if (!canvas.value) return
        ctx = canvas.value.getContext('2d')
        ctx.clearRect(0, 0, canvasWidth, canvasHeight)
        ctx.drawImage(img, 0, 0, canvasWidth, canvasHeight)

        if (boxPlaced.value || boxMoving || isResizing) {
            // Draw crop box
            ctx.strokeStyle = 'red'
            ctx.lineWidth = 2
            ctx.strokeRect(cropX, cropY, cropSize, cropSize)

            // Draw resize handle
            ctx.fillStyle = 'red'
            ctx.fillRect(cropX + cropSize - 6, cropY + cropSize - 6, 12, 12)
        }
    }

    const handleMouseDown = (e) => {
        const rect = canvas.value.getBoundingClientRect()
        const mouseX = e.clientX - rect.left
        const mouseY = e.clientY - rect.top

        const edgePadding = 10
        const withinResizeHandle =
            mouseX >= cropX + cropSize - edgePadding &&
            mouseX <= cropX + cropSize + edgePadding &&
            mouseY >= cropY + cropSize - edgePadding &&
            mouseY <= cropY + cropSize + edgePadding

        if (withinResizeHandle) {
            isResizing = true
            boxMoving = false
        } else {
            // Toggle move mode on click
            boxMoving = !boxMoving
            boxPlaced.value = !boxMoving // when moving ends, we "place"
        }

        drawCanvas()
    }

    const handleMouseMove = (e) => {
        if(unclicked.value == false){
            unclicked.value = true;
            handleMouseDown(e);
        }
        const rect = canvas.value.getBoundingClientRect()
        const mouseX = e.clientX - rect.left
        const mouseY = e.clientY - rect.top

        const edgePadding = 10
        const hoveringResizeHandle =
            mouseX >= cropX + cropSize - edgePadding &&
            mouseX <= cropX + cropSize + edgePadding &&
            mouseY >= cropY + cropSize - edgePadding &&
            mouseY <= cropY + cropSize + edgePadding

        if (isResizing) {
            const newSize = Math.max(
            minCropSize,
            Math.min(
                maxCropSize,
                Math.min(mouseX - cropX, mouseY - cropY)
            )
            )
            cropSize = newSize
            constrainCropBox()
            drawCanvas()
        } else if (boxMoving) {
            cropX = mouseX - cropSize / 2
            cropY = mouseY - cropSize / 2
            constrainCropBox()
            drawCanvas()
        }

        // Update cursor style
        if (isResizing) {
            currentCursor.value = 'nwse-resize'
        } else if (hoveringResizeHandle) {
            currentCursor.value = 'nwse-resize'
        } else if (boxMoving) {
            currentCursor.value = 'move'
        } else {
            currentCursor.value = 'crosshair'
        }

        cropImage();
    }


    const handleMouseUp = () => {
        isResizing = false
    }

    const constrainCropBox = () => {
        cropX = Math.max(0, Math.min(cropX, canvasWidth - cropSize))
        cropY = Math.max(0, Math.min(cropY, canvasHeight - cropSize))
    }

    const cropImage = () => {
        const cropCanvas = document.createElement('canvas')
        const cropCtx = cropCanvas.getContext('2d')

        cropCanvas.width = cropSize
        cropCanvas.height = cropSize

        const scaleX = img.width / canvasWidth
        const scaleY = img.height / canvasHeight

        cropCtx.drawImage(
            img,
            cropX * scaleX, cropY * scaleY,
            cropSize * scaleX, cropSize * scaleY,
            0, 0, cropSize, cropSize
        )

        croppedImage.value = cropCanvas.toDataURL('image/png')
    }

    function submitImgFinal(){
        if(!submit_link){
            alert('No Link Found')
            return;
        }

        DBService.postData('/api/'+submit_link,croppedImage.value) .then( (data) => {
            if(data.success){
                alert(data.message);
                emit('callbackbtn',1);
            } else{
                alert(data.message);
            }
        });
    }

    function goBackToPrev(){
        emit('callbackbtn',0);
    }

</script>

<template>
  <div class="cropper-container">
    <div class="row">
        <div class="col-md-6 mt-4">
            <input type="file" accept="image/*" @change="loadImage" />
        </div>
        <div class="col-md-6 text-end">
            <button class="btn btn-dark" @click.prevent="goBackToPrev()">Go Back</button>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12 text-center">
            <div v-if="imageSrc" class="canvas-wrapper">
              <canvas
                ref="canvas"
                :width="canvasWidth"
                :height="canvasHeight"
                :style="{ cursor: currentCursor }"
                @mousedown="handleMouseDown"
                @mousemove="handleMouseMove"
                @mouseup="handleMouseUp"
                @mouseleave="handleMouseUp"
              ></canvas>
            </div>
        </div>
        <!-- <div class="col-md-12 text-center mt-3" v-if="imageSrc">
            <button style="width : 40%" class="btn btn-primary" @click.prevent="cropImage" :disabled="!boxPlaced">Crop</button>
        </div> -->
    </div>


    <div v-if="croppedImage" class="mt-4 text-center">
      <PicDisplay :img_src="croppedImage" :change_btn="false" title="Displayed Image"></PicDisplay>
      <button @click.prevent="submitImgFinal()" style="width: 40%;" class="btn btn-dark mt-4">Submit Image</button>
      <slot></slot>
    </div>
  </div>
</template>

<style scoped>
    .cropper-container {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    max-width: 100%;
    }

    .canvas-wrapper {
    position: relative;
    max-width: 100%;
    }

    canvas {
    border: 1px solid #ccc;
    cursor: crosshair;
    }

    .pic-box {
        position: relative;
        overflow: hidden;
        width: 200px;
        height: 200px;
        border-radius: 50%;
        margin: 0 auto;
        
    }
    .pic-box a.change-pic {
        position: absolute;
        width: 200px;
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
        width:200px;
        height: 200px;
    }
</style>
