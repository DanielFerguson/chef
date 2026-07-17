import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import { validateProductionConfig } from '../scripts/production-config.mjs';

const valid = {
  SITE_URL: 'https://www.cheffamily.com',
  PUBLIC_APP_URL: 'https://app.cheffamily.com/start',
  PUBLIC_LOGIN_URL: 'https://app.cheffamily.com/login',
};

test('accepts the exact production marketing and application contract', () => {
  const result = validateProductionConfig(valid);

  assert.equal(result.siteUrl.origin, 'https://www.cheffamily.com');
  assert.equal(result.appUrl.pathname, '/start');
  assert.equal(result.loginUrl.pathname, '/login');
});

describe('rejects unsafe or placeholder publication configuration', () => {
  for (const [name, environment, message] of [
    ['missing site', { ...valid, SITE_URL: undefined }, 'SITE_URL'],
    [
      'placeholder site',
      { ...valid, SITE_URL: 'https://chef.example' },
      'SITE_URL',
    ],
    [
      'reserved example.com site',
      { ...valid, SITE_URL: 'https://www.example.com' },
      'SITE_URL',
    ],
    [
      'active app scheme',
      { ...valid, PUBLIC_APP_URL: 'javascript:alert(1)' },
      'PUBLIC_APP_URL',
    ],
    [
      'cross-origin application links',
      { ...valid, PUBLIC_APP_URL: 'https://other.cheffamily.com/start' },
      'share one origin',
    ],
    [
      'wrong start path',
      { ...valid, PUBLIC_APP_URL: 'https://app.cheffamily.com/register' },
      'must end in /start',
    ],
    [
      'embedded credentials',
      {
        ...valid,
        PUBLIC_LOGIN_URL: 'https://user:secret@app.cheffamily.com/login',
      },
      'PUBLIC_LOGIN_URL',
    ],
  ]) {
    test(name, () => {
      assert.throws(
        () => validateProductionConfig(environment),
        (error) => error instanceof Error && error.message.includes(message),
      );
    });
  }
});
