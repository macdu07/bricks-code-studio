const config = window.BCS_BRIDGE || {};
const previewKey = `bcs-js-preview:${config.channel || 'default'}`;
const parentOrigin = (() => {
  try { return new URL(config.origin || window.location.origin).origin; }
  catch (_) { return window.location.origin; }
})();

function trusted(event, data) {
  return event.origin === parentOrigin && event.source === window.parent && data?.source === 'bricks-code-studio' && data.channel === config.channel;
}

function notifyParent(type, payload = {}) {
  window.parent.postMessage({ source: 'bricks-code-studio-bridge', channel: config.channel, type, ...payload }, parentOrigin);
}

function stylePreview(css) {
  let node = document.getElementById('bcs-live-style');
  if (!node) { node = document.createElement('style'); node.id = 'bcs-live-style'; }
  node.textContent = css || '';
  // Bricks appends builder-generated rules dynamically. Keep preview last so equal-specificity
  // workspace rules win through normal cascade order, without mutating them with !important.
  const host = document.body || document.documentElement;
  host.appendChild(node);
  notifyParent('bcs:css-applied', { contentHash: String(css || '').length });
}

function htmlPreview(payload) {
  clearHtmlPreview();
  const overlay = document.createElement('div');
  overlay.id = 'bcs-structure-preview';
  overlay.innerHTML = payload.html || '';
  const style = document.createElement('style');
  style.id = 'bcs-structure-preview-style';
  style.textContent = `${payload.css || ''}\n#bcs-structure-preview{position:absolute;inset:0 auto auto 0;width:100%;min-height:100vh;background:#fff;z-index:2147483000}`;
  document.head.appendChild(style);
  document.body.appendChild(overlay);
}

function clearHtmlPreview() {
  document.getElementById('bcs-structure-preview')?.remove();
  document.getElementById('bcs-structure-preview-style')?.remove();
}

window.addEventListener('message', event => {
  const data = event.data;
  if (!trusted(event, data)) return;
  if (data.type === 'bcs:ping') notifyParent('bcs:ready');
  if (data.type === 'bcs:css') stylePreview(data.css);
  if (data.type === 'bcs:html') htmlPreview(data);
  if (data.type === 'bcs:clear') clearHtmlPreview();
  if (data.type === 'bcs:run-js') {
    sessionStorage.setItem(previewKey, JSON.stringify({ code: data.code || '', expires: Date.now() + 5 * 60 * 1000 }));
    window.location.reload();
  }
});

notifyParent('bcs:ready');

const previewDraft = sessionStorage.getItem(previewKey);
if (previewDraft !== null) {
  sessionStorage.removeItem(previewKey);
  try {
    const preview = JSON.parse(previewDraft);
    if (preview.expires >= Date.now()) {
      window.addEventListener('DOMContentLoaded', () => {
        try { Function(`"use strict";\n${preview.code}`)(); }
        catch (error) { console.error('[Bricks Code Studio JS preview]', error); }
      }, { once: true });
    }
  } catch (_) {}
}
