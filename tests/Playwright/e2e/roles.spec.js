import { test, expect } from '@playwright/test';
import { account, expectHealthyPage, login, logout, sidebarPaths, statusOf } from './helpers.js';

/**
 * One journey per role, through the real UI: log in, land on the right
 * dashboard, see the menu the role should see (and not the rest), get a 403
 * on a forbidden page typed into the address bar, open the self-service pages
 * and the role's approval pages, log out.
 *
 * "Every sidebar link opens" walks the whole menu, so a menu item that leads
 * to a 403 fails here.
 */

const SELF_SERVICE = [
    ['/my-profile', null],
    ['/attendance/my', null],
    ['/time-off/my', 'Apply Leave'],
    ['/overtime/my', 'Request OT'],
    ['/notifications', null],
];

// `menu` lists pages besides the landing dashboard: the sidebar folds a role's
// own dashboard into its "Dashboard" item (the landing test covers it).
const ROLES = {
    super_admin: {
        landing: '/',
        menu: ['/employees', '/settings/roles', '/settings/control-panel', '/settings/audit-log', '/attendance/employees', '/payroll/process'],
        hidden: [],
        forbidden: [],
        approvals: ['/time-off/team', '/overtime/manage', '/attendance/employees', '/payroll/finance-approve'],
    },
    hr_admin: {
        landing: '/',
        menu: ['/employees', '/attendance/employees', '/time-off/leave-management', '/settings/departments'],
        hidden: [],
        forbidden: [],
        approvals: ['/time-off/team', '/attendance/employees', '/time-off/leave-management'],
    },
    department_head: {
        landing: '/dashboard/department',
        menu: ['/', '/time-off/team', '/attendance/team', '/overtime/manage'],
        hidden: ['/employees/create', '/settings/roles', '/payroll/process', '/dashboard/finance', '/attendance/employees'],
        forbidden: ['/employees/create', '/settings/roles', '/payroll/process', '/dashboard/finance'],
        approvals: ['/time-off/team', '/attendance/team', '/overtime/manage'],
    },
    manager: {
        landing: '/dashboard/manager',
        menu: ['/time-off/team', '/attendance/team', '/overtime/manage'],
        hidden: ['/employees/create', '/settings/roles', '/payroll/process', '/dashboard/finance', '/attendance/employees', '/dashboard/department'],
        // All Attendance is hidden from managers (it repeats Team Attendance) but
        // its route is open to approvers, scoped to their team — see isolation.spec.
        forbidden: ['/employees/create', '/settings/roles', '/payroll/process', '/dashboard/finance'],
        approvals: ['/time-off/team', '/attendance/team', '/overtime/manage'],
    },
    finance: {
        landing: '/dashboard/finance',
        menu: ['/', '/payroll/process', '/payroll/finance-approve'],
        hidden: ['/employees/create', '/settings/roles', '/attendance/employees', '/time-off/team'],
        forbidden: ['/employees/create', '/settings/roles', '/attendance/employees'],
        approvals: ['/payroll/finance-approve', '/payroll/process'],
    },
    employee: {
        landing: '/',
        menu: ['/attendance/my', '/time-off/my', '/overtime/my', '/payroll/my-payslips'],
        hidden: ['/employees/create', '/settings/roles', '/payroll/process', '/time-off/team', '/attendance/team', '/attendance/employees', '/dashboard/finance', '/time-off/leave-management'],
        forbidden: ['/employees/create', '/settings/roles', '/payroll/process', '/time-off/team', '/attendance/employees', '/dashboard/finance', '/time-off/leave-management', '/dashboard/manager'],
        approvals: [],
    },
};

for (const [slug, role] of Object.entries(ROLES)) {
    test.describe(`${slug} journey`, () => {
        test('logs in and lands on the role dashboard', async ({ page }) => {
            await login(page, slug);
            expect(new URL(page.url()).pathname).toBe(role.landing);
            await expect(page.locator('[data-flux-sidebar]')).toBeVisible();
        });

        test('sees its menu, and not the menu of other roles', async ({ page }) => {
            await login(page, slug);
            const paths = await sidebarPaths(page);

            for (const path of role.menu) {
                expect(paths, `${slug} should see ${path} in the menu`).toContain(path);
            }
            for (const path of role.hidden) {
                expect(paths, `${slug} must not see ${path} in the menu`).not.toContain(path);
            }
        });

        test('every sidebar link opens (no menu item leads to a 403/404)', async ({ page }) => {
            // Walks every link (about 100 for Super Admin) on a single-threaded server.
            test.setTimeout(15 * 60 * 1000);
            await login(page, slug);
            const broken = [];

            for (const path of await sidebarPaths(page)) {
                const status = await statusOf(page, path);
                if (status >= 400) {
                    broken.push(`${path} → ${status}`);
                }
            }

            expect(broken, `${slug}: menu links that do not open`).toEqual([]);
        });

        test('a forbidden page typed into the address bar is a 403', async ({ page }) => {
            await login(page, slug);

            for (const path of role.forbidden) {
                expect(await statusOf(page, path), `${slug} → ${path}`).toBe(403);
            }

            if (role.forbidden.length > 0) {
                await page.goto(role.forbidden[0]);
                await expect(page.getByText("You don't have access").first()).toBeVisible();
            }
        });

        test('opens profile, attendance, leave, overtime and notifications', async ({ page }) => {
            const user = await login(page, slug);

            for (const [path, marker] of SELF_SERVICE) {
                const response = await page.goto(path);
                expect(response.status(), `${slug} → ${path}`).toBeLessThan(400);
                await expectHealthyPage(page);
                if (marker) {
                    await expect(page.getByText(marker, { exact: false }).first()).toBeVisible();
                }
            }

            await page.goto('/my-profile');
            await expect(page.locator('[data-flux-main], main').first()).toContainText(user.name);
        });

        test('opens its approval pages', async ({ page }) => {
            test.skip(role.approvals.length === 0, 'No approval pages for this role');
            await login(page, slug);

            for (const path of role.approvals) {
                const response = await page.goto(path);
                expect(response.status(), `${slug} → ${path}`).toBeLessThan(400);
                await expectHealthyPage(page);
            }
        });

        test('logs out', async ({ page }) => {
            await login(page, slug);
            await logout(page, slug);
        });
    });
}

test('the account names in this suite are fictional', () => {
    for (const slug of [...Object.keys(ROLES), 'beta']) {
        expect(account(slug).email).toMatch(/^e2e\.[a-z]+@example\.com$/);
    }
});
