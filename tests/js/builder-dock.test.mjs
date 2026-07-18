import assert from 'node:assert/strict';
import test from 'node:test';
import { bottomDockOffset } from '../../assets/src/builder-dock.mjs';

test('uses the visible height of a toolbar attached to the viewport bottom', () => {
  assert.equal(bottomDockOffset({ top: 852, bottom: 900, width: 1440, height: 48 }, 900), 48);
});

test('does not offset for hidden, top, or side toolbars', () => {
  assert.equal(bottomDockOffset({ top: 0, bottom: 48, width: 1440, height: 48 }, 900), 0);
  assert.equal(bottomDockOffset({ top: 0, bottom: 900, width: 48, height: 900 }, 900), 0);
  assert.equal(bottomDockOffset({ top: 852, bottom: 900, width: 1440, height: 48 }, 900, false), 0);
});
