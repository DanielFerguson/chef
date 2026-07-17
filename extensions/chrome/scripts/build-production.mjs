import { copyFile, mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { validateOrigin, validateVersion } from './release-config.mjs';

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
manifest.homepage_url = origin;
manifest.host_permissions = [
  'https://www.woolworths.com.au/*',
  'https://www.coles.com.au/*',
  `${origin}/*`,
];

await rm(output, { recursive: true, force: true });
await mkdir(output, { recursive: true });
await writeFile(resolve(output, 'manifest.json'), `${JSON.stringify(manifest, null, 2)}\n`);

for (const file of ['background.js', 'policy.js', 'popup.css', 'popup.html', 'popup.js']) {
  await copyFile(resolve(root, file), resolve(output, file));
}

console.log(`Built Chef extension ${version} for ${origin} in ${output}`);
