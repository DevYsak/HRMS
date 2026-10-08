import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

/**
 * Shared settings for the role-journey E2E suite (playwright.e2e.config.js).
 *
 * The app under test is a local PHP server pointed at a throwaway database
 * whose name must end in "_e2e". Mail is captured (array), queues run inline,
 * and the cache is per request, so nothing leaves the machine and nothing is
 * shared with the development database or its cache.
 */
export const E2E_PORT = Number(process.env.E2E_PORT || 8010);
export const BASE_URL = `http://127.0.0.1:${E2E_PORT}`;
export const E2E_DATABASE = process.env.E2E_DB_DATABASE || 'hrms_e2e';
export const MANIFEST = path.resolve('storage/framework/testing/e2e-accounts.json');

export const E2E_ENV = {
    APP_ENV: 'local',
    APP_URL: BASE_URL,
    DB_DATABASE: E2E_DATABASE,
    MAIL_MAILER: 'array',
    QUEUE_CONNECTION: 'sync',
    CACHE_STORE: 'array',
    BROADCAST_CONNECTION: 'null',
    PULSE_ENABLED: 'false',
    TELESCOPE_ENABLED: 'false',
    NIGHTWATCH_ENABLED: 'false',
};

if (!E2E_DATABASE.endsWith('_e2e')) {
    throw new Error(`E2E refused: database '${E2E_DATABASE}' is not an E2E database (its name must end in _e2e).`);
}

/** Run an artisan command against the E2E database. */
export function artisan(args, options = {}) {
    return execFileSync('php', ['artisan', ...args], {
        env: { ...process.env, ...E2E_ENV },
        stdio: options.stdio ?? 'pipe',
        encoding: 'utf8',
        timeout: options.timeout ?? 15 * 60 * 1000,
    });
}

/** Accounts and ids written by `php artisan e2e:seed-roles --manifest=…`. */
export function manifest() {
    return JSON.parse(fs.readFileSync(MANIFEST, 'utf8'));
}
