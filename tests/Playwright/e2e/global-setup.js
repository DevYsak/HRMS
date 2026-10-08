import { BASE_URL, E2E_DATABASE, MANIFEST, artisan } from './env.js';

const LOCAL_HOSTS = ['127.0.0.1', 'localhost', '::1'];

/**
 * Prepares the throwaway E2E database, then seeds the fictional role accounts.
 *
 *  - refuses anything but a local app and a database named *_e2e;
 *  - rebuilds the schema with migrate:fresh (E2E_REUSE_DB=1 keeps it and only
 *    re-creates the accounts), then seeds lookup data only — never the
 *    seeders that load real employees;
 *  - writes the account manifest the specs read.
 */
export default async function globalSetup() {
    const host = new URL(BASE_URL).hostname;
    if (!LOCAL_HOSTS.includes(host)) {
        throw new Error(`E2E refused: ${BASE_URL} is not a local app.`);
    }

    const env = artisan(['env']).trim();
    if (!/\b(local|testing)\b/.test(env)) {
        throw new Error(`E2E refused: the application environment is not local/testing (${env}).`);
    }

    const reuse = process.env.E2E_REUSE_DB === '1';
    if (!reuse) {
        console.log(`[e2e] migrate:fresh on ${E2E_DATABASE} …`);
        artisan(['migrate:fresh', '--force', '--no-interaction'], { stdio: 'inherit' });
    }

    console.log('[e2e] seeding role accounts …');
    artisan([
        'e2e:seed-roles',
        `--manifest=${MANIFEST}`,
        ...(reuse ? [] : ['--with-lookups']),
        ...(process.env.E2E_PASSWORD ? [`--password=${process.env.E2E_PASSWORD}`] : []),
    ], { stdio: 'inherit' });

    const response = await fetch(new URL('/login', BASE_URL));
    if (!response.ok) {
        throw new Error(`E2E app at ${BASE_URL} answered ${response.status} on /login.`);
    }
}
