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

const workflowCount = (html.match(/data-hero-workflow(?:\s|>)/g) ?? []).length;
const workflowControls = (html.match(/data-workflow-control(?:\s|>)/g) ?? [])
  .length;
const workflowPanels = (html.match(/data-workflow-panel(?:\s|>)/g) ?? [])
  .length;

if (workflowCount !== 1) {
  throw new Error(
    `Expected one hero workflow container; found ${workflowCount}.`,
  );
}

if (workflowControls !== 4 || workflowPanels !== 4) {
  throw new Error(
    `Expected four hero workflow controls and panels; found ${workflowControls} controls and ${workflowPanels} panels.`,
  );
}

if (!html.includes('Checkout stays yours')) {
  throw new Error('Hero workflow is missing the human checkout boundary.');
}

for (const marker of [
  'Thinking through your week…',
  'data-approved-label="Approved"',
  'data-rejected-label="Rejected"',
  'data-replaced-label="Replaced"',
  'Mushroom risotto',
  'Quick beef rice bowls',
  'Matching meal ingredients',
  '18 meal ingredients matched',
  'One substitution needs you',
  'data-workflow-timer',
  'data-plan-context-list',
  'data-cooking-current',
  'data-cooking-strike',
  'Turn the chicken through the pan sauce',
  'Divide between bowls, spoon over the pan sauce',
]) {
  if (!html.includes(marker)) {
    throw new Error(`Hero workflow is missing required state: ${marker}`);
  }
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
