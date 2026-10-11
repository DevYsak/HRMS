import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { artisan } from './env.js';
import { expectHealthyPage, login } from './helpers.js';

/**
 * UI hardening journeys (desktop project only — it sets its own viewports):
 * settings nav and preferences, My Overtime, the AI assistant's OT answer,
 * leave date validation, modal scroll behaviour, notification settings on a
 * phone, the HR leave review opened from a notification link, and the Team
 * Leave filters. Screenshots land in storage/logs/e2e-artifacts/hardening.
 */
test.describe.configure({ mode: 'serial' });

const SHOTS = path.resolve('storage/logs/e2e-artifacts/hardening');
fs.mkdirSync(SHOTS, { recursive: true });

const PHONE = { width: 375, height: 812 };
const DESKTOPS = [
    { width: 1366, height: 768 },
    { width: 1440, height: 900 },
    { width: 1920, height: 1080 },
];
const WIDTHS = [320, 360, 375, 390, 414, 768, 1024, 1280, 1366, 1440, 1536, 1920];

const shot = (page, name) => page.screenshot({ path: path.join(SHOTS, `${name}-${page.viewportSize().width}.png`), fullPage: false });

const overflowOf = (page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);

/** Run PHP against the throwaway E2E database (never the dev database). */
const tinker = (code) => artisan(['tinker', '--execute', code]).trim();

/** Today in the app timezone, as the date inputs expect it. */
const isoToday = () => new Date(Date.now() + 5.5 * 3600 * 1000).toISOString().slice(0, 10);

test.beforeAll(() => {
    // The assistant on for every role, with no external model: answers must
    // come from Pulse data alone.
    // A few notification events (one long, one required) so the settings grid has rows to lay out.
    tinker("foreach ([['Leave decision', 'Leave', false], ['Overtime decision with a much longer descriptive event name', 'Overtime', false], ['Payslip ready', 'Payroll', true]] as $i => [$label, $group, $required]) { App\\Models\\NotificationSetting::updateOrCreate(['key' => 'App\\\\Notifications\\\\HardeningProbe'.$i], ['label' => $label, 'group' => $group, 'mail_enabled' => true, 'database_enabled' => true, 'is_mandatory' => $required]); }");
    tinker("App\\Models\\AiSetting::current()->update(['enabled' => true, 'api_key' => null, 'provider' => 'openai', 'allowed_roles' => collect(App\\Enums\\UserRole::cases())->map->value->all()]);");
});

test('employee: settings show only permitted pages; preferences change how dates read', async ({ page }) => {
    await login(page, 'employee');
    await page.goto('/settings/preferences');
    await expectHealthyPage(page);

    const nav = page.locator('[data-settings-nav=desktop]');
    await expect(nav.locator('[data-settings-link="settings.my-notifications"]')).toBeVisible();
    await expect(nav.locator('[data-settings-link="settings.holidays"]')).toHaveCount(0);
    await expect(nav.locator('[data-settings-link="teams.index"]')).toHaveCount(0);
    expect((await page.request.get('/settings/holidays', { maxRedirects: 0 })).status()).toBe(403);

    await page.getByRole('button', { name: /DD\/MM\/YYYY/ }).click();
    await page.getByRole('button', { name: /12-hour/ }).click();
    await page.getByRole('button', { name: 'Save Preferences' }).click();
    await expect(page.locator('body')).toContainText('Preferences saved');
    await shot(page, 'employee-preferences');

    // Phone: the compact settings menu replaces the side nav, nothing spills.
    await page.setViewportSize(PHONE);
    await page.goto('/settings/my-notifications');
    await expect(page.locator('[data-settings-nav=mobile] select')).toBeVisible();
    await expect(page.locator('[data-notification-row]')).toHaveCount(3);
    await expect(page.locator('[data-notification-row] [role=switch]').first()).toBeInViewport();
    expect(await overflowOf(page)).toBeLessThanOrEqual(1);
    await shot(page, 'employee-my-notifications');
    await page.setViewportSize({ width: 320, height: 640 });
    expect(await overflowOf(page), 'notification settings at 320px').toBeLessThanOrEqual(1);
    await shot(page, 'employee-my-notifications');
});

