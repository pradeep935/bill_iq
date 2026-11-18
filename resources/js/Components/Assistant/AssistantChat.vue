<template>
  <div class="aic" :class="{ raised: page === 'pos' }">
    <button v-if="!open" class="aic-fab" type="button" title="Ask BillIQ Assistant" @click="open = true">
      <span class="aic-logo aic-logo-sm"><span>Rs.</span></span>
      <span class="aic-fab-text">Ask BillIQ</span>
    </button>

    <section v-else class="aic-panel" role="dialog" aria-label="BillIQ Assistant">
      <div class="aic-head">
        <span class="aic-logo"><span>Rs.</span></span>
        <div class="aic-head-text">
          <strong>Bill IQ Assistant</strong>
          <small><i class="aic-dot"></i> Online · Ask me anything</small>
        </div>
        <button v-if="messages.length" class="aic-icon-btn" type="button" title="New chat" @click="messages = []">↺</button>
        <button class="aic-icon-btn" type="button" title="Close" @click="open = false">✕</button>
      </div>

      <div ref="body" class="aic-body">
        <div v-if="!messages.length" class="aic-welcome">
          <span class="aic-logo aic-logo-lg"><span>Rs.</span></span>
          <h3>Hi! I'm your BillIQ Assistant</h3>
          <p>Ask me anything about billing, inventory, GST, accounting, CRM or payroll.</p>
          <div class="aic-chips">
            <button v-for="s in suggestions" :key="s" type="button" @click="ask(s)">{{ s }}</button>
          </div>
        </div>

        <div v-for="(m, i) in messages" :key="i" :class="['aic-row', m.role]">
          <span v-if="m.role === 'assistant'" class="aic-logo aic-logo-xs"><span>Rs.</span></span>
          <div class="aic-msg" v-html="format(m.content)"></div>
        </div>

        <div v-if="loading" class="aic-row assistant">
          <span class="aic-logo aic-logo-xs"><span>Rs.</span></span>
          <div class="aic-msg aic-typing"><i></i><i></i><i></i></div>
        </div>
      </div>

      <form class="aic-form" @submit.prevent="ask(input)">
        <input v-model="input" type="text" maxlength="1000" placeholder="Type your question…" :disabled="loading" />
        <button type="submit" :disabled="loading || !input.trim()" title="Send">➤</button>
      </form>
    </section>
  </div>
</template>

<script setup>
import { nextTick, ref } from 'vue';
import axios from 'axios';

const props = defineProps({ page: { type: String, default: '' } });
const open = ref(false);
const input = ref('');
const loading = ref(false);
const messages = ref([]);
const body = ref(null);

const suggestions = [
  'How do I create a sales invoice?',
  'How do I add a new customer?',
  'How do I record a purchase bill?',
  'How do I receive stock (GRN)?',
  'How do I check current stock?',
  'Where can I see GST reports?',
  'How do I add a product with HSN?',
  'How do I create a sales return?',
  'How do I run payroll?',
  'How do I add a lead in CRM?',
  'How do I record a payment or receipt?',
  'How do I set up a new business?',
];

const scroll = () => nextTick(() => { if (body.value) body.value.scrollTop = body.value.scrollHeight; });

