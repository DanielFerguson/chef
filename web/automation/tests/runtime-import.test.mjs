import assert from 'node:assert/strict';
import test from 'node:test';

import { Browserbase } from '@browserbasehq/sdk';
import { Stagehand } from '@browserbasehq/stagehand';

test('loads the production Browserbase and Stagehand runtime without optional providers', () => {
    assert.equal(typeof Browserbase, 'function');
    assert.equal(typeof Stagehand, 'function');
});
