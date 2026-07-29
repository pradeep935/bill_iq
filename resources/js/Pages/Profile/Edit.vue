<template>
  <Layout page="profile" title="Profile">
    <template #topbar-title>
      <div class="bill-page-title">
        <span>Profile</span>
        <h1>{{ user.name }}</h1>
        <p>Account details for the signed-in Bill IQ user.</p>
      </div>
    </template>

    <section class="bill-grid bill-grid-2">
      <article class="bill-card">
        <div class="bill-card-head">
          <h3>Account</h3>
        </div>
        <div class="bill-profile-list">
          <div>
            <span>Name</span>
            <strong>{{ user.name || 'Not set' }}</strong>
          </div>
          <div>
            <span>Email</span>
            <strong>{{ user.email || 'Not set' }}</strong>
          </div>
          <div>
            <span>Role</span>
            <strong>{{ roleLabel }}</strong>
          </div>
          <div>
            <span>Status</span>
            <strong>{{ user.is_active ? 'Active' : 'Inactive' }}</strong>
          </div>
        </div>
      </article>
    </section>
  </Layout>
</template>

<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Layout from '../Layout.vue';

const page = usePage();
const user = computed(() => page.props.auth?.user || {});
const roleLabel = computed(() => {
  const roleId = Number(user.value.role_id || 2);
  if (roleId === 1) return 'Super Admin';
  if (roleId === 3) return 'Staff';
  return 'Business Owner';
});
</script>
