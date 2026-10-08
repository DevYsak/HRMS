import { expect } from '@playwright/test';
import { manifest } from './env.js';

/** Fictional account for a role slug ('employee', 'manager', … or 'beta'). */
export function account(slug) {
    const data = manifest();
    const found = data.accounts[slug];
    if (!found) {
        throw new Error(`No E2E account '${slug}' in the manifest.`);
    }

    return { ...found, password: data.password };
}

/** Log in through the real login form and wait for the landing page. */
export async function login(page, slug) {
    const user = account(slug);
    await page.goto('/login');
    await page.fill('input[name=email]', user.email);
    await page.fill('input[name=password]', user.password);
    await Promise.all([
        page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 60 * 1000 }),
        // Same budget as the URL wait: a cold first request can be slow locally.
        page.click('[data-test=login-button]', { timeout: 60 * 1000 }),
    ]);
    await expectHealthyPage(page);

    return user;
}

/** Log out through the header account menu. */
export async function logout(page, slug) {
    const user = account(slug);
    const trigger = page.locator('ui-dropdown', { hasText: user.name }).filter({ visible: true }).last().locator('button').first();
    await trigger.click();
    await page.getByRole('menuitem', { name: 'Log out' }).filter({ visible: true }).first().click();
    await page.waitForURL((url) => url.pathname === '/' || url.pathname.startsWith('/login') || url.pathname.startsWith('/welcome'));

    const after = await page.request.get('/my-profile', { maxRedirects: 0 });
    expect(after.status(), 'a logged-out session must not open pages').toBe(302);
}

/** The page rendered without a server error page. */
export async function expectHealthyPage(page) {
    await expect(page.locator('body')).not.toContainText('Server Error');
    await expect(page.locator('body')).not.toContainText('Something went wrong');
    await expect(page.locator('body')).not.toContainText("You don't have access");
    await expect(page.locator('body')).not.toContainText('Page not found');
}

/** HTTP status of a GET in this browser session (no redirects followed). */
export async function statusOf(page, url) {
    const response = await page.request.get(url, { maxRedirects: 0 });

    return response.status();
}

/** Same-origin page links in the sidebar (desktop), as paths. */
export async function sidebarPaths(page) {
    const hrefs = await page.locator('[data-flux-sidebar] a[href]').evaluateAll((links) => links.map((a) => a.href));
    const origin = new URL(page.url()).origin;

    return [...new Set(hrefs
        .filter((href) => href.startsWith(origin))
        .map((href) => new URL(href).pathname)
        .filter((path) => path !== '' && !path.startsWith('/logout')))];
}

/** Titles of the notifications on the Notifications page. */
export async function notificationText(page) {
    await page.goto('/notifications');
    await expectHealthyPage(page);

    return (await page.locator('[data-flux-main], main').first().innerText()).replace(/\s+/g, ' ');
}

/** The next weekday at least `daysAhead` days from today (YYYY-MM-DD). */
export function nextWeekday(daysAhead = 7) {
    const date = new Date();
    date.setDate(date.getDate() + daysAhead);
    while ([0, 6].includes(date.getDay())) {
        date.setDate(date.getDate() + 1);
    }

    return isoDate(date);
}

export function isoDate(date) {
    const pad = (n) => String(n).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/** Fill a native date/time input that Livewire binds (wire:model). */
export async function fillInput(locator, value) {
    await locator.fill(value);
    await locator.dispatchEvent('change');
    await locator.blur();
}
