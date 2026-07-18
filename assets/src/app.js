import { Decoration, EditorView, keymap, lineNumbers, ViewPlugin, WidgetType } from '@codemirror/view';
import { EditorState } from '@codemirror/state';
import { defaultKeymap, history, historyKeymap } from '@codemirror/commands';
import { autocompletion, closeBrackets, closeBracketsKeymap, completionKeymap } from '@codemirror/autocomplete';
import { indentRange } from '@codemirror/language';
import { css, cssLanguage } from '@codemirror/lang-css';
import { html, htmlLanguage } from '@codemirror/lang-html';
import { javascript, javascriptLanguage } from '@codemirror/lang-javascript';
import { oneDark } from '@codemirror/theme-one-dark';
import { refreshBricksDesignState, refreshBricksStructureState } from './bricks-state-adapter.js';
import { extensionForFolder, resolveNewFilePath, tabForPath } from './workspace-paths.mjs';

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
      <nav class="bcs-tabs" role="tablist"><button role="tab" data-tab="scss">SCSS</button><button role="tab" data-tab="css">CSS</button><button role="tab" data-tab="js">JS</button><button role="tab" data-tab="html">HTML</button></nav>
      <div class="bcs-actions">
        <button data-action="new" title="New file">＋</button>
        <button data-action="rename" title="Rename">Rename</button>
        <button data-action="delete" title="Delete">Delete</button>
        <button data-action="format" title="Format document · Shift/Option+F">Format</button>
        <button data-action="auto-sync" title="Synchronize compatible CSS resources with Bricks whenever a style file is saved">Auto-sync</button>
        <button data-action="design" title="Sync compatible classes and variables">Sync Bricks</button>
        <button data-action="preview">Preview</button>
        <button data-action="run">Run JS</button>
        <button class="bcs-primary" data-action="save">Save</button>
        <button data-action="undo" hidden>Undo</button>
        <button data-action="fullscreen" title="Fullscreen">⛶</button>
        <button data-action="reload" title="Reload Bricks to refresh visual controls" hidden>↻ Bricks</button>
        <button data-action="minimize" title="Minimize">—</button>
      </div>
    </header>
    <div class="bcs-body">
      <aside class="bcs-diagnostics"><div class="bcs-diagnostics-title">Diagnostics</div><div class="bcs-diagnostics-list">Ready.</div></aside>
      <main class="bcs-editor-area"><div class="bcs-breadcrumb">Choose a file</div><div class="bcs-editor"></div></main>
      <aside class="bcs-files"><div class="bcs-files-title">Workspace</div><div class="bcs-file-list"></div></aside>
    </div>
    <footer class="bcs-status"><span data-status>Ready</span><span data-meta>Autocomplete: Ctrl/⌘ Space · Format: Shift/Option+F · Bricks Code Studio ${boot.version}</span></footer>
    <div class="bcs-context-menu" role="menu" hidden></div>
  </section>`;

const panel = root.querySelector('.bcs-panel');
const fileList = root.querySelector('.bcs-file-list');
const editorHost = root.querySelector('.bcs-editor');
const breadcrumb = root.querySelector('.bcs-breadcrumb');
const diagnosticsList = root.querySelector('.bcs-diagnostics-list');
const statusNode = root.querySelector('[data-status]');
const contextMenu = root.querySelector('.bcs-context-menu');

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
  if (path === 'structure.html') return html({ autoCloseTags: true, selfClosingTags: true });
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

const colorLiteralPattern = /#(?:[\da-fA-F]{3,4}|[\da-fA-F]{6}|[\da-fA-F]{8})\b|(?:rgb|hsl)a?\([^\n)]*\)/g;

function colorToHex(value) {
  const probe = document.createElement('span');
  probe.style.color = value;
  probe.style.position = 'fixed';
  probe.style.pointerEvents = 'none';
  probe.style.opacity = '0';
  document.body.appendChild(probe);
  const channels = getComputedStyle(probe).color.match(/[\d.]+/g)?.slice(0, 3).map(Number) || [];
  probe.remove();
  if (channels.length !== 3) return '#000000';
  return `#${channels.map(channel => Math.max(0, Math.min(255, Math.round(channel))).toString(16).padStart(2, '0')).join('')}`;
}

