import { EditorView, keymap, lineNumbers } from '@codemirror/view';
import { EditorState } from '@codemirror/state';
import { defaultKeymap, history, historyKeymap } from '@codemirror/commands';
import { autocompletion, completionKeymap } from '@codemirror/autocomplete';
import { css, cssLanguage } from '@codemirror/lang-css';
import { html, htmlLanguage } from '@codemirror/lang-html';
import { javascript, javascriptLanguage } from '@codemirror/lang-javascript';
import { oneDark } from '@codemirror/theme-one-dark';
import { refreshBricksDesignState } from './bricks-state-adapter.js';

const boot = window.BCS_BOOT;
if (!boot) throw new Error('Bricks Code Studio boot data is missing.');

const state = {
  postId: Number(boot.postId || window.bricksData?.postId || 0),
  scope: 'global',
  files: [],
  manifest: { entries: [] },
  completionIndex: { scssVariables: [], cssVariables: [], classes: [], imports: [] },
  activePath: '',
  contentHash: '',
  structureHash: '',
  editor: null,
  compiledCss: '',
  lastRevision: null,
  diagnostics: [],
  previewTimer: null,
  previewRequest: 0,
  canvasReady: false,
  pendingCss: '',
  dirty: false,
  saveInFlight: null,
  prefs: { height: 360, open: true, scope: 'global', autoSync: true },
};

const root = document.getElementById('bricks-code-studio-root');
root.innerHTML = `
  <section class="bcs-panel" aria-label="Bricks Code Studio">
    <div class="bcs-resizer" title="Resize panel"></div>
    <header class="bcs-header">
      <div class="bcs-brand"><span class="bcs-mark">&lt;/&gt;</span><strong>Code Studio</strong><small>Experimental</small><span class="bcs-live" data-live>● Connecting</span></div>
      <div class="bcs-scopes" role="group"><button data-scope="global">Global</button><button data-scope="document">Document</button></div>
      <nav class="bcs-tabs"><button data-tab="scss">SCSS</button><button data-tab="css">CSS</button><button data-tab="js">JS</button><button data-tab="html">HTML</button></nav>
      <div class="bcs-actions">
        <button data-action="new" title="New file">＋</button>
        <button data-action="rename" title="Rename">Rename</button>
        <button data-action="delete" title="Delete">Delete</button>
        <button data-action="auto-sync" title="Synchronize compatible CSS resources with Bricks whenever a style file is saved">Auto-sync</button>
        <button data-action="design" title="Sync compatible classes and variables">Sync Bricks</button>
        <button data-action="preview">Preview</button>
        <button data-action="run">Run JS</button>
        <button class="bcs-primary" data-action="save">Save</button>
        <button data-action="apply" hidden>Apply structure</button>
        <button data-action="undo" hidden>Undo</button>
        <button data-action="fullscreen" title="Fullscreen">⛶</button>
        <button data-action="reload" title="Reload Bricks to refresh visual controls" hidden>↻ Bricks</button>
        <button data-action="minimize" title="Minimize">—</button>
      </div>
    </header>
    <div class="bcs-body">
      <aside class="bcs-files"><div class="bcs-files-title">Workspace</div><div class="bcs-file-list"></div></aside>
      <main class="bcs-editor-area"><div class="bcs-breadcrumb">Choose a file</div><div class="bcs-editor"></div></main>
      <aside class="bcs-diagnostics"><div class="bcs-diagnostics-title">Diagnostics</div><div class="bcs-diagnostics-list">Ready.</div></aside>
    </div>
    <footer class="bcs-status"><span data-status>Ready</span><span data-meta>Autocomplete: Ctrl/⌘ Space · Bricks Code Studio ${boot.version}</span></footer>
  </section>`;

const panel = root.querySelector('.bcs-panel');
const fileList = root.querySelector('.bcs-file-list');
const editorHost = root.querySelector('.bcs-editor');
const breadcrumb = root.querySelector('.bcs-breadcrumb');
const diagnosticsList = root.querySelector('.bcs-diagnostics-list');
const statusNode = root.querySelector('[data-status]');

