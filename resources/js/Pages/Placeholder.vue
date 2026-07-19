<template>
  <Layout :page="page" :title="title">
    <section class="bill-hero">
      <div>
        <span class="bill-eyebrow">Inertia Page</span>
        <h2>{{ title }}</h2>
        <p>This module is ready for a dedicated Laravel, Vue and Inertia implementation.</p>
      </div>
    </section>

    <section class="bill-grid bill-grid-4">
      <article v-for="item in cards" :key="item.label" class="bill-card">
        <span>{{ item.label }}</span>
        <strong>{{ item.value }}</strong>
        <small>{{ item.hint }}</small>
      </article>
    </section>

    <article class="bill-card">
      <div class="bill-card-head">
        <h3>Build Plan</h3>
      </div>
      <div class="bill-tasks">
        <span>Create route and controller method for this module.</span>
        <span>Load data from Laravel models instead of static mock data.</span>
        <span>Build form, table, filters and actions as Vue components.</span>
        <span>Connect create, update and posting actions to validated Laravel endpoints.</span>
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

const cards = [
  { label: 'Status', value: 'Ready', hint: 'Vue page shell is active' },
  { label: 'Routing', value: 'Inertia', hint: 'Served by Laravel controller' },
  { label: 'Data', value: 'Pending', hint: 'Model integration next' },
  { label: 'Actions', value: 'Planned', hint: 'CRUD workflow to be added' },
];
</script>
