<template>
  <Layout :page="page" :title="title">
    <template #topbar-title>
      <div class="bill-page-title">
        <span>BillIQ</span>
        <h1>{{ title }}</h1>
        <p>The requested page is not connected to an active sidebar route.</p>
      </div>
    </template>

    <article class="bill-card">
      <div class="bill-card-head">
        <h3>Page unavailable</h3>
      </div>
      <div class="bill-tasks">
        <span>Use the sidebar to open an implemented module.</span>
      </div>
    </article>
  </Layout>
</template>

<script setup>
import Layout from './Layout.vue';

defineProps({
  page: { type: String, required: true },
  title: { type: String, required: true },
});

</script>
