<!-- <script setup>
const props = defineProps({
  icon: {
    type: String,
    required: true,
  },
  iconColorClass: {
    type: String,
    required: true,
  },
  value: {
    type: [String, Number],
    default: 0,
  },
  label: {
    type: String,
    required: true,
  },
  up: {
    type: Number,
    default: 0,
  },
  down: {
    type: Number,
    default: 0,
  },
  upMsg: {
    type: String,
    default: 'up this month',
  },
  downMsg: {
    type: String,
    default: 'down this month',
  },
  msg: {
    type: String,
    default: '',
  },
});

function showUp() {
  return Number(props.up) > 0;
}

function showDown() {
  return Number(props.down) > 0;
}

function showMsg() {
  return !showUp() && !showDown() && props.msg !== '';
}
</script>

<template>
  <div class="stat-card">
    <div class="stat-icon" :class="iconColorClass">
      <i class="bi" :class="icon"></i>
    </div>
    <div>
      <div class="stat-value">{{ value }}</div>
      <div class="stat-label">{{ label }}</div>
      <div v-if="showUp() || showDown()" class="d-flex flex-wrap align-items-center gap-3">
        <div v-if="showUp()" class="stat-change up">
          <i class="bi bi-arrow-up-short"></i>
          {{ up }} {{ upMsg }}
        </div>
        <div v-if="showDown()" class="stat-change down">
          <i class="bi bi-arrow-down-short"></i>
          {{ down }} {{ downMsg }}
        </div>
      </div>
      <div v-if="showMsg()" class="stat-change">
        {{ msg }}
      </div>
    </div>
  </div>
</template>
 -->
 <script setup>
const props = defineProps({
  icon: {
    type: String,
    required: true,
  },
  iconColorClass: {
    type: String,
    required: true,
  },
  value: {
    type: [String, Number],
    default: 0,
  },
  label: {
    type: String,
    required: true,
  },

  // NEW
  male: {
    type: Number,
    default: 0,
  },

  female: {
    type: Number,
    default: 0,
  },

  up: {
    type: Number,
    default: 0,
  },
  down: {
    type: Number,
    default: 0,
  },
  upMsg: {
    type: String,
    default: 'up this month',
  },
  downMsg: {
    type: String,
    default: 'down this month',
  },
  msg: {
    type: String,
    default: '',
  },
});

function showUp() {
  return Number(props.up) > 0;
}

function showDown() {
  return Number(props.down) > 0;
}

function showMsg() {
  return !showUp() && !showDown() && props.msg !== '';
}

function showGenderStats() {
  return props.male > 0 || props.female > 0;
}
</script>

<template>
  <div class="stat-card">
    <div class="stat-icon" :class="iconColorClass">
      <i class="bi" :class="icon"></i>
    </div>

    <div>
      <div class="stat-value">{{ value }}</div>

      <div class="stat-label">{{ label }}</div>

      <!-- Gender Stats -->
      <div v-if="showGenderStats()" class="gender-stats" >
        <div class="gender-item male">
          <i class="bi bi-gender-male"></i>
          <span>{{ male }} Male</span>
        </div>

        <div class="gender-item female">
          <i class="bi bi-gender-female"></i>
          <span>{{ female }} Female</span>
        </div>
    </div>

      <div
        v-if="showUp() || showDown()"
        class="d-flex flex-wrap align-items-center gap-3 mt-2"
      >
        <div v-if="showUp()" class="stat-change up">
          <i class="bi bi-arrow-up-short"></i>
          {{ up }} {{ upMsg }}
        </div>

        <div v-if="showDown()" class="stat-change down">
          <i class="bi bi-arrow-down-short"></i>
          {{ down }} {{ downMsg }}
        </div>
      </div>

      <div v-if="showMsg()" class="stat-change">
        {{ msg }}
      </div>
    </div>
  </div>
</template>

<style scoped>
.gender-stats {
  display: flex;
  align-items: center;
  gap: 14px;
  margin-top: 6px;
}

.gender-item {
  display: flex;
  align-items: center;
  gap: 5px;
  font-size: 13px;
  font-weight: 600;
  color: #6b7280;
  background: #f8fafc;
  padding: 3px 8px;
  border-radius: 20px;
}

.gender-item i {
  font-size: 14px;
}

.gender-item.male i {
  color: #2563eb;
}

.gender-item.female i {
  color: #ec4899;
}
</style>