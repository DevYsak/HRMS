import { defineConfig, devices } from '@playwright/test';
import { BASE_URL, E2E_ENV, E2E_PORT } from './tests/Playwright/e2e/env.js';

/**
 * Role-journey E2E suite (tests/Playwright/e2e): one fictional account per
 * role, logged in through the real UI, on a throwaway local database
 * (*_e2e, rebuilt by global-setup). Run with `npm run e2e`.
 *
 * Desktop runs every spec; the phone and tablet projects run the responsive
 * smoke spec only.
 */
export default defineConfig({
    testDir: './tests/Playwright/e2e',
    timeout: 4 * 60 * 1000,
    expect: { timeout: 20 * 1000 },
    workers: 1,
    fullyParallel: false,
    retries: 0,
    reporter: [['list'], ['json', { outputFile: 'storage/logs/e2e-results.json' }]],
    outputDir: 'storage/logs/e2e-artifacts',
    globalSetup: './tests/Playwright/e2e/global-setup.js',
    use: {
        baseURL: BASE_URL,
        locale: 'en-GB',
        timezoneId: 'Asia/Kolkata',
        colorScheme: 'light',
        navigationTimeout: 60 * 1000,
        actionTimeout: 20 * 1000,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        { name: 'desktop', use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } } },
        { name: 'tablet', testMatch: /responsive\.spec\.js/, use: { ...devices['Desktop Chrome'], viewport: { width: 820, height: 1180 }, hasTouch: true } },
        { name: 'phone', testMatch: /responsive\.spec\.js/, use: { ...devices['Pixel 7'] } },
    ],
    webServer: {
        // The PHP built-in server with Laravel's router script; the E2E env
        // (database, mail, cache) overrides .env for this process only.
        // Started from public/, as `artisan serve` does: the router script
        // looks for index.php in the working directory.
        command: `php -S 127.0.0.1:${E2E_PORT} ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`,
        cwd: 'public',
        url: `${BASE_URL}/up`,
        reuseExistingServer: false,
        timeout: 2 * 60 * 1000,
        env: { ...process.env, ...E2E_ENV },
        stdout: 'ignore',
        stderr: 'pipe',
    },
});
