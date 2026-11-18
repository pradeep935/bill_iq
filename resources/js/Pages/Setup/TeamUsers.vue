<template>
  <section class="tu">
    <div class="tu-head">
      <div>
        <h2>Team Users</h2>
        <p>People who can log in to this business. Admin = full access except SaaS; Staff = billing, customers and stock view.</p>
      </div>
      <button type="button" class="tu-primary" @click="openAdd">+ Add User</button>
    </div>

    <div v-if="message" class="tu-note" :class="{ error: isError }">{{ message }}</div>

    <div class="tu-wrap">
      <table class="tu-table">
        <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last login</th><th></th></tr></thead>
        <tbody>
          <tr v-for="u in users" :key="u.id">
            <td><strong>{{ u.name }}</strong><span v-if="u.is_owner" class="tu-tag">Owner</span><span v-if="u.id === currentUserId" class="tu-tag me">You</span></td>
            <td>{{ u.email }}</td>
            <td>{{ u.role }}</td>
            <td><span class="tu-pill" :class="u.is_active ? 'on' : 'off'">{{ u.is_active ? 'Active' : 'Inactive' }}</span></td>
            <td>{{ u.last_login_at || '—' }}</td>
            <td class="tu-actions">
              <button type="button" @click="openEdit(u)">Edit</button>
              <button v-if="!u.is_owner && u.id !== currentUserId" type="button" @click="toggle(u)">{{ u.is_active ? 'Deactivate' : 'Activate' }}</button>
            </td>
          </tr>
          <tr v-if="!users.length"><td colspan="6" class="tu-empty">{{ loading ? 'Loading…' : 'No users yet. Click “Add User”.' }}</td></tr>
        </tbody>
      </table>
    </div>

    <div v-if="modal" class="tu-backdrop" @click.self="modal = false">
      <form class="tu-modal" @submit.prevent="save">
        <h3>{{ form.id ? 'Edit user' : 'Add new user' }}</h3>
        <label>Full name<input v-model="form.name" required maxlength="255" /></label>
        <label>Email (login)<input v-model="form.email" type="email" :disabled="!!form.id" required /></label>
        <label>Role
          <select v-model.number="form.role_id" :disabled="form.locked">
            <option :value="3">Staff — billing, customers, stock view</option>
            <option :value="2">Admin — full business access</option>
          </select>
        </label>
        <label>{{ form.id ? 'New password (leave blank to keep)' : 'Password (min 8 characters)' }}
          <input v-model="form.password" type="text" minlength="8" :required="!form.id" autocomplete="new-password" />
        </label>
        <p v-for="(e, k) in errors" :key="k" class="tu-err">{{ Array.isArray(e) ? e[0] : e }}</p>
        <div class="tu-modal-actions">
          <button type="button" @click="modal = false">Cancel</button>
          <button type="submit" class="tu-primary" :disabled="saving">{{ saving ? 'Saving…' : 'Save' }}</button>
        </div>
      </form>
    </div>
  </section>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import axios from 'axios';

const BASE = '/app/setup/team-users';
const users = ref([]);
const currentUserId = ref(null);
const loading = ref(true);
const message = ref('');
const isError = ref(false);
const modal = ref(false);
const saving = ref(false);
const errors = ref({});
const form = reactive({ id: null, name: '', email: '', role_id: 3, password: '', locked: false });

const notify = (text, error = false) => { message.value = text; isError.value = error; };

async function load() {
  loading.value = true;
  try {
    const { data } = await axios.get(BASE);
    users.value = data.users;
    currentUserId.value = data.current_user_id;
  } catch (e) {
    notify(e.response?.data?.message || 'Could not load users.', true);
  } finally { loading.value = false; }
}

function openAdd() {
  Object.assign(form, { id: null, name: '', email: '', role_id: 3, password: '', locked: false });
  errors.value = {}; modal.value = true;
}
function openEdit(u) {
  Object.assign(form, { id: u.id, name: u.name, email: u.email, role_id: u.role_id, password: '', locked: u.is_owner || u.id === currentUserId.value });
  errors.value = {}; modal.value = true;
}

