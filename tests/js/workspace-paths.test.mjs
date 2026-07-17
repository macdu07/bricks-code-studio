import test from 'node:test';
import assert from 'node:assert/strict';
import { extensionForFolder, resolveNewFilePath } from '../../assets/src/workspace-paths.mjs';

test('detects the file type from root and nested folders', () => {
  assert.equal(extensionForFolder('scss'), 'scss');
  assert.equal(extensionForFolder('scss/components/buttons'), 'scss');
  assert.equal(extensionForFolder('/js/modules/'), 'js');
  assert.equal(extensionForFolder('css'), 'css');
});

test('creates a file in the selected folder and adds its extension', () => {
  assert.equal(resolveNewFilePath('scss', 'buttons'), 'scss/buttons.scss');
  assert.equal(resolveNewFilePath('scss/components', 'card.scss'), 'scss/components/card.scss');
  assert.equal(resolveNewFilePath('js/modules', 'menu'), 'js/modules/menu.js');
});

test('keeps an explicit workspace path without duplicating its folder', () => {
  assert.equal(resolveNewFilePath('scss', 'scss/settings/_colors.scss'), 'scss/settings/_colors.scss');
});

test('rejects an extension that does not match the selected folder', () => {
  assert.throws(() => resolveNewFilePath('js', 'menu.scss'), /must use the \.js extension/);
  assert.throws(() => resolveNewFilePath('scss/components', 'card.js'), /must use the \.scss extension/);
});