// Escape first, then allow only **bold**, `code`, bullet lists and line breaks.
function format(text) {
  const esc = String(text || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  return esc
    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
    .replace(/`([^`]+)`/g, '<code>$1</code>')
    .replace(/^\s*[-•*]\s+(.*)$/gm, '<span class="aic-li">• $1</span>')
    .replace(/\n/g, '<br>');
}

async function ask(raw) {
  const text = String(raw || '').trim();
  if (!text || loading.value) return;
  const history = messages.value.slice(-8).map(({ role, content }) => ({ role, content }));
  messages.value.push({ role: 'user', content: text });
  input.value = '';
  loading.value = true;
  scroll();
  try {
    const { data } = await axios.post('/app/assistant/chat', { message: text, history, page: props.page });
    messages.value.push({ role: 'assistant', content: data.answer });
  } catch (e) {
    messages.value.push({ role: 'assistant', content: e.response?.status === 429 ? 'Too many requests. Please wait a moment and try again.' : 'Could not reach the assistant. Please try again.' });
  } finally {
    loading.value = false;
    scroll();
  }
}
</script>

<style scoped>
.aic { --aic-blue: #2f4bd8; --aic-navy: #111b3a; }

/* BillIQ logo (same Rs. badge as the sidebar) */
.aic-logo { flex: none; width: 38px; height: 38px; border-radius: 11px; background: linear-gradient(135deg, #4f7cff, #2f4bd8); display: inline-flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(47, 75, 216, .4); }
.aic-logo > span { color: #fff; font-weight: 800; font-size: 15px; letter-spacing: -.5px; }
.aic-logo-sm { width: 30px; height: 30px; border-radius: 9px; box-shadow: none; background: #fff; }
.aic-logo-sm > span { color: var(--aic-blue); font-size: 12px; }
.aic-logo-xs { width: 26px; height: 26px; border-radius: 8px; box-shadow: none; }
.aic-logo-xs > span { font-size: 10px; }
.aic-logo-lg { width: 56px; height: 56px; border-radius: 16px; }
.aic-logo-lg > span { font-size: 21px; }

.aic-fab { position: fixed; right: 22px; bottom: 22px; z-index: 60; display: flex; align-items: center; gap: 9px; padding: 8px 18px 8px 8px; border: 0; border-radius: 999px; background: linear-gradient(135deg, #4f7cff, #2f4bd8); color: #fff; font-weight: 700; font-size: 14px; cursor: pointer; box-shadow: 0 8px 24px rgba(47, 75, 216, .45); transition: transform .15s; }
.aic-fab:hover { transform: translateY(-2px); }

.aic-panel { position: fixed; right: 22px; bottom: 22px; z-index: 60; width: 380px; max-width: calc(100vw - 24px); height: 540px; max-height: calc(100vh - 44px); display: flex; flex-direction: column; background: #fff; border-radius: 18px; overflow: hidden; box-shadow: 0 18px 50px rgba(15, 23, 42, .35); border: 1px solid rgba(15, 23, 42, .08); }

.aic-head { display: flex; align-items: center; gap: 10px; padding: 14px 14px; background: linear-gradient(135deg, #111b3a, #1c2b66); color: #fff; }
.aic-head-text { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.aic-head-text strong { font-size: 15px; line-height: 1.2; }
.aic-head-text small { font-size: 11px; opacity: .75; margin-top: 2px; }
.aic-dot { display: inline-block; width: 7px; height: 7px; border-radius: 50%; background: #34d399; margin-right: 3px; }
.aic-icon-btn { width: 28px; height: 28px; border: 0; border-radius: 8px; background: rgba(255, 255, 255, .12); color: #fff; font-size: 13px; cursor: pointer; }
.aic-icon-btn:hover { background: rgba(255, 255, 255, .25); }

.aic-body { flex: 1; overflow-y: auto; padding: 14px; display: flex; flex-direction: column; gap: 10px; background: #f4f6fb; }

.aic-welcome { margin: auto 0; text-align: center; display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 6px 4px; }
.aic-welcome h3 { margin: 8px 0 0; font-size: 16px; color: #111b3a; }
.aic-welcome p { margin: 0 0 8px; font-size: 13px; color: #64708a; }
.aic-chips { display: flex; flex-wrap: wrap; justify-content: center; gap: 7px; width: 100%; }
.aic-chips button { text-align: left; padding: 7px 12px; border: 1px solid #dbe2f3; border-radius: 999px; background: #fff; color: #1f2d5c; font-size: 12.5px; font-weight: 600; cursor: pointer; transition: all .15s; }
.aic-chips button:hover { border-color: var(--aic-blue); background: #eef2ff; }

.aic-row { display: flex; align-items: flex-end; gap: 7px; }
.aic-row.user { justify-content: flex-end; }
.aic-msg { max-width: 82%; padding: 9px 12px; border-radius: 14px; font-size: 13.5px; line-height: 1.5; word-break: break-word; }
.aic-row.user .aic-msg { background: linear-gradient(135deg, #4f7cff, #2f4bd8); color: #fff; border-bottom-right-radius: 4px; }
.aic-row.assistant .aic-msg { background: #fff; color: #1f2937; border: 1px solid #e3e8f3; border-bottom-left-radius: 4px; }
.aic-msg :deep(code) { background: #eef1f8; padding: 1px 5px; border-radius: 5px; font-size: 12px; }
.aic-msg :deep(.aic-li) { display: block; padding-left: 4px; }

.aic-typing { display: flex; gap: 4px; padding: 12px 14px; }
.aic-typing i { width: 6px; height: 6px; border-radius: 50%; background: #9aa6c4; animation: aic-bounce 1s infinite; }
.aic-typing i:nth-child(2) { animation-delay: .15s; }
.aic-typing i:nth-child(3) { animation-delay: .3s; }
@keyframes aic-bounce { 0%, 80%, 100% { transform: translateY(0); opacity: .5; } 40% { transform: translateY(-4px); opacity: 1; } }

.aic-form { display: flex; gap: 8px; padding: 10px 12px; background: #fff; border-top: 1px solid #e8ecf5; }
.aic-form input { flex: 1; padding: 10px 14px; border: 1px solid #d7deef; border-radius: 999px; font-size: 13.5px; outline: none; background: #f8f9fd; }
.aic-form input:focus { border-color: var(--aic-blue); background: #fff; }
.aic-form button { width: 40px; height: 40px; border: 0; border-radius: 50%; background: var(--aic-blue); color: #fff; font-size: 14px; cursor: pointer; }
.aic-form button:disabled { opacity: .45; cursor: default; }

/* POS has a fixed bottom bar with the Complete button: lift the assistant above it */
.aic.raised .aic-fab { bottom: 104px; }
.aic.raised .aic-panel { bottom: 104px; max-height: calc(100vh - 128px); }

@media (max-width: 520px) {
  .aic-panel { right: 8px; bottom: 8px; height: calc(100vh - 16px); max-height: none; }
  .aic-fab-text { display: none; }
  .aic-fab { padding: 8px; }
}
</style>