test('employee: request OT, see it counted, and the AI says one pending — not zero', async ({ page }) => {
    await login(page, 'employee');
    await page.goto('/overtime/my');
    await expectHealthyPage(page);
    await expect(page.locator('body')).toContainText('Track requests and approved overtime.');

    await page.getByRole('button', { name: 'Request OT' }).filter({ visible: true }).first().click();
    const modal = page.locator('[data-app-modal]');
    await expect(modal).toBeVisible();
    expect(await page.evaluate(() => getComputedStyle(document.documentElement).overflow)).toBe('hidden');

    await page.locator('input[wire\\:model="work_date"]').fill(isoToday());
    await page.locator('input[wire\\:model="start_time"]').fill('18:30');
    await page.locator('input[wire\\:model="end_time"]').fill('17:00');
    await page.locator('textarea[wire\\:model="reason"]').fill('Hardening journey — dispatch backlog.');
    await page.getByRole('button', { name: 'Submit Request' }).click();
    await expect(modal).toContainText('The end time must be after the start time.');
    await expect(page.locator('input[wire\\:model="end_time"]')).toBeFocused();
    await shot(page, 'employee-ot-modal-error');

    await page.locator('input[wire\\:model="end_time"]').fill('20:30');
    await page.getByRole('button', { name: 'Submit Request' }).click();
    await expect(page.locator('body')).toContainText('OT request submitted successfully.');
    await expect(page.locator('[data-ot-card=pending]')).toContainText('1');
    for (const size of DESKTOPS) {
        await page.setViewportSize(size);
        await shot(page, 'employee-my-overtime');
    }
    await page.setViewportSize(PHONE);
    await expect(page.locator('[data-ot-mobile-list]')).toBeVisible();
    expect(await overflowOf(page)).toBeLessThanOrEqual(1);
    await shot(page, 'employee-my-overtime');

    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/ai-assistant');
    await expect(page.locator('body')).toContainText('Answers are limited to your authorised HR data.');
    await expect(page.locator('[data-ai-scope]')).toContainText('Your data only');
    await expect(page.getByText('Press Enter to send')).toBeInViewport({ ratio: 1 });
    await page.locator('#ai-question').fill('Do I have any pending OT requests?');
    await page.getByRole('button', { name: 'Send question' }).click();
    const answer = page.locator('[data-ai-message=assistant]').last();
    await expect(answer).toContainText('You have 1 OT request waiting for a decision.');
    await expect(answer.locator('[data-ai-link]', { hasText: 'Open My Overtime' })).toBeVisible();
    await expect(answer.locator('[data-ai-link]', { hasText: 'Overtime Requests' })).toHaveCount(0);

    await page.locator('#ai-question').fill('How many OT requests are pending my approval?');
    await page.getByRole('button', { name: 'Send question' }).click();
    await expect(page.locator('[data-ai-message=assistant]').last()).toContainText("You don't approve OT requests");
    await shot(page, 'employee-ai-assistant');
    await page.setViewportSize(PHONE);
    expect(await overflowOf(page)).toBeLessThanOrEqual(1);
    await shot(page, 'employee-ai-assistant');
});

test('employee: leave dates validate under their own field and the modal fits a phone', async ({ page }) => {
    await login(page, 'employee');
    await page.setViewportSize({ width: 375, height: 667 });
    await page.goto('/time-off/my');
    await page.getByRole('button', { name: 'Apply Leave' }).filter({ visible: true }).first().click();

    const modal = page.locator('[data-app-modal]');
    await expect(modal).toBeVisible();
    expect(await page.evaluate(() => getComputedStyle(document.documentElement).overflow), 'page behind is locked').toBe('hidden');

    await expect(page.locator('[data-ai-copilot]')).toBeHidden();
    const panel = await modal.locator('> div.relative').boundingBox();
    expect(panel.height, 'modal never taller than the screen').toBeLessThanOrEqual(667);
    await expect(modal.locator('footer button[type=submit]')).toBeInViewport();

    const start = new Date(Date.now() + 10 * 86400000).toISOString().slice(0, 10);
    const end = new Date(Date.now() + 5 * 86400000).toISOString().slice(0, 10);
    await modal.locator('#dr-start_date').fill(start);
    await modal.locator('#dr-end_date').fill(end);
    await modal.locator('footer button[type=submit]').click();
    await expect(modal).toContainText('The end date must be on or after the start date.');

    // Stacked on a phone: End Date sits below Start Date.
    const startBox = await modal.locator('#dr-start_date').boundingBox();
    const endBox = await modal.locator('#dr-end_date').boundingBox();
    expect(endBox.y).toBeGreaterThan(startBox.y);
    await shot(page, 'employee-leave-modal-error');

    await page.setViewportSize({ width: 1366, height: 768 });
    const startWide = await modal.locator('#dr-start_date').boundingBox();
    const endWide = await modal.locator('#dr-end_date').boundingBox();
    expect(Math.abs(endWide.y - startWide.y), 'side by side on desktop').toBeLessThan(4);
    await shot(page, 'employee-leave-modal-error');
});