async function api(path, method = 'GET', data = null) {
  const options = { method, headers: { 'X-WP-Nonce': boot.nonce } };
  let url = `${boot.restUrl}${path}`;
  if (method === 'GET' && data) {
    url += `?${new URLSearchParams(Object.entries(data).filter(([, v]) => v !== undefined && v !== null))}`;
  } else if (data) {
    options.headers['Content-Type'] = 'application/json';
    options.body = JSON.stringify(data);
  }
  const response = await fetch(url, options);
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) throw Object.assign(new Error(payload.message || `Request failed (${response.status})`), { payload, status: response.status });
  return payload;
}

function params(extra = {}) { return { scope: state.scope, postId: state.postId, ...extra }; }
function setStatus(message, type = '') { statusNode.textContent = message; statusNode.dataset.type = type; }
function language(path) {
  if (path.endsWith('.js')) return javascript();
  if (path === 'structure.html') return html();
  return css();
}

function completionLanguageData(path) {
  if (path.endsWith('.js')) return javascriptLanguage.data.of({ autocomplete: workspaceCompletionSource });
  if (path === 'structure.html') return htmlLanguage.data.of({ autocomplete: workspaceCompletionSource });
  return cssLanguage.data.of({ autocomplete: workspaceCompletionSource });
}

function extractStyleSymbols(content) {
  const found = { scssVariables: [], cssVariables: [], classes: [] };
  let match;
  const scssPattern = /\$([a-zA-Z_][\w-]*)\s*:/g;
  const cssVariablePattern = /(--[a-zA-Z_][\w-]*)\s*:/g;
  const classPattern = /(?:^|[\s},])\.(-?[_a-zA-Z][\w-]*)/gm;
  while ((match = scssPattern.exec(content))) found.scssVariables.push(`$${match[1]}`);
  while ((match = cssVariablePattern.exec(content))) found.cssVariables.push(match[1]);
  while ((match = classPattern.exec(content))) found.classes.push(match[1]);
  return found;
}

