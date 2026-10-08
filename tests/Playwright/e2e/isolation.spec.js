import { test, expect } from '@playwright/test';
import { account, expectHealthyPage, login, statusOf } from './helpers.js';

/**
 * Department and team isolation, checked from the browser.
 *
 * E2E Alpha: every role account, with Ari Alpha (employee) reporting to Mona
 * Manager and Dev Depthead heading the department. E2E Beta: Bea Beta only.
 * A manager or department head must never see or open Bea's records; HR and
 * Super Admin see the whole company; Finance reaches payroll data, not HR
 * records.
 */

const main = (page) => page.locator('[data-flux-main], main').first();

test.describe('department isolation', () => {
    for (const slug of ['manager', 'department_head']) {
        test(`${slug} never sees or opens the other department`, async ({ page }) => {
            await login(page, slug);
            const alpha = account('employee');
            const beta = account('beta');

            for (const path of ['/attendance/team', '/attendance/employees', '/time-off/team', '/employees']) {
                await page.goto(path);
                await expectHealthyPage(page);
                await expect(main(page), `${slug} ${path}`).not.toContainText(beta.name);
            }

            // Their own people are there: a manager's Team Attendance lists direct
            // reports; a department head's department view lists the department.
            await page.goto(slug === 'manager' ? '/attendance/team' : '/dashboard/department');
            await expect(main(page)).toContainText(alpha.name);

            for (const path of [
                `/employees/${beta.employee_id}/profile`,
                `/employees/${beta.employee_id}/edit`,
                `/employees/${beta.employee_id}/finance-profile`,
                `/time-off/leave-management/employees/${beta.employee_id}`,
            ]) {
                expect(await statusOf(page, path), `${slug} → ${path}`).toBeGreaterThanOrEqual(403);
            }
        });
    }

    test('department head dashboard counts only the department', async ({ page }) => {
        await login(page, 'department_head');
        await page.goto('/dashboard/department');
        await expectHealthyPage(page);

        await expect(main(page)).toContainText('E2E Alpha');
        await expect(main(page)).not.toContainText('E2E Beta');
        await expect(main(page)).not.toContainText(account('beta').name);
    });

    test('HR sees the whole company', async ({ page }) => {
        await login(page, 'hr_admin');

        await expect(main(page)).toContainText('All departments');
        // Seven fictional employees across both departments, nobody else.
        const headcount = main(page).getByText('Working headcount', { exact: true }).first().locator('xpath=..');
        await expect(headcount).toHaveText(/(^|\s)7\s+Working headcount/);

        await page.goto('/employees');
        await expect(main(page)).toContainText(account('employee').name);
        await expect(main(page)).toContainText(account('beta').name);

        expect(await statusOf(page, `/employees/${account('beta').employee_id}/profile`)).toBeLessThan(400);
    });

    test('Finance reaches payroll data, not HR records', async ({ page }) => {
        await login(page, 'finance');
        const alpha = account('employee');

        expect(await statusOf(page, `/employees/${alpha.employee_id}/finance-profile`)).toBeLessThan(400);
        expect(await statusOf(page, '/payroll/process')).toBeLessThan(400);

        for (const path of [`/employees/${alpha.employee_id}/edit`, `/employees/${alpha.employee_id}/profile`, '/employees/create', '/settings/roles']) {
            expect(await statusOf(page, path), `finance → ${path}`).toBe(403);
        }
    });

    test('an employee opens only their own records', async ({ page }) => {
        await login(page, 'employee');
        const manager = account('manager');
        const beta = account('beta');

        for (const path of [
            `/employees/${beta.employee_id}/profile`,
            `/employees/${manager.employee_id}/profile`,
            `/employees/${beta.employee_id}/finance-profile`,
            `/time-off/leave-management/employees/${beta.employee_id}`,
        ]) {
            expect(await statusOf(page, path), `employee → ${path}`).toBeGreaterThanOrEqual(403);
        }
    });
});
