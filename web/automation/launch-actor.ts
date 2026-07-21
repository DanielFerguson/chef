import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const actorPath =
    process.env.CHEF_BROWSER_ACTOR_ENTRYPOINT ??
    fileURLToPath(new URL('./actor.js', import.meta.url));
const actorEnvironment = Object.fromEntries(
    [
        'CHEF_BROWSER_ACTOR_SOCKET',
        'CHEF_BROWSER_CDP_URL',
        'CHEF_BROWSER_ACTOR_ID',
        'CHEF_BROWSER_FENCING_TOKEN',
        'CHEF_BROWSER_ACTOR_GENERATION',
        'CHEF_BROWSER_ACTOR_EXPIRES_AT',
        'LANG',
        'TZ',
        'TMPDIR',
    ]
        .map((name) => [name, process.env[name]])
        .filter((entry): entry is [string, string] => entry[1] !== undefined),
);
const child = spawn(process.execPath, [actorPath], {
    detached: true,
    env: actorEnvironment,
    stdio: 'ignore',
});

child.once('error', () => process.exit(1));
child.once('spawn', () => {
    child.unref();
    process.stdout.write(`${JSON.stringify({ pid: child.pid })}\n`);
});
