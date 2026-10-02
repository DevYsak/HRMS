import { defineConfig } from '@playwright/test';

/**
 * Playwright is used only to capture the Employee Guide screenshots
 * (tests/Playwright). Run it with `npm run guide:screenshots` against a local
 * app — never production. See tests/Playwright/README.md.
 */
export default defineConfig({
    testDir: './tests/Playwright',
    timeout: 10 * 60 * 1000,
    workers: 1,
    reporter: 'list',
    globalSetup: './tests/Playwright/global-setup.js',
    use: {
        baseURL: process.env.GUIDE_BASE_URL || 'http://127.0.0.1:8000',
        viewport: { width: 1440, height: 900 },
        deviceScaleFactor: 1,
        colorScheme: 'light',
        locale: 'en-GB',
        timezoneId: 'Asia/Kolkata',
    },
});
