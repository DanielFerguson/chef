import { createHash } from 'node:crypto';
import { readFile, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';

const providerPath = resolve(
    'node_modules/@browserbasehq/stagehand/dist/esm/lib/v3/llm/LLMProvider.js',
);
const expectedChecksum =
    '08e29dda2af2c9115df6eeaa03ee3c679d0d4d0b9fb33f4bdb062d511a6e25b2';
const optionalProviders = [
    ['@ai-sdk/amazon-bedrock', 'bedrock', 'createAmazonBedrock'],
    ['@ai-sdk/google-vertex', 'vertex', 'createVertex'],
    ['@ai-sdk/anthropic', 'anthropic', 'createAnthropic'],
    ['@ai-sdk/google', 'google', 'createGoogleGenerativeAI'],
    ['@ai-sdk/xai', 'xai', 'createXai'],
    ['@ai-sdk/azure', 'azure', 'createAzure'],
    ['@ai-sdk/groq', 'groq', 'createGroq'],
    ['@ai-sdk/cerebras', 'cerebras', 'createCerebras'],
    ['@ai-sdk/togetherai', 'togetherai', 'createTogetherAI'],
    ['@ai-sdk/mistral', 'mistral', 'createMistral'],
    ['@ai-sdk/deepseek', 'deepseek', 'createDeepSeek'],
    ['@ai-sdk/perplexity', 'perplexity', 'createPerplexity'],
    ['ollama-ai-provider-v2', 'ollama', 'createOllama'],
];

let source = await readFile(providerPath, 'utf8');
const checksum = createHash('sha256').update(source).digest('hex');

if (checksum !== expectedChecksum) {
    throw new Error(
        `Stagehand LLMProvider checksum changed (${checksum}); review the production-runtime preparation before deployment.`,
    );
}

for (const [packageName, provider, creator] of optionalProviders) {
    const escapedPackage = packageName.replaceAll('/', '\\/');
    source = source.replace(
        new RegExp(
            `^import \\{ [^\\n]+ \\} from "${escapedPackage}";\\n`,
            'm',
        ),
        '',
    );
    source = source.replace(new RegExp(`^    ${provider},\\n`, 'gm'), '');
    source = source.replace(new RegExp(`^    ${provider}: ${creator},\\n`, 'gm'), '');
}

for (const [packageName] of optionalProviders) {
    if (source.includes(`from "${packageName}"`)) {
        throw new Error(`Failed to remove optional Stagehand provider ${packageName}.`);
    }
}

await writeFile(providerPath, source);