function replacementColorValue(original, chosenHex) {
  const shortHexAlpha = original.match(/^#[\da-f]{3}([\da-f])$/i)?.[1];
  if (shortHexAlpha) return `${chosenHex}${shortHexAlpha}${shortHexAlpha}`;
  const hexAlpha = original.match(/^#[\da-f]{6}([\da-f]{2})$/i)?.[1];
  if (hexAlpha) return `${chosenHex}${hexAlpha}`;
  const functionalAlpha = original.match(/\/\s*([^)]+)\)$/)?.[1]?.trim()
    || (/^(rgba|hsla)\(/i.test(original) ? original.match(/,\s*([^,()]+)\s*\)$/)?.[1]?.trim() : '');
  if (!functionalAlpha) return chosenHex;
  const channels = chosenHex.match(/[\da-f]{2}/gi).map(channel => parseInt(channel, 16));
  return `rgb(${channels.join(' ')} / ${functionalAlpha})`;
}

class ColorSwatchWidget extends WidgetType {
  constructor(value, from, to) { super(); this.value = value; this.from = from; this.to = to; }
  eq(other) { return other.value === this.value && other.from === this.from && other.to === this.to; }
  toDOM(view) {
    const wrapper = document.createElement('span');
    wrapper.className = 'bcs-color-swatch';
    wrapper.style.setProperty('--bcs-swatch-color', this.value);
    wrapper.title = `Edit color ${this.value}`;
    wrapper.setAttribute('aria-label', `Edit color ${this.value}`);
    wrapper.setAttribute('role', 'button');
    wrapper.tabIndex = 0;
    const input = document.createElement('input');
    input.type = 'color';
    input.value = colorToHex(this.value);
    input.tabIndex = -1;
    const openPicker = event => { event.preventDefault(); event.stopPropagation(); input.click(); };
    wrapper.addEventListener('mousedown', event => event.preventDefault());
    wrapper.addEventListener('click', openPicker);
    wrapper.addEventListener('keydown', event => { if (event.key === 'Enter' || event.key === ' ') openPicker(event); });
    input.addEventListener('click', event => event.stopPropagation());
    input.addEventListener('change', event => {
      event.stopPropagation();
      if (view.state.doc.sliceString(this.from, this.to) !== this.value) return;
      const replacement = replacementColorValue(this.value, input.value);
      view.dispatch({ changes: { from: this.from, to: this.to, insert: replacement }, selection: { anchor: this.from + replacement.length } });
      view.focus();
    });
    wrapper.appendChild(input);
    return wrapper;
  }
  ignoreEvent() { return true; }
}

function buildColorSwatches(view) {
  const ranges = [];
  view.visibleRanges.forEach(({ from, to }) => {
    const source = view.state.doc.sliceString(from, to);
    colorLiteralPattern.lastIndex = 0;
    let match;
    while ((match = colorLiteralPattern.exec(source))) {
      const value = match[0];
      if (!CSS.supports('color', value)) continue;
      const start = from + match.index;
      const end = start + value.length;
      ranges.push(Decoration.widget({ widget: new ColorSwatchWidget(value, start, end), side: 1 }).range(end));
    }
  });
  return Decoration.set(ranges, true);
}

const colorSwatches = ViewPlugin.fromClass(class {
  constructor(view) { this.decorations = buildColorSwatches(view); }
  update(update) {
    if (update.docChanged || update.viewportChanged) this.decorations = buildColorSwatches(update.view);
  }
}, { decorations: plugin => plugin.decorations });

function mountEditor(content = '', readOnly = false) {
  if (state.editor) state.editor.destroy();
  const extensions = [lineNumbers(), history(), closeBrackets(), autocompletion({ activateOnTyping: true, activateOnTypingDelay: 120, maxRenderedOptions: 60 }), keymap.of([{ key: 'Shift-Alt-f', run: formatDocument }, ...closeBracketsKeymap, ...completionKeymap, ...defaultKeymap, ...historyKeymap]), language(state.activePath), completionLanguageData(state.activePath), oneDark, EditorView.editable.of(!readOnly)];
  if (/\.(scss|css)$/i.test(state.activePath)) extensions.push(colorSwatches);
  extensions.push(EditorView.updateListener.of(update => {
    if (!update.docChanged || readOnly) return;
    state.dirty = true;
    setStatus('Unsaved changes', 'dirty');
    if (state.activePath.endsWith('.js')) validateJavascript(update.state.doc.toString());
    else schedulePreview();
  }));
  state.editor = new EditorView({
    state: EditorState.create({
      doc: content,
      extensions,
    }),
    parent: editorHost,
  });
}

