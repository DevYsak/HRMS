import { test, expect } from '@playwright/test';
import { artisan } from './env.js';
import { account, expectHealthyPage, fillInput, isoDate, login, nextWeekday, notificationText } from './helpers.js';

/**
 * Business journeys across roles, each in its own browser session:
 *
 *  - leave: employee applies → their manager is notified and approves (HR too
 *    when the policy asks) → the employee is told it is approved;
 *  - overtime: employee requests → their manager is notified, Finance is not;
 *  - missing checkout: yesterday's open check-in → the employee is alerted;
 *  - regularisation: employee asks to fix a punch → HR is notified (the
 *    deciding approver), the line manager and Finance are not.
 */
test.describe.configure({ mode: 'serial' });

async function as(browser, slug) {
    const context = await browser.newContext();
    const page = await context.newPage();
    await login(page, slug);

    return { page, close: () => context.close() };
}

test('leave: employee → manager approves → employee notified', async ({ browser }) => {
    const employee = account('employee');
    const day = nextWeekday(8);

    const emp = await as(browser, 'employee');
    await emp.page.goto('/time-off/my');
    await emp.page.getByRole('button', { name: 'Apply Leave' }).filter({ visible: true }).first().click();

    const form = emp.page.locator('form[wire\\:submit="submitRequest"]');
    await expect(form).toBeVisible();
    await form.locator('div:has(> label:has-text("Leave Type")) > button').first().click();
    await form.getByRole('button', { name: 'Casual / Sick Leave' }).click();
    await fillInput(form.locator('input[wire\\:model\\.live="start_date"]'), day);
    await fillInput(form.locator('input[wire\\:model\\.live="end_date"]'), day);
    await form.locator('textarea[wire\\:model="reason"]').fill('E2E journey — family appointment.');
    await form.getByRole('button', { name: 'Submit Request' }).click();
    await expect(form).toBeHidden({ timeout: 30 * 1000 });
    await expect(emp.page.locator('body')).toContainText(/pending/i);
    await emp.close();

    // The reporting manager is asked; someone outside the line is not.
    const finance = await as(browser, 'finance');
    expect(await notificationText(finance.page)).not.toContain('New Leave Request');
    await finance.close();

    const manager = await as(browser, 'manager');
    expect(await notificationText(manager.page)).toContain('New Leave Request');
    await approveLeave(manager.page, employee.name);
    await manager.close();

    let check = await as(browser, 'employee');
    let text = await notificationText(check.page);
    await check.close();

    if (!text.includes('Leave Approved') && text.includes('Leave Awaiting HR')) {
        const hr = await as(browser, 'hr_admin');
        await approveLeave(hr.page, employee.name);
        await hr.close();

        check = await as(browser, 'employee');
        text = await notificationText(check.page);
        await check.close();
    }

    expect(text).toContain('Leave Approved');
});

async function approveLeave(page, employeeName) {
    await page.goto('/time-off/team');
    await expectHealthyPage(page);
    const card = page.locator('div', { hasText: employeeName }).filter({ has: page.getByRole('button', { name: 'Review Request' }) }).last();
    await card.getByRole('button', { name: 'Review Request' }).first().click();
    const approve = page.getByRole('button', { name: 'Approve', exact: true }).filter({ visible: true }).last();
    await approve.click();
    await expect(page.locator('body')).toContainText(/approved/i);
}

test('overtime: employee → their manager is asked, Finance is not', async ({ browser }) => {
    const employee = account('employee');

    const emp = await as(browser, 'employee');
    await emp.page.goto('/overtime/my');
    await emp.page.getByRole('button', { name: 'Request OT' }).filter({ visible: true }).first().click();
    const workDate = emp.page.locator('input[wire\\:model="work_date"]');
    await expect(workDate).toBeVisible();
    await fillInput(workDate, isoDate(new Date()));
    await fillInput(emp.page.locator('input[wire\\:model="start_time"]'), '18:30');
    await fillInput(emp.page.locator('input[wire\\:model="end_time"]'), '20:30');
    await emp.page.locator('textarea[wire\\:model="reason"]').fill('E2E journey — month-end dispatch.');
    await emp.page.getByRole('button', { name: 'Submit Request' }).filter({ visible: true }).click();
    await expect(emp.page.locator('body')).toContainText('OT request submitted successfully.');
    await emp.close();

    const manager = await as(browser, 'manager');
    expect(await notificationText(manager.page)).toContain('New OT Request');
    await manager.page.goto('/overtime/manage');
    await expect(manager.page.locator('[data-flux-main], main').first()).toContainText(employee.name);
    await manager.close();

    const finance = await as(browser, 'finance');
    expect(await notificationText(finance.page)).not.toContain('New OT Request');
    await finance.close();
});

test('missing checkout: yesterday\'s open check-in alerts the employee', async ({ browser }) => {
    artisan(['hrms:flag-missing-checkouts']);

    const emp = await as(browser, 'employee');
    expect(await notificationText(emp.page)).toContain('Missing Clock-Out');
    await emp.close();
});

test('regularisation: employee → HR decides; manager and Finance are not asked', async ({ browser }) => {
    const yesterday = new Date();
    yesterday.setDate(yesterday.getDate() - 1);

    const emp = await as(browser, 'employee');
    await emp.page.goto('/attendance/my');
    await emp.page.locator('button', { hasText: 'Regularize' }).filter({ visible: true }).first().click();
    const dialog = emp.page.locator('[data-modal="regularisation-modal"], ui-modal', { hasText: 'Request Regularization' }).filter({ visible: true }).first();
    await expect(dialog).toBeVisible();
    await fillInput(dialog.locator('input[wire\\:model="regDate"]'), isoDate(yesterday));
    const fixOut = dialog.locator('input[wire\\:model\\.live="regFixOut"]');
    if (!(await fixOut.isChecked())) {
        await fixOut.check();
    }
    await fillInput(dialog.locator('input[wire\\:model="regCheckOut"]'), '19:00');
    const checkIn = dialog.locator('input[wire\\:model="regCheckIn"]');
    if (await checkIn.isEnabled()) {
        await fillInput(checkIn, '10:25');
    }
    await dialog.locator('textarea[wire\\:model="regReason"]').fill('E2E journey — forgot to clock out.');
    await dialog.getByRole('button', { name: 'Submit Request' }).click();
    await expect(emp.page.locator('body')).toContainText('Regularisation request sent to HR for approval.');
    await emp.close();

    const hr = await as(browser, 'hr_admin');
    expect(await notificationText(hr.page)).toContain('Regularisation Request');
    await hr.close();

    for (const slug of ['manager', 'finance']) {
        const other = await as(browser, slug);
        expect(await notificationText(other.page), `${slug} must not be asked to decide a regularisation`).not.toContain('Regularisation Request');
        await other.close();
    }
});
