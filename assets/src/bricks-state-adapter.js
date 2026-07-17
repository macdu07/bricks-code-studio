function clone(value) {
  if (typeof structuredClone === 'function') return structuredClone(value);
  return JSON.parse(JSON.stringify(value));
}

/**
 * Refresh the Bricks 2.4 builder's reactive design data after an external REST
 * write. Keep every internal access in this version-gated adapter so a future
 * Bricks change degrades to the normal reload button instead of breaking saves.
 */
export function refreshBricksDesignState(designState, bricksVersion = '') {
  if (!designState || !/^2\.4(?:\.|-|$)/.test(String(bricksVersion))) {
    return { refreshed: false, reason: 'unsupported-version' };
  }

  const host = document.querySelector('.brx-body');
  const app = host?.__vue_app__ || host?._vnode?.appContext?.app;
  const globals = app?.config?.globalProperties;
  const builderState = globals?.$_state;
  if (!builderState || !Array.isArray(builderState.globalClasses)) {
    return { refreshed: false, reason: 'builder-state-unavailable' };
  }

  const classes = Array.isArray(designState.globalClasses) ? clone(designState.globalClasses) : null;
  const variables = Array.isArray(designState.globalVariables) ? clone(designState.globalVariables) : null;
  if (!classes) return { refreshed: false, reason: 'invalid-design-state' };

  const activeClassId = builderState.activeClass?.id || '';
  builderState.isBroadcasting = true;
  builderState.globalClasses = classes;
  builderState.globalClassIndexById = Object.fromEntries(classes.map((item, index) => [item.id, index]));
  if (variables) builderState.globalVariables = variables;
  if (designState.globalClassesTimestamp) builderState.globalClassesTimestamp = designState.globalClassesTimestamp;

  if (activeClassId) {
    const refreshedClass = classes.find(item => item.id === activeClassId);
    builderState.activeClass = refreshedClass ? clone(refreshedClass) : false;
  }

  if (window.bricksData?.loadData) {
    window.bricksData.loadData.globalClasses = clone(classes);
    if (variables) window.bricksData.loadData.globalVariables = clone(variables);
    if (designState.globalClassesTimestamp) window.bricksData.loadData.globalClassesTimestamp = designState.globalClassesTimestamp;
  }

  if (Array.isArray(builderState.unsavedChanges)) {
    builderState.unsavedChanges = builderState.unsavedChanges.filter(key => !['globalClasses', 'globalVariables'].includes(key));
  }
  builderState.rerenderClassNames = Date.now();
  builderState.forceRender = `${Date.now()}:${builderState.activeId || ''}`;
  if (typeof globals.$_rerenderControls === 'function') globals.$_rerenderControls();

  Promise.resolve().then(() => { builderState.isBroadcasting = false; });
  return { refreshed: true, activeClassId };
}

/**
 * Replace the active Bricks area after Code Studio persisted an HTML structure.
 * The server remains authoritative; this only keeps the open 2.4 builder reactive
 * state aligned so the user does not have to reload after every HTML save.
 */
export function refreshBricksStructureState(elements, bricksVersion = '') {
  if (!Array.isArray(elements) || !/^2\.4(?:\.|-|$)/.test(String(bricksVersion))) {
    return { refreshed: false, reason: 'unsupported-version' };
  }
  const host = document.querySelector('.brx-body');
  const app = host?.__vue_app__ || host?._vnode?.appContext?.app;
  const globals = app?.config?.globalProperties;
  const builderState = globals?.$_state;
  if (!builderState) return { refreshed: false, reason: 'builder-state-unavailable' };

  const dynamicArea = globals?.$_dynamicArea;
  const area = typeof dynamicArea === 'string' ? dynamicArea : dynamicArea?.value;
  const targetArea = ['header', 'content', 'footer'].includes(area) ? area : 'content';
  const nextElements = clone(elements);
  const activeId = builderState.activeId || '';
  builderState.isBroadcasting = true;
  builderState[targetArea] = nextElements;
  builderState.activeElement = activeId ? clone(nextElements.find(element => element.id === activeId) || null) : null;
  if (!builderState.activeElement) builderState.activeId = null;
  if (Array.isArray(builderState.unsavedChanges)) {
    builderState.unsavedChanges = builderState.unsavedChanges.filter(key => key !== targetArea);
  }
  if (window.bricksData?.loadData) window.bricksData.loadData[targetArea] = clone(nextElements);
  builderState.rerenderElementIds = nextElements.map(element => element.id).filter(Boolean);
  builderState.forceRender = `${Date.now()}:structure`;
  if (typeof globals.$_rerenderControls === 'function') globals.$_rerenderControls();
  Promise.resolve().then(() => { builderState.isBroadcasting = false; });
  return { refreshed: true, area: targetArea };
}