test('employee: everyday pages fit every width from 320 to 1920', async ({ page }) => {
    test.setTimeout(10 * 60 * 1000);
    await login(page, 'employee');
    const pages = ['/overtime/my', '/settings/my-notifications', '/settings/preferences', '/ai-assistant', '/my-profile', '/time-off/my'];
    const overflowing = [];

    for (const width of WIDTHS) {
        await page.setViewportSize({ width, height: width < 768 ? 800 : 900 });
        for (const url of pages) {
            await page.goto(url);
            await expectHealthyPage(page);
            const overflow = await overflowOf(page);
            if (overflow > 1) {
                overflowing.push(`${url} @${width}px (+${overflow}px)`);
            }
        }
    }

    await page.setViewportSize(PHONE);
    await page.goto('/my-profile');
    await expect(page.locator('[data-profile-completeness]')).toBeVisible();
    await shot(page, 'employee-profile-overview');

    expect(overflowing).toEqual([]);
});

test('HR: a leave notification link opens the review modal; Team Leave filters stay inside their card', async ({ page }) => {
    // A pending request from the employee, as the notification would point to.
    const id = tinker(
        "$e = App\\Models\\User::where('email', 'like', 'e2e.employee@%')->first()->employee;"
        + "$t = App\\Models\\LeaveType::where('allow_unpaid_request', true)->first() ?? App\\Models\\LeaveType::first();"
        + "$t->update(['allow_unpaid_request' => true]);"
        + "$d = now()->addWeekdays(12)->toDateString();"
        + "echo app(App\\Services\\LeaveService::class)->submitRequest($e, $t, $d, $d, 'Hardening journey', requestedLeaveStatus: 'unpaid')->id;",
    ).split('\n').pop();

    await login(page, 'hr_admin');
    for (const size of [...DESKTOPS, PHONE]) {
        await page.setViewportSize(size);
        await page.goto(`/time-off/team?selectedRequestId=${id}`);
        await expectHealthyPage(page);
        const modal = page.locator('[data-app-modal]');
        await expect(modal).toContainText('Review Leave Request');
        await expect(modal.getByRole('button', { name: 'Approve' })).toBeInViewport();
        expect(await page.evaluate(() => getComputedStyle(document.documentElement).overflow)).toBe('hidden');
        await shot(page, 'hr-leave-review-from-notification');

        await page.keyboard.press('Escape');
        await expect(modal).toHaveCount(0);

        const card = await page.locator('[data-team-leave-filters]').boundingBox();
        for (const input of ['#team-filter-from', '#team-filter-to']) {
            const box = await page.locator(input).boundingBox();
            expect(box.x + box.width, `${input} inside the card at ${size.width}px`).toBeLessThanOrEqual(card.x + card.width + 1);
        }
        expect(await overflowOf(page), `team leave at ${size.width}px`).toBeLessThanOrEqual(1);
        await shot(page, 'hr-team-leave-filters');
    }

    await page.setViewportSize({ width: 768, height: 1024 });
    await page.goto('/time-off/team');
    await shot(page, 'hr-team-leave-filters');

    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/settings/preferences');
    await expect(page.locator('[data-settings-link="settings.holidays"]')).toBeVisible();
});
