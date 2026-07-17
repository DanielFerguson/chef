import { access, readFile } from 'node:fs/promises';
import { join } from 'node:path';

const root = new URL('../', import.meta.url).pathname;
const dist = join(root, 'dist');

const requiredFiles = [
  'index.html',
  '404.html',
  'robots.txt',
  'sitemap-index.xml',
  'llms.txt',
  'llms-full.txt',
  'og/chef-home.png',
];

await Promise.all(requiredFiles.map((file) => access(join(dist, file))));

const html = await readFile(join(dist, 'index.html'), 'utf8');
const h1Count = (html.match(/<h1(?:\s|>)/g) ?? []).length;

if (h1Count !== 1) {
  throw new Error(`Expected exactly one homepage H1; found ${h1Count}.`);
}

for (const required of [
  '<link rel="canonical"',
  '<meta name="description"',
  '<meta property="og:image"',
  '<script type="application/ld+json"',
  '/llms.txt',
]) {
  if (!html.includes(required)) {
    throw new Error(`Missing required homepage marker: ${required}`);
  }
}

const schemaMatch = html.match(
  /<script type="application\/ld\+json">([\s\S]*?)<\/script>/,
);

if (!schemaMatch) {
  throw new Error('Homepage JSON-LD could not be found.');
}

const schema = JSON.parse(schemaMatch[1]);
const graphTypes = new Set(
  (schema['@graph'] ?? []).map((entry) => entry['@type']),
);

for (const type of [
  'Organization',
  'WebSite',
  'SoftwareApplication',
  'FAQPage',
]) {
  if (!graphTypes.has(type)) {
    throw new Error(`Homepage JSON-LD is missing ${type}.`);
  }
}

console.log(
  `Static marketing checks passed (${requiredFiles.length} required files, 1 H1, 4 schema entities).`,
);
