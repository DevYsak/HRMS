import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { artisan } from './env.js';
import { expectHealthyPage, login } from './helpers.js';

/**
 * HR connectivity journeys (desktop project; sets its own viewports): the
 * Department Head's scoped pages agree on people and counts, Team Leave only
 * offers actions she can take, and HR sees one People Operations view by
 * department. Screenshots land in storage/logs/e2e-artifacts/hr-connectivity.
 */
test.describe.configure({ mode: 'serial' });

const SHOTS = path.resolve('storage/logs/e2e-artifacts/hr-connectivity');
fs.mkdirSync(SHOTS, { recursive: true });

const shot = (page, name) => page.screenshot({ path: path.join(SHOTS, `${name}-${page.viewportSize().width}.png`), fullPage: true });
const overflowOf = (page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
const tinker = (code) => artisan(['tinker', '--execute', code]).trim();

test.beforeAll(() => {
    // A pending leave request from the department head's team and one from the other department.
    tinker(
        "$t = App\\Models\\LeaveType::first(); $t->update(['allow_unpaid_request' => true]);"
        + "$d = now()->addWeekdays(10)->toDateString();"
        + "foreach (['e2e.employee@%', 'e2e.beta@%'] as $mail) { $e = App\\Models\\User::where('email', 'like', $mail)->first()->employee;"
        + "App\\Models\\LeaveRequest::create(['employee_id' => $e->id, 'leave_type_id' => $t->id, 'start_date' => $d, 'end_date' => $d, 'days' => 1, 'reason' => 'Connectivity journey', 'status' => 'pending', 'requested_leave_status' => 'unpaid']); }",
    );
});

test('department head: every page shows the same department scope and only actions she can take', async ({ page }) => {
    await login(page, 'department_head');
    await page.setViewportSize({ width: 1440, height: 900 });

    await page.goto('/dashboard/manager');
    await expectHealthyPage(page);
    await expect(page.locator('[data-scope-badge]').first()).toContainText('E2E Alpha');
    await shot(page, 'dh-team-view');

    await page.goto('/attendance/command-center');
    await expectHealthyPage(page);
    await expect(page.locator('[data-scope-badge]').first()).toContainText('E2E Alpha');
    await expect(page.locator('body')).not.toContainText('Bea Beta');
    await shot(page, 'dh-command-center');

    await page.goto('/time-off/team');
    await expectHealthyPage(page);
    await expect(page.locator('body')).toContainText('Ari Alpha');
    await expect(page.locator('body')).not.toContainText('Bea Beta');
    await expect(page.locator('[data-can-decide]').first()).toBeVisible();
    await page.locator('[data-can-decide]').first().click();
    await expect(page.locator('[data-approval-path]')).toBeVisible();
    await expect(page.locator('[data-app-modal]')).toContainText('Approve');
    await shot(page, 'dh-team-leave-review');

    const deptId = tinker("echo App\\Models\\Department::where('name', 'E2E Alpha')->value('id');").split('\n').pop();
    const otherId = tinker("echo App\\Models\\Department::where('name', 'E2E Beta')->value('id');").split('\n').pop();
    await page.goto(`/departments/${deptId}`);
    await expectHealthyPage(page);
    await expect(page.locator('body')).toContainText('Department Head: Dev Depthead');
    await shot(page, 'dh-department-overview');
    expect((await page.request.get(`/departments/${otherId}`, { maxRedirects: 0 })).status()).toBe(403);

    await page.goto('/performance/kpi-management');
    await expectHealthyPage(page);
    await shot(page, 'dh-kpi-management');

    for (const url of ['/dashboard/manager', '/attendance/command-center', '/time-off/team', `/departments/${deptId}`]) {
        await page.setViewportSize({ width: 375, height: 812 });
        await page.goto(url);
        expect(await overflowOf(page), `${url} at 375px`).toBeLessThanOrEqual(1);
    }
    await page.goto('/dashboard/manager');
    await shot(page, 'dh-team-view');
});

test('HR: People Operations by department, Department Health and Data Quality', async ({ page }) => {
    await login(page, 'hr_admin');
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/');
    await expectHealthyPage(page);
    await expect(page.locator('[data-people-operations]')).toBeVisible();
    await expect(page.locator('[data-department-health="E2E Alpha"]')).toBeVisible();
    await shot(page, 'hr-dashboard');

    const select = page.locator('[data-po-department]');
    await select.selectOption({ label: 'E2E Alpha' });
    await expect(page.locator('[data-people-operations] [data-scope-badge]')).toContainText('E2E Alpha');
    await shot(page, 'hr-dashboard-alpha');

    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto('/');
    expect(await overflowOf(page)).toBeLessThanOrEqual(1);
    await shot(page, 'hr-dashboard');
});

test('super admin: role summary before saving and the approval matrix', async ({ page }) => {
    await login(page, 'super_admin');
    await page.setViewportSize({ width: 1440, height: 900 });

    await page.goto('/settings/roles');
    await expectHealthyPage(page);
    const card = page.locator('.pulse-card', { hasText: 'Department Head' }).first();
    await card.getByRole('button', { name: 'Edit' }).click();
    await expect(page.locator('[data-role-summary]')).toBeVisible();
    await expect(page.locator('[data-role-summary]')).toContainText('Can approve');
    await page.screenshot({ path: path.join(SHOTS, 'sa-role-summary-1440.png') });

    await page.goto('/settings/approval-matrix');
    await expectHealthyPage(page);
    await expect(page.locator('[data-approval-matrix="leave"]')).toBeVisible();
    await shot(page, 'sa-approval-matrix');
});
