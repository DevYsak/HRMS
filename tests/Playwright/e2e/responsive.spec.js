import { test, expect } from '@playwright/test';
import { expectHealthyPage, login, logout } from './helpers.js';

/**
 * Responsive smoke test, run on the desktop, tablet and phone projects: each
 * role's everyday pages render without a server error and without pushing
 * the page sideways, and on small screens the menu opens from its toggle.
 */
const PAGES = {
    employee: ['/', '/attendance/my', '/time-off/my', '/overtime/my', '/notifications', '/my-profile', '/help/employee-guide'],
    manager: ['/dashboard/manager', '/time-off/team', '/attendance/team', '/overtime/manage'],
    department_head: ['/dashboard/department'],
    hr_admin: ['/', '/employees', '/attendance/employees', '/time-off/leave-management'],
    finance: ['/dashboard/finance', '/payroll/process'],
    super_admin: ['/', '/settings/roles'],
};

async function horizontalOverflow(page) {
    return page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
}

for (const [slug, paths] of Object.entries(PAGES)) {
    test(`${slug}: pages fit the screen`, async ({ page }) => {
        await login(page, slug);
        const overflowing = [];

        for (const path of paths) {
            const response = await page.goto(path);
            expect(response.status(), `${slug} → ${path}`).toBeLessThan(400);
            await expectHealthyPage(page);
            const overflow = await horizontalOverflow(page);
            if (overflow > 1) {
                overflowing.push(`${path} (+${overflow}px)`);
            }
        }

        expect(overflowing, `${slug}: pages wider than the screen`).toEqual([]);
    });
}

test('on small screens the menu opens from its toggle', async ({ page }) => {
    await login(page, 'manager');
    const sidebar = page.locator('[data-flux-sidebar]');
    const width = page.viewportSize().width;

    if (width >= 1024) {
        await expect(sidebar).toBeInViewport();
        return;
    }

    await expect(sidebar).not.toBeInViewport();
    await page.locator('[data-flux-sidebar-toggle], button[aria-label="Toggle sidebar"]').filter({ visible: true }).first().click();
    await expect(sidebar).toBeInViewport();
    await expect(sidebar.locator('a[href$="/time-off/team"]').first()).toBeAttached();
});

test('log out works on this screen size', async ({ page }) => {
    await login(page, 'employee');
    await logout(page, 'employee');
});
