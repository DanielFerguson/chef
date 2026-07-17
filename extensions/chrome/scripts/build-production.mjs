import { copyFile, mkdir, readFile, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const output = resolve(root, 'dist');
const args = Object.fromEntries(
  process.argv.slice(2).map((argument) => {
    const [key, ...value] = argument.replace(/^--/, '').split('=');
    return [key, value.join('=')];
  }),
);

const origin = validateOrigin(args.origin);
const version = validateVersion(args.version);
const manifest = JSON.parse(await readFile(resolve(root, 'manifest.json'), 'utf8'));
manifest.version = version;
manifest.host_permissions = [
  'https://www.woolworths.com.au/*',
  'https://www.coles.com.au/*',
  `${origin}/*`,
];

await mkdir(output, { recursive: true });
await writeFile(resolve(output, 'manifest.json'), `${JSON.stringify(manifest, null, 2)}\n`);

for (const file of ['background.js', 'policy.js', 'popup.css', 'popup.js']) {
  await copyFile(resolve(root, file), resolve(output, file));
}

const popup = (await readFile(resolve(root, 'popup.html'), 'utf8'))
  .replace('value="http://localhost:8000"', `value="${origin}"`);
await writeFile(resolve(output, 'popup.html'), popup);

console.log(`Built Chef extension ${version} for ${origin} in ${output}`);

function validateOrigin(value) {
  let url;

  try {
    url = new URL(value);
  } catch {
    throw new Error('Pass an exact production origin with --origin=https://host.');
  }

  if (
    url.protocol !== 'https:' ||
    url.origin !== value ||
    url.port ||
    url.username ||
    url.password ||
    /(^|\.)(localhost|example|invalid|test)$/i.test(url.hostname)
  ) {
    throw new Error('The production extension origin must be an exact non-placeholder HTTPS origin.');
  }

  return url.origin;
}

function validateVersion(value) {
  if (!/^\d+\.\d+\.\d+$/.test(value ?? '')) {
    throw new Error('Pass a numeric extension version with --version=x.y.z.');
  }

  return value;
}
