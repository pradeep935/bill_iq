<script setup>
    import { ref, onMounted } from 'vue';
    const { title = '', id, width = 50 } = defineProps(['title', 'id', 'width'])

    const offcanvasInstance = ref(null)

    onMounted(() => {
        const el = document.getElementById(id)
        offcanvasInstance.value = new bootstrap.Offcanvas(el)
    })

    // 👇 expose method (IMPORTANT)
    function close() {
        offcanvasInstance.value?.hide()
    }

    defineExpose({
        close
    })
</script>
<template>
    <div class="offcanvas offcanvas-end modal-right" :id="id" tabindex="-1">
        <div class="offcanvas-header modal-right__header">
            <div class="modal-right__header-left">
                <div class="modal-right__icon-wrap">
                    <i class="bi bi-person-plus"></i>
                </div>
                <div>
                    <div class="modal-right__title">{{ title }}</div>
                    <div class="modal-right__subtitle">Fill in the details below</div>
                </div>
            </div>
            <button type="button" class="modal-right__close-btn" data-bs-dismiss="offcanvas">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <slot></slot>
    </div>
</template>

<style lang="scss">
.modal-right {
  width: 600px !important;
}

.modal-right__header {
  background: var(--primary);
  padding: 0 20px;
  height: 64px;
  position: relative;
  flex-shrink: 0;
  justify-content: space-between;
}

.modal-right__header-left {
  display: flex;
  align-items: center;
  gap: 12px;
}

.modal-right__icon-wrap {
  width: 36px;
  height: 36px;
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.15);
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-size: 18px;
}

.modal-right__title {
  font-size: 15px;
  font-weight: 800;
  color: #fff;
  letter-spacing: -0.2px;
  line-height: 1.2;
}

.modal-right__subtitle {
  font-size: 11px;
  color: rgba(255, 255, 255, 0.65);
  font-weight: 500;
  margin-top: 1px;
}

.modal-right__close-btn {
  background: rgba(255, 255, 255, 0.15);
  border: none;
  width: 32px;
  height: 32px;
  border-radius: 7px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-size: 16px;
  cursor: pointer;
  transition: background 0.15s;
}

.modal-right__close-btn:hover {
  background: rgba(255, 255, 255, 0.25);
}

.modal-right__body {
  padding: 16px 20px;
  overflow-y: auto;
}
.oc-footer {
  padding: 14px 20px;
  border-top: 1px solid var(--border);
  display: flex;
  gap: 10px;
  justify-content: flex-end;
  background: #fff;
} 
</style>
