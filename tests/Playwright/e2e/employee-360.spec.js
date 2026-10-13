import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { account, expectHealthyPage, login, statusOf } from './helpers.js';

/**
 * Employee 360° (desktop project; sets its own viewports): HR opens any
 * employee, the Department Head only her department, and the page never
 * scrolls sideways from 375px to 1920px, in light or dark mode.
 * Screenshots land in storage/logs/e2e-artifacts/employee-360.
 */
test.describe.configure({ mode: 'serial' });

const SHOTS = path.resolve('storage/logs/e2e-artifacts/employee-360');
fs.mkdirSync(SHOTS, { recursive: true });

const WIDTHS = [375, 430, 768, 1024, 1366, 1440, 1600, 1920];
const overflowOf = (page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);

test('HR: the page is complete and never overflows sideways at any width', async ({ page }) => {
    const ari = account('employee');
    await login(page, 'hr_admin');

    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(`/employees/${ari.employee_id}/360`);
    await expectHealthyPage(page);
    await expect(page.locator('[data-360-header]')).toContainText('Ari Alpha');
    await expect(page.locator('[data-scope-label]')).toContainText('Company scope');
    for (const section of ['attendance', 'leave', 'performance', 'overtime']) {
        await expect(page.locator(`[data-360-section="${section}"]`)).toBeVisible();
    }
    await expect(page.locator('[data-360-details]')).toBeVisible();

    // The app shell holds: header and content beside the sidebar, not below it.
    const side = await page.locator('[data-flux-sidebar]').first().boundingBox();
    const header = await page.locator('[data-flux-header]').first().boundingBox();
    const content = await page.locator('[data-employee-360]').boundingBox();
    expect(header.x, 'header starts after the sidebar').toBeGreaterThanOrEqual(side.x + side.width - 1);
    expect(header.y, 'header is at the top, not under the sidebar').toBeLessThan(10);
    expect(content.x, 'content starts after the sidebar').toBeGreaterThanOrEqual(side.x + side.width - 1);

    for (const width of WIDTHS) {
        await page.setViewportSize({ width, height: width < 768 ? 860 : 900 });
        await page.waitForTimeout(250);
        expect(await overflowOf(page), `horizontal overflow at ${width}px`).toBeLessThanOrEqual(0);
        await page.screenshot({ path: path.join(SHOTS, `hr-light-${width}.png`), fullPage: true });
    }

    // Desktop: the activity table fits its card, and the trend chart's date labels never overlap.
    await page.setViewportSize({ width: 1600, height: 900 });
    await page.waitForTimeout(300);
    const activityScroll = page.locator('[data-360-section="activity"] .overflow-x-auto').first();
    if (await activityScroll.count()) {
        expect(await activityScroll.evaluate((el) => el.scrollWidth - el.clientWidth), 'activity table scrolls at 1600px').toBeLessThanOrEqual(0);
    }
    const labelBoxes = await page.locator('[data-360-section="attendance"] .apexcharts-xaxis-texts-g text').evaluateAll((nodes) => nodes.map((n) => n.getBoundingClientRect()).map((r) => [r.left, r.right]));
    for (let i = 1; i < labelBoxes.length; i++) {
        expect(labelBoxes[i][0], 'trend chart labels overlap').toBeGreaterThanOrEqual(labelBoxes[i - 1][1] - 1);
    }

    // Details move below the content on tablet / phone, beside it on desktop.
    await page.setViewportSize({ width: 768, height: 900 });
    const mainBox = await page.locator('[data-360-section="attendance"]').boundingBox();
    const detailsBox = await page.locator('[data-360-details]').boundingBox();
    expect(detailsBox.y).toBeGreaterThan(mainBox.y);

    // Dark mode, same layout rules.
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.evaluate(() => document.documentElement.classList.add('dark'));
    // Re-theming keeps the trend chart's date labels.
    await expect(page.locator('[data-360-section="attendance"] .apexcharts-xaxis').first()).toContainText('Oct');
    for (const width of [375, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await page.waitForTimeout(250);
        expect(await overflowOf(page), `dark overflow at ${width}px`).toBeLessThanOrEqual(0);
        await page.screenshot({ path: path.join(SHOTS, `hr-dark-${width}.png`), fullPage: true });
    }
});

test('HR: filters update the panels without a full reload', async ({ page }) => {
    const ari = account('employee');
    await login(page, 'hr_admin');
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(`/employees/${ari.employee_id}/360`);

    await page.evaluate(() => { window.__noReload = true; });
    await page.locator('[data-leave-tab="history"]').click();
    await expect(page.locator('[data-leave-tab="history"]')).toHaveAttribute('aria-selected', 'true');
    await page.locator('select[wire\\:model\\.live="attendanceStatus"]').selectOption('present');
    await expect(page).toHaveURL(/attendance=present/);
    expect(await page.evaluate(() => window.__noReload)).toBe(true);
});

test('department head: own department opens, the other department is refused', async ({ page }) => {
    const ari = account('employee');
    const bea = account('beta');
    await login(page, 'department_head');
    await page.setViewportSize({ width: 1440, height: 900 });

    await page.goto(`/employees/${ari.employee_id}/360`);
    await expectHealthyPage(page);
    await expect(page.locator('[data-scope-label]')).toContainText('Department scope');
    await expect(page.locator('[data-employee-selector]')).not.toContainText('Bea Beta');
    await page.screenshot({ path: path.join(SHOTS, 'dh-1440.png'), fullPage: true });

    expect(await statusOf(page, `/employees/${bea.employee_id}/360`)).toBe(403);
    expect(await statusOf(page, `/employees/${bea.employee_id}/360/report`)).toBe(403);
});

test('employee: no Employee 360° access by default', async ({ page }) => {
    const ari = account('employee');
    await login(page, 'employee');

    expect(await statusOf(page, `/employees/${ari.employee_id}/360`)).toBe(403);
});