function currentContent() { return state.editor ? state.editor.state.doc.toString() : ''; }

function formatDocument(view = state.editor) {
  if (!view || state.activePath === 'compiled.css') return false;
  const changes = indentRange(view.state, 0, view.state.doc.length);
  if (changes.empty) {
    setStatus('Document is already formatted', 'success');
    return true;
  }
  view.dispatch({ changes, scrollIntoView: true });
  setStatus('Document formatted · save to publish', 'dirty');
  return true;
}

function renderFiles() {
  fileList.replaceChildren();
  const tree = {
    scss: { children: {}, file: null },
    css: { children: {}, file: null },
    js: { children: {}, file: null },
  };
  state.files.forEach(file => {
    let branch = tree;
    const parts = file.path.split('/');
    parts.forEach((part, index) => {
      branch[part] ||= { children: {}, file: null };
      if (index === parts.length - 1) branch[part].file = file;
      branch = branch[part].children;
    });
  });
  const rootOrder = { scss: 0, css: 1, js: 2 };
  const renderBranch = (branch, host, parentPath = '') => Object.keys(branch).sort((a, b) => {
    if (!parentPath && (rootOrder[a] !== undefined || rootOrder[b] !== undefined)) return (rootOrder[a] ?? 99) - (rootOrder[b] ?? 99);
    return a.localeCompare(b, undefined, { numeric: true });
  }).forEach(name => {
    const node = branch[name];
    const nodePath = parentPath ? `${parentPath}/${name}` : name;
    if (Object.keys(node.children).length || (!parentPath && rootOrder[name] !== undefined)) {
      const folder = document.createElement('details');
      folder.className = 'bcs-folder';
      folder.dataset.path = nodePath;
      folder.open = true;
      const label = document.createElement('summary');
      const folderName = document.createElement('span');
      folderName.textContent = name;
      const add = document.createElement('button');
      add.type = 'button';
      add.className = 'bcs-folder-add';
      add.textContent = '＋';
      add.title = `New ${extensionForFolder(nodePath).toUpperCase()} file in ${nodePath}`;
      add.setAttribute('aria-label', add.title);
      add.addEventListener('click', event => { event.preventDefault(); event.stopPropagation(); void createFile(nodePath); });
      label.append(folderName, add);
      label.addEventListener('contextmenu', event => { event.preventDefault(); showFolderMenu(nodePath, event.clientX, event.clientY); });
      folder.appendChild(label);
      const children = document.createElement('div');
      renderBranch(node.children, children, nodePath);
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
      button.addEventListener('contextmenu', event => { event.preventDefault(); showFileMenu(node.file.path, event.clientX, event.clientY); });
      host.appendChild(button);
    }
  });
  renderBranch(tree, fileList);
}

async function loadCompiledOutput() {
  try {
    const published = await api('/compiled', 'GET', params());
    if (published.available) {
      state.compiledCss = published.css || '';
      return;
    }
    const compiled = await api('/compile', 'POST', params({ publish: false }));
    if (!(compiled.diagnostics || []).some(item => item.severity === 'error')) state.compiledCss = compiled.css || '';
  } catch (error) {
    console.warn('[Bricks Code Studio] Could not restore compiled CSS view', error);
  }
}

async function loadWorkspace(preferred = '', { loadCompiled = true } = {}) {
  setStatus('Loading workspace…');
  const data = await api('/workspace', 'GET', params());
  state.files = data.files || [];
  state.manifest = data.manifest || { entries: [] };
  if (loadCompiled) await loadCompiledOutput();
  await refreshCompletionIndex();
  renderFiles();
  const target = preferred || state.prefs.activeFile || state.files[0]?.path;
  if (target === 'compiled.css') openCompiledCss(false);
  else if (target === 'structure.html' && state.postId) await openStructure();
  else if (target && state.files.some(file => file.path === target)) await openFile(target);
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
  savePreferences();
}