async function save() {
  saving.value = true; errors.value = {};
  try {
    if (form.id) {
      const body = { name: form.name, role_id: form.role_id };
      if (form.password) body.password = form.password;
      await axios.put(`${BASE}/${form.id}`, body);
    } else {
      await axios.post(BASE, { name: form.name, email: form.email, role_id: form.role_id, password: form.password });
    }
    modal.value = false;
    notify(form.id ? 'User updated.' : 'User created. Share the email and password with them.');
    await load();
  } catch (e) {
    errors.value = e.response?.data?.errors || { general: e.response?.data?.message || 'Could not save the user.' };
  } finally { saving.value = false; }
}

async function toggle(u) {
  try {
    await axios.put(`${BASE}/${u.id}`, { is_active: !u.is_active });
    notify(`${u.name} ${u.is_active ? 'deactivated' : 'activated'}.`);
    await load();
  } catch (e) { notify(e.response?.data?.message || 'Action failed.', true); }
}

onMounted(load);
</script>

<style scoped>
.tu { background: #fff; border: 1px solid #dfe6ef; border-radius: 8px; margin-top: 18px; padding: 18px; }
.tu-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 14px; margin-bottom: 14px; }
.tu-head h2 { margin: 0; font-size: 18px; color: #142139; }
.tu-head p { margin: 4px 0 0; font-size: 12px; color: #758197; }
.tu-primary { background: #2563eb; color: #fff; border: 0; border-radius: 8px; padding: 9px 14px; font-weight: 700; font-size: 13px; cursor: pointer; }
.tu-note { background: #e7f8ef; color: #15803d; border-radius: 8px; padding: 9px 12px; font-size: 13px; margin-bottom: 12px; }
.tu-note.error { background: #fef2f2; color: #b91c1c; }
.tu-wrap { border: 1px solid #edf1f5; border-radius: 8px; overflow: auto; }
.tu-table { border-collapse: collapse; width: 100%; min-width: 640px; }
.tu-table th, .tu-table td { border-bottom: 1px solid #edf1f5; padding: 11px 10px; font-size: 13px; text-align: left; }
.tu-table th { background: #f8fafc; color: #69758a; font-size: 10px; letter-spacing: .04em; text-transform: uppercase; }
.tu-tag { margin-left: 6px; background: #eef2ff; color: #3b4fd8; border-radius: 6px; font-size: 10px; padding: 2px 6px; font-weight: 700; }
.tu-tag.me { background: #ecfdf5; color: #047857; }
.tu-pill { border-radius: 999px; padding: 3px 9px; font-size: 11px; font-weight: 700; }
.tu-pill.on { background: #e7f8ef; color: #15803d; } .tu-pill.off { background: #fef2f2; color: #b91c1c; }
.tu-actions { white-space: nowrap; text-align: right !important; }
.tu-actions button { background: #fff; border: 1px solid #d8e0eb; border-radius: 7px; padding: 5px 10px; font-size: 12px; font-weight: 700; margin-left: 6px; cursor: pointer; }
.tu-empty { text-align: center !important; color: #8490a2; }
.tu-backdrop { position: fixed; inset: 0; background: rgba(15, 23, 42, .45); z-index: 80; display: flex; align-items: center; justify-content: center; padding: 16px; }
.tu-modal { background: #fff; border-radius: 14px; width: 420px; max-width: 100%; padding: 20px; display: flex; flex-direction: column; gap: 12px; }
.tu-modal h3 { margin: 0 0 2px; }
.tu-modal label { display: flex; flex-direction: column; gap: 5px; font-size: 12px; font-weight: 700; color: #344159; }
.tu-modal input, .tu-modal select { border: 1px solid #cfd6e0; border-radius: 8px; padding: 9px 10px; font-size: 13px; font-weight: 400; }
.tu-err { color: #b91c1c; font-size: 12px; margin: 0; }
.tu-modal-actions { display: flex; justify-content: flex-end; gap: 8px; }
.tu-modal-actions button { border: 1px solid #d8e0eb; background: #fff; border-radius: 8px; padding: 9px 14px; font-weight: 700; cursor: pointer; }
.tu-modal-actions .tu-primary { border: 0; background: #2563eb; }
</style>
