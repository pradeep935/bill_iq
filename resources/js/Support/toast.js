// Non-blocking replacement for window.alert(): many screens call alert() after saving or on errors. A browser alert
// freezes the whole page and looks unprofessional, so the message is shown as a toast in the corner instead.
const ERROR_WORDS = /\b(could not|cannot|can't|failed|fail|error|invalid|required|unable|not found|denied|already|please|must|exceed|insufficient|mismatch)\b/i;

let host = null;

const ensureHost = () => {
    if (host && document.body.contains(host)) return host;
    host = document.createElement('div');
    host.setAttribute('aria-live', 'polite');
    host.style.cssText = 'position:fixed;top:16px;right:16px;z-index:100000;display:flex;flex-direction:column;gap:8px;max-width:min(420px,calc(100vw - 32px));pointer-events:none;';
    document.body.appendChild(host);
    return host;
};

export const showToast = (message, type) => {
    const text = String(message ?? '').trim();
    if (!text) return;
    const kind = type || (ERROR_WORDS.test(text) ? 'error' : 'ok');
    const colors = kind === 'error'
        ? 'color:#912018;background:#fef3f2;border:1px solid #fecdca;'
        : 'color:#065f46;background:#ecfdf3;border:1px solid #a6f4c5;';
    const el = document.createElement('div');
    el.setAttribute('role', kind === 'error' ? 'alert' : 'status');
    el.style.cssText = `${colors}pointer-events:auto;padding:12px 14px;border-radius:10px;font:600 13px/1.4 Inter,system-ui,sans-serif;box-shadow:0 10px 30px rgba(15,23,42,.18);cursor:pointer;white-space:pre-line;`;
    el.textContent = text;
    const remove = () => el.remove();
    el.addEventListener('click', remove);
    ensureHost().appendChild(el);
    setTimeout(remove, kind === 'error' ? 8000 : 4500);
};

export const installAlertToast = () => {
    window.alert = (message) => showToast(message);
};