function openCompiledCss(persist = true) {
  state.activePath = 'compiled.css';
  state.dirty = false;
  breadcrumb.textContent = 'Compiled preview';
  mountEditor(state.compiledCss || '/* No published CSS build yet. Save a CSS or SCSS entry to generate it. */', true);
  updateActions();
  if (persist) savePreferences();
}

function updateActions() {
  const isHtml = state.activePath === 'structure.html';
  const isCompiled = state.activePath === 'compiled.css';
  const isJs = state.activePath.endsWith('.js');
  root.querySelector('[data-action="save"]').hidden = isCompiled;
  root.querySelector('[data-action="rename"]').hidden = isHtml || isCompiled;
  root.querySelector('[data-action="delete"]').hidden = isHtml || isCompiled;
  root.querySelector('[data-action="format"]').hidden = isCompiled;
  root.querySelector('[data-action="run"]').hidden = !isJs;
  root.querySelector('[data-action="design"]').hidden = !(state.activePath.endsWith('.scss') || state.activePath.endsWith('.css'));
  root.querySelector('[data-action="preview"]').hidden = isJs;
  updateActiveTab();
}

function updateActiveTab() {
  const activeTab = tabForPath(state.activePath);
  root.querySelectorAll('[data-tab]').forEach(button => {
    const active = button.dataset.tab === activeTab;
    button.classList.toggle('is-active', active);
    button.setAttribute('aria-selected', active ? 'true' : 'false');
  });
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
        : syncResult?.failed
          ? ' · Bricks sync failed'
          : '';
      const finalStatus = result.publishedAssets
        ? { message: `${fromBricksShortcut ? 'Code Studio saved and published · Bricks save requested' : 'Saved and published'}${syncSuffix}`, type: 'success' }
        : { message: 'Source saved; last valid build remains published', type: 'error' };
      await loadWorkspace(state.activePath, { loadCompiled: false });
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

async function saveStructure({ fromBricksShortcut = false } = {}) {
  if (!state.dirty) { setStatus('HTML structure is already saved', 'success'); return; }
  if (state.saveInFlight) return state.saveInFlight;
  state.saveInFlight = (async () => {
    try {
      setStatus(fromBricksShortcut ? 'Saving HTML structure…' : 'Applying and saving structure…');
      const preview = await api('/structure/preview', 'POST', { postId: state.postId, html: currentContent(), treeHash: state.structureHash });
      renderDiagnostics(diffDiagnostics(preview.diff, preview.warnings));
      if (preview.diff?.destructive && !window.confirm(`This HTML save removes ${(preview.diff.removed || []).length} Bricks element(s). Continue? A revision will be created first.`)) {
        setStatus('HTML save cancelled', 'dirty');
        return;
      }
      const result = await api('/structure/apply', 'POST', { postId: state.postId, html: currentContent(), treeHash: state.structureHash, confirmed: true });
      state.lastRevision = result.revisionId;
      state.structureHash = result.treeHash;
      state.dirty = false;
      root.querySelector('[data-action="undo"]').hidden = !state.lastRevision;
      const builderRefresh = refreshBricksStructureState(result.elements || [], boot.bricksVersion);
      postToCanvas('bcs:clear');
      await openStructure();
      setStatus(`HTML saved · revision ${state.lastRevision || 'new'}${builderRefresh.refreshed ? ' · Bricks refreshed' : ' · reloading Bricks…'}`, 'success');
      if (!builderRefresh.refreshed) setTimeout(() => window.location.reload(), 350);
    } catch (error) { showError(error); }
    finally { state.saveInFlight = null; }
  })();
  return state.saveInFlight;
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
    ...linkCandidates.map(item => ({ severity: 'info', message: `${item.type} "${item.name}" already exists in Bricks and will be linked automatically.` })),
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
  renderDiagnostics(preview.warnings || []);
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

function activeFolder() {
  if (!state.activePath || !state.activePath.includes('/') || ['structure.html', 'compiled.css'].includes(state.activePath)) return 'scss';
  return state.activePath.slice(0, state.activePath.lastIndexOf('/'));
}

async function createFile(folderPath = '') {
  const folder = folderPath || activeFolder();
  const extension = extensionForFolder(folder);
  const name = window.prompt(`New ${extension.toUpperCase()} file in ${folder}/:`, `new-file.${extension}`);
  if (!name) return;
  try {
    const path = resolveNewFilePath(folder, name);
    await api('/file', 'PUT', params({ path, content: '', expectedHash: '' }));
    await loadWorkspace(path);
    setStatus(`${path} created`, 'success');
  } catch (error) { showError(error); }
}

async function renameFile(path = state.activePath) {
  if (!path || path === 'structure.html' || path === 'compiled.css') return;
  const to = window.prompt('New path:', path);
  if (!to || to === path) return;
  try { await api('/file/move', 'POST', params({ from: path, to })); await loadWorkspace(to); } catch (error) { showError(error); }
}

async function deleteFile(path = state.activePath) {
  if (!path || path === 'structure.html' || path === 'compiled.css' || !window.confirm(`Delete ${path}?`)) return;
  try { await api('/file', 'DELETE', params({ path })); if (state.activePath === path) state.activePath = ''; await loadWorkspace(); } catch (error) { showError(error); }
}

function hideContextMenu() { contextMenu.hidden = true; contextMenu.replaceChildren(); }

function showContextMenu(items, x, y) {
  contextMenu.replaceChildren();
  items.forEach(item => {
    const button = document.createElement('button');
    button.type = 'button';
    button.role = 'menuitem';
    button.textContent = item.label;
    if (item.danger) button.classList.add('is-danger');
    button.addEventListener('click', () => { hideContextMenu(); void item.run(); });
    contextMenu.appendChild(button);
  });
  contextMenu.hidden = false;
  contextMenu.style.left = `${x}px`;
  contextMenu.style.top = `${y}px`;
  requestAnimationFrame(() => {
    const rect = contextMenu.getBoundingClientRect();
    contextMenu.style.left = `${Math.max(8, Math.min(x, window.innerWidth - rect.width - 8))}px`;
    contextMenu.style.top = `${Math.max(8, Math.min(y, window.innerHeight - rect.height - 8))}px`;
  });
}

function showFolderMenu(path, x, y) {
  const extension = extensionForFolder(path);
  showContextMenu([{ label: `New ${extension.toUpperCase()} file`, run: () => createFile(path) }], x, y);
}

function showFileMenu(path, x, y) {
  const folder = path.includes('/') ? path.slice(0, path.lastIndexOf('/')) : extensionForFolder(path);
  const extension = extensionForFolder(folder);
  showContextMenu([
    { label: `New ${extension.toUpperCase()} file beside this one`, run: () => createFile(folder) },
    { label: 'Rename', run: () => renameFile(path) },
    { label: 'Delete', danger: true, run: () => deleteFile(path) },
  ], x, y);
}

function showNewFileMenu(anchor) {
  const rect = anchor.getBoundingClientRect();
  showContextMenu(['scss', 'css', 'js'].map(type => ({ label: `New ${type.toUpperCase()} file`, run: () => createFile(type) })), rect.left, rect.bottom + 4);
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
    if (tab === 'css') { openCompiledCss(); return; }
    const match = state.files.find(file => file.path.endsWith(tab === 'scss' ? '.scss' : '.js'));
    if (match) return openFile(match.path);
  }
  if (action === 'new') { showNewFileMenu(button); return; }
  const handlers = { rename: renameFile, delete: deleteFile, format: formatDocument, save: state.activePath === 'structure.html' ? saveStructure : saveFile, preview: state.activePath === 'structure.html' ? previewStructure : previewStyles, undo: undoStructure, design: syncDesign };
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

document.addEventListener('pointerdown', event => { if (!contextMenu.hidden && !contextMenu.contains(event.target)) hideContextMenu(); });
window.addEventListener('resize', hideContextMenu);
window.addEventListener('blur', hideContextMenu);

// Run before Bricks' shortcut. Style saves continue alongside Bricks; dirty HTML
// replaces the native save because the server receives the complete new tree.
document.addEventListener('keydown', event => {
  if (!(event.ctrlKey || event.metaKey) || event.altKey || event.key.toLowerCase() !== 's') return;
  if (state.activePath === 'structure.html' && state.dirty) {
    event.preventDefault();
    event.stopImmediatePropagation();
    void saveStructure({ fromBricksShortcut: true });
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
