import { mkdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { env } from '../config/env.js';
function dataDir() {
    return path.resolve(env.morningDataDir);
}
function storePath() {
    return path.join(dataDir(), 'morning-integration.json');
}
async function ensureDir() {
    await mkdir(dataDir(), { recursive: true });
}
async function readBundle() {
    try {
        const raw = await readFile(storePath(), 'utf8');
        const parsed = JSON.parse(raw);
        return parsed && typeof parsed === 'object' ? parsed : {};
    }
    catch {
        return {};
    }
}
async function writeBundle(next) {
    await ensureDir();
    const payload = {
        ...next,
        updatedAt: new Date().toISOString(),
    };
    await writeFile(storePath(), `${JSON.stringify(payload, null, 2)}\n`, 'utf8');
}
function normalizeEnv(raw) {
    return raw === 'sandbox' ? 'sandbox' : 'production';
}
export async function readLocalCredentials() {
    const bundle = await readBundle();
    const c = bundle.credentials;
    if (!c)
        return null;
    const keyId = String(c.keyId ?? '').trim();
    const keySecret = String(c.keySecret ?? '').trim();
    if (!keyId || !keySecret)
        return null;
    return {
        keyId,
        keySecret,
        env: normalizeEnv(c.env),
    };
}
export async function writeLocalCredentials(creds) {
    const bundle = await readBundle();
    await writeBundle({
        ...bundle,
        credentials: {
            keyId: creds.keyId,
            keySecret: creds.keySecret,
            env: normalizeEnv(creds.env),
        },
    });
}
export async function readLocalSyncState() {
    const bundle = await readBundle();
    const state = bundle.syncState;
    return state && typeof state === 'object' ? state : null;
}
export async function writeLocalSyncState(state) {
    const bundle = await readBundle();
    await writeBundle({
        ...bundle,
        syncState: state,
    });
}
//# sourceMappingURL=morningLocalStore.js.map