async function refreshCompletionIndex() {
  const styleFiles = state.files.filter(file => /\.(scss|css)$/i.test(file.path)).slice(0, 40);
  const contents = await Promise.all(styleFiles.map(async file => {
    try { return (await api('/file', 'GET', params({ path: file.path }))).content || ''; }
    catch (_) { return ''; }
  }));
  const aggregate = { scssVariables: [], cssVariables: [], classes: [], imports: [] };
  contents.forEach(content => {
    const symbols = extractStyleSymbols(content);
    aggregate.scssVariables.push(...symbols.scssVariables);
    aggregate.cssVariables.push(...symbols.cssVariables);
    aggregate.classes.push(...symbols.classes);
  });
  aggregate.imports = styleFiles.filter(file => file.path.endsWith('.scss') && file.path !== state.activePath).map(file => {
    const parts = file.path.split('/');
    parts[parts.length - 1] = parts[parts.length - 1].replace(/^_/, '').replace(/\.scss$/i, '');
    return parts.join('/').replace(/^scss\//, '');
  });
  Object.keys(aggregate).forEach(key => { aggregate[key] = [...new Set(aggregate[key])].sort(); });
  state.completionIndex = aggregate;
}

function workspaceCompletionSource(context) {
  const path = state.activePath;
  const line = context.state.doc.lineAt(context.pos);
  const before = context.state.sliceDoc(line.from, context.pos);
  const currentSymbols = extractStyleSymbols(context.state.doc.toString());
  const uniqueOptions = (items, type, detail) => [...new Set(items)].map(label => ({ label, type, detail }));

  if (path.endsWith('.scss')) {
    const importMatch = /@(use|forward|import)\s+["']([^"']*)$/.exec(before);
    if (importMatch) {
      const activeImport = path.split('/').pop().replace(/^_/, '').replace(/\.scss$/i, '');
      const imports = state.completionIndex.imports.filter(item => item !== activeImport && item !== path.replace(/^scss\//, '').replace(/\.scss$/i, ''));
      return { from: context.pos - importMatch[2].length, options: uniqueOptions(imports, 'namespace', 'Workspace SCSS file') };
    }
    const variable = context.matchBefore(/(?:\$|--)[\w-]*/);
    if (variable && (context.explicit || variable.text.length > 1)) {
      return {
        from: variable.from,
        options: [
          ...uniqueOptions([...state.completionIndex.scssVariables, ...currentSymbols.scssVariables], 'variable', 'SCSS variable'),
          ...uniqueOptions([...state.completionIndex.cssVariables, ...currentSymbols.cssVariables], 'variable', 'CSS custom property'),
        ],
      };
    }
  }

  if (path.endsWith('.css')) {
    const variable = context.matchBefore(/--[\w-]*/);
    if (variable && (context.explicit || variable.text.length > 2)) {
      return { from: variable.from, options: uniqueOptions([...state.completionIndex.cssVariables, ...currentSymbols.cssVariables], 'variable', 'CSS custom property') };
    }
  }

  if (path === 'structure.html' && /class\s*=\s*["'][^"']*$/.test(before)) {
    const word = context.matchBefore(/[\w-]*/);
    if (word) return { from: word.from, options: uniqueOptions(state.completionIndex.classes, 'class', 'Workspace CSS class') };
  }

  if (path.endsWith('.js')) {
    const word = context.matchBefore(/[\w$]*/);
    if (!word || (!context.explicit && word.text.length < 2)) return null;
    return {
      from: word.from,
      options: uniqueOptions(
        ['window', 'document', 'console', 'fetch', 'localStorage', 'sessionStorage', 'setTimeout', 'requestAnimationFrame', 'MutationObserver', 'IntersectionObserver'],
        'variable',
        'Browser API'
      ),
    };
  }
  return null;
}

function mountEditor(content = '', readOnly = false) {
  if (state.editor) state.editor.destroy();
  state.editor = new EditorView({
    state: EditorState.create({
      doc: content,
      extensions: [lineNumbers(), history(), autocompletion({ activateOnTyping: true, activateOnTypingDelay: 120, maxRenderedOptions: 60 }), keymap.of([...completionKeymap, ...defaultKeymap, ...historyKeymap]), language(state.activePath), completionLanguageData(state.activePath), oneDark, EditorView.editable.of(!readOnly), EditorView.updateListener.of(update => {
        if (!update.docChanged || readOnly) return;
        state.dirty = true;
        setStatus('Unsaved changes', 'dirty');
        if (state.activePath.endsWith('.js')) validateJavascript(update.state.doc.toString());
        else schedulePreview();
      })],
    }),
    parent: editorHost,
  });
}

function currentContent() { return state.editor ? state.editor.state.doc.toString() : ''; }
function renderFiles() {
  fileList.replaceChildren();
  const tree = {};
  state.files.forEach(file => {
    let branch = tree;
    const parts = file.path.split('/');
    parts.forEach((part, index) => {
      branch[part] ||= { children: {}, file: null };
      if (index === parts.length - 1) branch[part].file = file;
      branch = branch[part].children;
    });
  });
  const renderBranch = (branch, host) => Object.keys(branch).sort((a, b) => a.localeCompare(b, undefined, { numeric: true })).forEach(name => {
    const node = branch[name];
    if (Object.keys(node.children).length) {
      const folder = document.createElement('details');
      folder.className = 'bcs-folder';
      folder.open = true;
      const label = document.createElement('summary');
      label.textContent = name;
      folder.appendChild(label);
      const children = document.createElement('div');
      renderBranch(node.children, children);
      folder.appendChild(children);
      host.appendChild(folder);
    }
    if (node.file) {
      const button = document.createElement('button');
      button.className = `bcs-file${node.file.path === state.activePath ? ' is-active' : ''}`;
      button.dataset.path = node.file.path;
      button.title = node.file.path;
      button.textContent = name;
      if ((state.manifest.entries || []).includes(node.file.path)) button.dataset.entry = 'entry';
      button.addEventListener('click', () => openFile(node.file.path));
      host.appendChild(button);
    }
  });
  renderBranch(tree, fileList);
}

async function loadWorkspace(preferred = '') {
  setStatus('Loading workspace…');
  const data = await api('/workspace', 'GET', params());
  state.files = data.files || [];
  state.manifest = data.manifest || { entries: [] };
  await refreshCompletionIndex();
  renderFiles();
  const target = preferred || state.prefs.activeFile || state.files[0]?.path;
  if (target && state.files.some(file => file.path === target)) await openFile(target);
  else { state.activePath = ''; state.dirty = false; breadcrumb.textContent = 'Empty workspace'; mountEditor(''); }
  setStatus('Ready');
}

async function openFile(path) {
  if (path === 'structure.html') return openStructure();
  setStatus(`Opening ${path}…`);
  const data = await api('/file', 'GET', params({ path }));
  state.activePath = path;
  state.contentHash = data.contentHash;
  state.dirty = false;
  breadcrumb.textContent = `${state.scope} / ${path}`;
  mountEditor(data.content);
  renderFiles();
  updateActions();
  setStatus('Ready');
  savePreferences();
}

async function openStructure() {
  if (!state.postId) return setStatus('No Bricks document is open.', 'error');
  setStatus('Generating protected HTML view…');
  const data = await api('/structure', 'GET', { postId: state.postId });
  state.activePath = 'structure.html';
  state.structureHash = data.treeHash;
  state.contentHash = data.treeHash;
  state.dirty = false;
  breadcrumb.textContent = `document / structure.html · ${data.protectedIds.length} protected`;
  mountEditor(data.html);
  renderFiles();
  updateActions();
  setStatus('HTML view generated');
}

function updateActions() {
  const isHtml = state.activePath === 'structure.html';
  const isCompiled = state.activePath === 'compiled.css';
  const isJs = state.activePath.endsWith('.js');
  root.querySelector('[data-action="apply"]').hidden = !isHtml;
  root.querySelector('[data-action="save"]').hidden = isHtml || isCompiled;
  root.querySelector('[data-action="rename"]').hidden = isHtml || isCompiled;
  root.querySelector('[data-action="delete"]').hidden = isHtml || isCompiled;
  root.querySelector('[data-action="run"]').hidden = !isJs;
  root.querySelector('[data-action="design"]').hidden = !(state.activePath.endsWith('.scss') || state.activePath.endsWith('.css'));
  root.querySelector('[data-action="preview"]').hidden = isJs;
}

function renderDiagnostics(items = []) {
  state.diagnostics = items;
  diagnosticsList.replaceChildren();
  if (!items.length) { diagnosticsList.textContent = 'No diagnostics.'; return; }
  items.forEach(item => {
    const row = document.createElement('button');
    row.className = `bcs-diagnostic is-${item.severity || 'info'}`;
    row.textContent = `${item.path || ''}${item.line ? `:${item.line}` : ''} ${item.message || ''}`;
    diagnosticsList.appendChild(row);
  });
}

function validateJavascript(source) {
  try {
    Function(`"use strict";\n${source}`);
    renderDiagnostics([]);
  } catch (error) {
    renderDiagnostics([{ severity: 'error', path: state.activePath, message: error.message || 'Invalid JavaScript' }]);
  }
}

function schedulePreview() {
  clearTimeout(state.previewTimer);
  if (!state.activePath.match(/\.(scss|css)$/)) return;
  state.previewTimer = setTimeout(previewStyles, 280);
}

async function compile(publish = false) {
  return api('/compile', 'POST', params({ publish, draftPath: state.activePath, draftContent: currentContent() }));
}

async function previewStyles() {
  const request = ++state.previewRequest;
  try {
    const result = await compile(false);
    if (request !== state.previewRequest) return;
    renderDiagnostics(result.diagnostics || []);
    if ((result.diagnostics || []).some(item => item.severity === 'error')) return setStatus('SCSS contains errors', 'error');
    state.compiledCss = result.css || '';
    postToCanvas('bcs:css', { css: state.compiledCss });
    setStatus('Live style preview');
  } catch (error) { showError(error); }
}

async function saveFile({ fromBricksShortcut = false } = {}) {
  if (!state.activePath || state.activePath === 'structure.html' || state.activePath === 'compiled.css') return;
  if (state.saveInFlight) return state.saveInFlight;
  clearTimeout(state.previewTimer);
  state.saveInFlight = (async () => {
    try {
      setStatus(fromBricksShortcut ? 'Saving Code Studio; Bricks save continues…' : 'Saving…');
      const saved = await api('/file', 'PUT', params({ path: state.activePath, content: currentContent(), expectedHash: state.contentHash }));
      state.contentHash = saved.contentHash;
      state.dirty = false;
      const result = await compile(true);
      renderDiagnostics(result.diagnostics || []);
      const hasErrors = (result.diagnostics || []).some(item => item.severity === 'error');
      let syncResult = null;
      if (!hasErrors) {
        state.compiledCss = result.css || '';
        postToCanvas('bcs:css', { css: state.compiledCss });
        if (state.prefs.autoSync && /\.(scss|css)$/i.test(state.activePath) && result.publishedAssets) {
          try {
            syncResult = await synchronizeCss(result.css || '', { interactive: false });
          } catch (syncError) {
            console.error('[Bricks Code Studio] Automatic Bricks sync failed', syncError);
            renderDiagnostics([...(result.diagnostics || []), { severity: 'warning', message: `CSS was saved and published, but Bricks sync failed: ${syncError.message}` }]);
            syncResult = { applied: false, failed: true };
          }
        }
      }
      const syncSuffix = syncResult?.applied
        ? syncResult.builderRefreshed
          ? ' · Bricks panel refreshed'
          : ' · Bricks synchronized · reload controls'
        : syncResult?.needsConfirmation
          ? ' · Bricks link confirmation required'
          : syncResult?.failed
            ? ' · Bricks sync failed'
          : '';
      const finalStatus = result.publishedAssets
        ? { message: `${fromBricksShortcut ? 'Code Studio saved and published · Bricks save requested' : 'Saved and published'}${syncSuffix}`, type: syncResult?.needsConfirmation ? 'dirty' : 'success' }
        : { message: 'Source saved; last valid build remains published', type: 'error' };
      await loadWorkspace(state.activePath);
      setStatus(finalStatus.message, finalStatus.type);
    } catch (error) { showError(error); }
    finally { state.saveInFlight = null; }
  })();
  return state.saveInFlight;
}

async function previewStructure() {
  try {
    setStatus('Rendering structure preview…');
    const result = await api('/structure/preview', 'POST', { postId: state.postId, html: currentContent(), treeHash: state.structureHash });
    renderDiagnostics(diffDiagnostics(result.diff, result.warnings));
    postToCanvas('bcs:html', result.preview || {});
    setStatus('Structure preview ready');
  } catch (error) { showError(error); }
}

async function applyStructure() {
  try {
    const preview = await api('/structure/preview', 'POST', { postId: state.postId, html: currentContent(), treeHash: state.structureHash });
    const summary = summarizeDiff(preview.diff);
    if (!window.confirm(`Apply this structure change?\n\n${summary}\n\nA Bricks revision will be created.`)) return;
    const result = await api('/structure/apply', 'POST', { postId: state.postId, html: currentContent(), treeHash: state.structureHash, confirmed: true });
    state.lastRevision = result.revisionId;
    state.structureHash = result.treeHash;
    state.dirty = false;
    root.querySelector('[data-action="undo"]').hidden = !state.lastRevision;
    postToCanvas('bcs:clear');
    setStatus(`Structure applied · revision ${state.lastRevision || 'new'}`, 'success');
  } catch (error) { showError(error); }
}

async function undoStructure() {
  if (!state.lastRevision || !window.confirm(`Restore Bricks revision ${state.lastRevision}?`)) return;
  try {
    await api('/structure/restore', 'POST', { postId: state.postId, revisionId: state.lastRevision });
    state.lastRevision = null;
    root.querySelector('[data-action="undo"]').hidden = true;
    await openStructure();
    setStatus('Revision restored', 'success');
  } catch (error) { showError(error); }
}

async function syncDesign() {
  try {
    const compiled = await compile(false);
    if ((compiled.diagnostics || []).some(item => item.severity === 'error')) return renderDiagnostics(compiled.diagnostics);
    const syncResult = await synchronizeCss(compiled.css || '', { interactive: true });
    if (!syncResult?.applied) return;
    state.compiledCss = compiled.css || '';
    postToCanvas('bcs:css', { css: state.compiledCss });
    root.querySelector('[data-action="reload"]').hidden = syncResult.builderRefreshed;
    setStatus(`Synchronized${syncResult.linkedCount ? ` · ${syncResult.linkedCount} linked` : ''} · canvas updated${syncResult.builderRefreshed ? ' · Bricks controls refreshed' : ' · reload controls required'}`, syncResult.builderRefreshed ? 'success' : 'dirty');
  } catch (error) { showError(error); }
}

function designDiagnostics(preview) {
  const linkCandidates = preview.linkCandidates || [];
  return [
    ...(preview.warnings || []),
    ...linkCandidates.map(item => ({
      severity: 'warning',
      message: `${item.type} "${item.name}" already exists in Bricks (ID ${item.id}). Confirm once to link it; later saves will synchronize it automatically.`,
    })),
    ...(preview.conflicts || []).map(item => ({
      severity: 'warning',
      message: `Sync blocked: ${item.type} "${item.name}" conflicts with a Bricks resource. Your CSS is still saved and published.`,
    })),
  ];
}

async function synchronizeCss(cssText, { interactive = false } = {}) {
  const preview = await api('/design/preview', 'POST', { postId: state.postId, css: cssText });
  const linkCandidates = preview.linkCandidates || [];
  renderDiagnostics(designDiagnostics(preview));
  if (preview.conflicts?.length) {
    setStatus('CSS saved; resolve Bricks design resource conflicts', 'error');
    return { applied: false, conflicts: true };
  }
  const hasChanges = preview.classes.length || preview.variables.length || preview.elementsToUpdate.length || preview.generatedElements.length;
  if (!hasChanges) return { applied: false, empty: true };
  if (linkCandidates.length && !interactive) {
    setStatus('CSS saved; confirm the new Bricks resource link once', 'dirty');
    return { applied: false, needsConfirmation: true };
  }
  if (interactive) {
    const linkedNames = linkCandidates.map(item => `${item.type} “${item.name}”`).join(', ');
    const message = linkCandidates.length
      ? `${linkedNames} already ${linkCandidates.length === 1 ? 'exists' : 'exist'} in Bricks.\n\nLink Code Studio to ${linkCandidates.length === 1 ? 'this resource' : 'these resources'} and update it automatically on future saves? A restorable backup will be created first.`
      : `${preview.classes.length} classes and ${preview.variables.length} variables will be synchronized. Continue?`;
    if (!window.confirm(message)) return { applied: false, cancelled: true };
  }
  const result = await api('/design/apply', 'POST', {
    postId: state.postId,
    css: cssText,
    previewHash: preview.previewHash,
    confirmed: true,
    linkExisting: linkCandidates.length > 0,
  });
  const builderRefresh = refreshBricksDesignState(result.designState, boot.bricksVersion);
  root.querySelector('[data-action="reload"]').hidden = builderRefresh.refreshed;
  return {
    applied: true,
    linkedCount: (result.linked?.classes?.length || 0) + (result.linked?.variables?.length || 0),
    builderRefreshed: builderRefresh.refreshed,
    refreshReason: builderRefresh.reason || '',
    result,
  };
}

async function createFile() {
  const path = window.prompt('New file path (.scss, .css, or .js):', state.activePath.includes('/') ? state.activePath.replace(/[^/]+$/, '') : 'scss/');
  if (!path) return;
  try { await api('/file', 'PUT', params({ path, content: '', expectedHash: '' })); await loadWorkspace(path); } catch (error) { showError(error); }
}

async function renameFile() {
  if (!state.activePath || state.activePath === 'structure.html' || state.activePath === 'compiled.css') return;
  const to = window.prompt('New path:', state.activePath);
  if (!to || to === state.activePath) return;
  try { await api('/file/move', 'POST', params({ from: state.activePath, to })); await loadWorkspace(to); } catch (error) { showError(error); }
}

async function deleteFile() {
  if (!state.activePath || state.activePath === 'structure.html' || state.activePath === 'compiled.css' || !window.confirm(`Delete ${state.activePath}?`)) return;
  try { await api('/file', 'DELETE', params({ path: state.activePath })); state.activePath = ''; await loadWorkspace(); } catch (error) { showError(error); }
}

function canvasIframe() {
  const preferred = document.querySelector('#bricks-builder-iframe, iframe[name="bricks-builder-iframe"]');
  if (preferred) return preferred;
  return [...document.querySelectorAll('iframe')].find(frame => /bricks_preview|bricks=run/i.test(frame.src || '')) || document.querySelector('.brx-body iframe, iframe');
}

function postToCanvas(type, payload = {}) {
  const iframe = canvasIframe();
  if (!iframe?.contentWindow) return setStatus('Builder canvas iframe not found', 'error');
  if (type === 'bcs:css') state.pendingCss = payload.css || '';
  iframe.contentWindow.postMessage({ source: 'bricks-code-studio', channel: boot.channel, type: 'bcs:ping' }, boot.origin);
  iframe.contentWindow.postMessage({ source: 'bricks-code-studio', channel: boot.channel, type, ...payload }, boot.origin);
}

window.addEventListener('message', event => {
  const iframe = canvasIframe();
  const data = event.data;
  if (event.origin !== boot.origin || event.source !== iframe?.contentWindow || data?.source !== 'bricks-code-studio-bridge' || data.channel !== boot.channel) return;
  if (data.type === 'bcs:ready') {
    state.canvasReady = true;
    root.querySelector('[data-live]').textContent = '● Live';
    root.querySelector('[data-live]').classList.add('is-ready');
    if (state.pendingCss) iframe.contentWindow.postMessage({ source: 'bricks-code-studio', channel: boot.channel, type: 'bcs:css', css: state.pendingCss }, boot.origin);
  }
  if (data.type === 'bcs:css-applied') root.querySelector('[data-live]').title = 'Live CSS is applied to the canvas';
});

function diffDiagnostics(diff = {}, warnings = []) {
  const rows = [];
  ['added', 'removed', 'moved', 'updated'].forEach(key => (diff[key] || []).forEach(id => rows.push({ severity: key === 'removed' ? 'warning' : 'info', message: `${key}: ${id}` })));
  return rows.concat((warnings || []).map(item => ({ severity: 'warning', message: item.message || String(item) })));
}
function summarizeDiff(diff = {}) { return ['added', 'removed', 'moved', 'updated'].map(key => `${key}: ${(diff[key] || []).length}`).join('\n'); }
function showError(error) { console.error('[Bricks Code Studio]', error); renderDiagnostics([{ severity: 'error', message: error.message }]); setStatus(error.message, 'error'); }

async function savePreferences() {
  state.prefs = { ...state.prefs, scope: state.scope, activeFile: state.activePath, height: parseInt(panel.style.height, 10) || state.prefs.height, open: !panel.classList.contains('is-minimized'), autoSync: state.prefs.autoSync !== false };
  try { await api('/preferences', 'POST', state.prefs); } catch (_) {}
}

root.addEventListener('click', async event => {
  const button = event.target.closest('button');
  if (!button) return;
  const action = button.dataset.action;
  if (button.dataset.scope) {
    if (button.dataset.scope === 'document' && !state.postId) return setStatus('No document is open.', 'error');
    state.scope = button.dataset.scope; root.querySelectorAll('[data-scope]').forEach(el => el.classList.toggle('is-active', el.dataset.scope === state.scope)); await loadWorkspace(); savePreferences(); return;
  }
  if (button.dataset.tab) {
    const tab = button.dataset.tab;
    if (tab === 'html') return openStructure();
    if (tab === 'css') { state.activePath = 'compiled.css'; state.dirty = false; breadcrumb.textContent = 'Compiled preview'; mountEditor(state.compiledCss || '/* Compile SCSS to inspect output. */', true); updateActions(); return; }
    const match = state.files.find(file => file.path.endsWith(tab === 'scss' ? '.scss' : '.js'));
    if (match) return openFile(match.path);
  }
  const handlers = { new: createFile, rename: renameFile, delete: deleteFile, save: saveFile, preview: state.activePath === 'structure.html' ? previewStructure : previewStyles, apply: applyStructure, undo: undoStructure, design: syncDesign };
  if (handlers[action]) return handlers[action]();
  if (action === 'auto-sync') {
    state.prefs.autoSync = !state.prefs.autoSync;
    updateAutoSyncButton();
    await savePreferences();
    setStatus(`Automatic Bricks sync ${state.prefs.autoSync ? 'enabled' : 'disabled'}`, 'success');
    return;
  }
  if (action === 'run') { postToCanvas('bcs:run-js', { code: currentContent() }); setStatus('Reloading canvas with JS preview…'); }
  if (action === 'minimize') { panel.classList.toggle('is-minimized'); savePreferences(); }
  if (action === 'fullscreen') panel.classList.toggle('is-fullscreen');
  if (action === 'reload' && window.confirm('Reload Bricks now? Save any pending Bricks element changes first.')) window.location.reload();
});

// Run before Bricks' own shortcut handler, but do not cancel the event: both saves happen.
document.addEventListener('keydown', event => {
  if (!(event.ctrlKey || event.metaKey) || event.altKey || event.key.toLowerCase() !== 's') return;
  if (state.activePath === 'structure.html' && state.dirty) {
    setStatus('Bricks save requested · use Apply structure for HTML changes', 'dirty');
    return;
  }
  if (!state.dirty || !state.activePath || state.activePath === 'compiled.css') return;
  void saveFile({ fromBricksShortcut: true });
}, true);

const resizer = root.querySelector('.bcs-resizer');
resizer.addEventListener('pointerdown', event => {
  event.preventDefault();
  const startY = event.clientY, startHeight = panel.getBoundingClientRect().height;
  const move = e => { panel.style.height = `${Math.max(180, Math.min(window.innerHeight - 60, startHeight + startY - e.clientY))}px`; };
  const up = () => { window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up); savePreferences(); };
  window.addEventListener('pointermove', move); window.addEventListener('pointerup', up);
});

function updateAutoSyncButton() {
  const button = root.querySelector('[data-action="auto-sync"]');
  button.classList.toggle('is-active', state.prefs.autoSync !== false);
  button.textContent = state.prefs.autoSync !== false ? 'Auto-sync ✓' : 'Auto-sync';
  button.setAttribute('aria-pressed', state.prefs.autoSync !== false ? 'true' : 'false');
}

(async function init() {
  try {
    state.prefs = { ...state.prefs, ...(await api('/preferences')) };
    state.scope = state.prefs.scope === 'document' && state.postId ? 'document' : 'global';
    panel.style.height = `${state.prefs.height || 360}px`;
    panel.classList.toggle('is-minimized', state.prefs.open === false);
    updateAutoSyncButton();
    root.querySelectorAll('[data-scope]').forEach(el => el.classList.toggle('is-active', el.dataset.scope === state.scope));
    updateActions();
    await loadWorkspace();
  } catch (error) { showError(error); }
})();
