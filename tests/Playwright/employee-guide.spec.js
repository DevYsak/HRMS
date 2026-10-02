import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

/**
 * Captures the screenshots for the in-app Employee Guide (/help/employee-guide).
 *
 * Logs in as the fictional demo employee (php artisan guide:demo-employee),
 * visits each employee page, and saves two images per shot into
 * public/images/employee-guide:
 *   <id>.jpg            — the untouched screen (JPEG)
 *   annotated/<id>.png  — the same screen with numbered markers
 * plus manifest.json, which the guide reads for titles and marker legends.
 *
 * Markers are drawn on a temporary overlay inside the browser only; the
 * application itself is never modified. Nothing is submitted: forms are opened
 * for the picture and left unsaved.
 *
 * If an expected page is forbidden, redirects, or a marked element is missing,
 * the run fails and names the shot — the UI has changed and the guide needs a
 * look.
 */

const EMAIL = process.env.GUIDE_EMPLOYEE_EMAIL || 'guide.employee@example.com';
const PASSWORD = process.env.GUIDE_EMPLOYEE_PASSWORD || 'GuideDemo#2026';
const OUT_DIR = path.resolve('public/images/employee-guide');
const MANIFEST = path.join(OUT_DIR, 'manifest.json');

// ── Locator helpers ────────────────────────────────────────────────────────

/** The nearest card-like box (bordered, rounded) around the given text. */
const card = (text, options = { exact: true }) => (page) =>
    scope(page).getByText(text, options).first()
        .locator("xpath=ancestor::*[contains(@class,'pulse-card') or (contains(@class,'border') and (contains(@class,'rounded-xl') or contains(@class,'rounded-2xl') or contains(@class,'rounded-3xl')))][1]");

/** A dashboard section card, found by its <h3> title. */
const sectionCard = (title) => (page) =>
    scope(page).locator('h3').filter({ hasText: new RegExp(`^\\s*${title}\\s*$`) }).first()
        .locator("xpath=ancestor::div[contains(@class,'rounded-2xl')][1]");

const text = (value, options = { exact: true }) => (page) => scope(page).getByText(value, options).first();
const button = (name, options = { exact: true }) => (page) => scope(page).getByRole('button', { name, ...options }).first();
const css = (selector) => (page) => scope(page).locator(selector).first();
/** The block a form label belongs to (the label's parent element). */
const field = (label, options = { exact: false }) => (page) => scope(page).getByText(label, options).first().locator('xpath=..');

/** Search the open modal if there is one, otherwise the main content (not the sidebar). */
const scope = (page) => page.__scope ?? page.locator('[data-flux-main], main').first();

const userMenuButton = async (page) => {
    const buttons = page.locator('button', { hasText: 'Alex Morgan' });
    for (let i = 0; i < await buttons.count(); i++) {
        const box = await buttons.nth(i).boundingBox();
        if (box && box.y < 80) {
            return buttons.nth(i);
        }
    }
    throw new Error('Top-right user menu button not found');
};

const openModal = (trigger, heading) => async (page) => {
    await trigger(page).click();
    // Flux <dialog> modals and the custom fixed-overlay modals some pages use.
    const dialog = page.locator('dialog[open], [role=dialog]:visible, div.fixed.inset-0:visible').filter({ hasText: heading }).last();
    await expect(dialog, `modal "${heading}" did not open`).toBeVisible({ timeout: 10000 });
    await page.waitForTimeout(500);
    page.__scope = dialog;
};

// ── Shots ──────────────────────────────────────────────────────────────────

const SHOTS = [
    {
        id: 'dashboard-top', url: '/', title: 'Your dashboard',
        markers: [
            ['Sidebar menu', 'every page you can use', (page) => page.getByRole('link', { name: 'Attendance', exact: true }).first().locator('xpath=ancestor::nav[1]')],
            ['Clock In', 'start your working day', button('Clock In')],
            ['Setup prompts', 'finish your profile and onboarding', (page) => page.getByText('Complete your profile').first().locator("xpath=ancestor::*[contains(@class,'grid')][1]")],
            ['Today at a glance', 'attendance, leave, late marks, OT, pending, salary', (page) => page.getByText('Leave Left', { exact: true }).first().locator("xpath=ancestor::div[contains(@class,'grid-cols-2')][1]")],
            ['Notifications', 'unread count', (page) => page.getByRole('button', { name: 'Notifications' }).first()],
            ['Your menu', 'profile, settings, this guide, log out', userMenuButton],
        ],
    },
    {
        id: 'dashboard-widgets', url: '/', title: 'Dashboard widgets', scrollTo: text("Today's Timeline"),
        markers: [
            ["Today's timeline", 'clock in, breaks and clock out', sectionCard("Today's Timeline")],
            ['Payroll', 'latest net salary and payslip download', sectionCard('Payroll')],
            ['Performance', 'current review score', sectionCard('Performance')],
            ['Quick actions', 'one-click shortcuts', sectionCard('Quick Actions')],
            ['Announcements', 'recent notifications and holidays', sectionCard('Announcements')],
        ],
    },
    {
        id: 'avatar-menu', url: '/', title: 'Your personal menu (top right)',
        prepare: async (page) => { await (await userMenuButton(page)).click(); await page.waitForTimeout(400); },
        markers: [
            ['My Profile', 'your personal and job details', (page) => page.getByRole('menuitem', { name: 'My Profile' }).last()],
            ['Account settings', 'password and security', (page) => page.getByRole('menuitem', { name: 'Account settings' }).last()],
            ['Preferences', 'timezone and date format', (page) => page.getByRole('menuitem', { name: 'Preferences' }).last()],
            ['Help & Employee Guide', 'this guide', (page) => page.getByRole('menuitem', { name: 'Help & Employee Guide' }).last()],
            ['Log out', null, (page) => page.getByRole('menuitem', { name: 'Log out' }).last()],
        ],
    },
    {
        id: 'mobile-dashboard', url: '/', title: 'Pulse on a phone: tap the menu icon to open the sidebar', mobile: true,
        prepare: async (page) => {
            await page.locator('[data-flux-sidebar-toggle], button[aria-label="Toggle sidebar"]').first().click();
            await page.waitForTimeout(600);
        },
        markers: [],
    },
    {
        id: 'profile-overview', url: '/my-profile', title: 'My Profile',
        markers: [
            ['Your record', 'employee ID, manager, joining date, shift', card('Employee ID', { exact: false })],
            ['Pending change', 'waiting for HR', text('awaiting HR review', { exact: false })],
            ['Tabs', 'overview, personal, employment, requests', (page) => page.getByRole('button', { name: 'Personal', exact: true }).first().locator('xpath=..')],
            ['Finish your profile', 'click a field to fill it in', card('Finish your profile')],
        ],
    },
    {
        id: 'profile-request', url: '/my-profile', title: 'Requesting a change that HR approves',
        prepare: openModal(button('Bank name'), 'Request a change'),
        markers: [
            ['New value', null, (page) => scope(page).locator('input, select').first()],
            ['Reason', 'optional but helpful', field('Reason (optional)')],
            ['Send to HR', 'your old value stays until approved', button('Send to HR')],
        ],
    },
    {
        id: 'profile-requests', url: '/my-profile', title: 'Tracking your change requests',
        prepare: async (page) => {
            await page.getByRole('button', { name: /^Requests/ }).first().click();
            await expect(page.getByText('Change requests', { exact: true })).toBeVisible({ timeout: 10000 });
            await page.waitForTimeout(500);
        },
        markers: [
            ['Requests tab', null, (page) => page.getByRole('button', { name: /^Requests/ }).first()],
            ['Requested change', 'old and new value', text('45 Example Road', { exact: false })],
            ['Status', null, (page) => scope(page).getByText(/^\s*pending\s*$/i).first()],
            ['Progress', 'who has it now', text('Awaiting HR review', { exact: true })],
            ['Withdraw', 'cancel while pending', button(/withdraw/i, {})],
        ],
    },
    {
        id: 'attendance-top', url: '/attendance/my', title: 'My Attendance',
        markers: [
            ['Period', 'today, week, month, quarter, year', (page) => page.getByRole('button', { name: 'Quarter', exact: true }).first().locator('xpath=..')],
            ['Attendance score', null, css('.pa-hcard')],
            ['Clock in / out', 'selfie + location', css('.pa-actbtns > :nth-child(1)')],
            ['Breaks', 'start and end a break', css('.pa-actbtns > :nth-child(2)')],
            ['Regularize', 'fix a missing or wrong punch', css('.pa-actbtns > :nth-child(3)')],
            ['Smart status', 'what to do next', css('.pa-actsmart')],
        ],
    },
    {
        id: 'attendance-timeline', url: '/attendance/my', title: 'Attendance insights and day-by-day history',
        scrollTo: text('Punch In / Out Timeline', { exact: false }),
        markers: [
            ['Insights', 'averages, streaks, late count, missing punches', text('AI Attendance Insights', { exact: true })],
            ['Suggestions', null, text('Suggestions', { exact: false })],
            ['Timeline', 'one row per day; click a day for its punches', text('Punch In / Out Timeline', { exact: false })],
            ['Export', 'download your log', (page) => page.getByRole('button', { name: /export/i }).last()],
        ],
    },
    {
        id: 'attendance-regularise', url: '/attendance/my', title: 'Requesting an attendance correction',
        prepare: openModal(css('.pa-actbtns > :nth-child(3)'), 'Request Regularization'),
        markers: [
            ['Day', null, field('Which day?')],
            ['What to fix', 'a punch, or a half day', field('What do you need to fix?')],
            ['Which punch', 'IN, OUT or both', field('Which punch is missing')],
            ['Correct time', null, field('Expected time')],
            ['Reason & proof', 'attachment optional', field('Reason & proof')],
            ['Approval route', 'manager → HR → admin', field('What happens next')],
        ],
    },
    {
        id: 'leave-top', url: '/time-off/my', title: 'My Time Off',
        markers: [
            ['Apply Leave', null, button('Apply Leave')],
            ['Your summary', 'available, pending, approved, next holiday', (page) => page.getByText(/available leave/i).first().locator("xpath=ancestor::div[contains(@class,'grid')][1]")],
            ['Awaiting approval', 'requests still with a reviewer', card('leave request awaiting approval', { exact: false })],
            ['Balance views', 'balances, pending & upcoming, history, statement', (page) => scope(page).getByRole('button', { name: 'My Balances', exact: true }).first().locator('xpath=..')],
            ['Leave year', 'switch to a previous year', (page) => scope(page).getByText('Leave year', { exact: true }).first().locator('xpath=..')],
        ],
    },
    {
        id: 'leave-balances', url: '/time-off/my', title: 'My Balances: one card per leave type',
        scrollTo: (page) => scope(page).getByRole('button', { name: 'My Balances', exact: true }).first(),
        markers: [
            ['Available to request', 'what you can apply for now', (page) => scope(page).getByText('Available to request', { exact: true }).first().locator('xpath=..')],
            ['Approved balance', 'before pending requests', (page) => scope(page).getByText('Approved balance', { exact: true }).first().locator('xpath=..')],
            ['Breakdown', 'base, carry forward, add-on, used, pending…', (page) => scope(page).getByText('Base Entitlement', { exact: true }).first().locator('xpath=ancestor::dl[1]')],
            ['Leave year dates', null, (page) => scope(page).getByText(/^Leave year \d/).first()],
            ['Card actions', 'apply, history, statement', (page) => scope(page).getByRole('button', { name: 'View History', exact: true }).first().locator('xpath=..')],
        ],
    },
    {
        id: 'leave-pending-upcoming', url: '/time-off/my', title: 'Pending & Upcoming: where each request is now',
        scrollTo: (page) => scope(page).getByRole('button', { name: 'My Balances', exact: true }).first(),
        prepare: async (page) => {
            await scope(page).getByRole('button', { name: 'Pending & Upcoming', exact: true }).first().click();
            await expect(scope(page).getByText('Current Stage', { exact: true })).toBeVisible({ timeout: 10000 });
            await page.waitForTimeout(400);
        },
        markers: [
            ['Counts', 'pending, needs information, upcoming, rejected, encashment', (page) => scope(page).getByText('Needs Information', { exact: true }).first().locator("xpath=ancestor::div[contains(@class,'grid')][1]")],
            ['Current stage', 'awaiting manager, awaiting HR…', (page) => scope(page).getByText('Awaiting manager', { exact: true }).first()],
        ],
    },
    {
        id: 'leave-history', url: '/time-off/my', title: 'Transaction History: every credit and deduction',
        scrollTo: (page) => scope(page).getByRole('button', { name: 'My Balances', exact: true }).first(),
        prepare: async (page) => {
            await scope(page).getByRole('button', { name: 'Transaction History', exact: true }).first().click();
            await expect(scope(page).locator('select', { hasText: 'All leave types' })).toBeVisible({ timeout: 10000 });
            await page.waitForTimeout(400);
        },
        markers: [
            ['Filters', 'leave type and month', (page) => scope(page).locator('select', { hasText: 'All leave types' }).first().locator('xpath=..')],
            ['Movement', 'date, what happened, + or − days', (page) => scope(page).locator('select', { hasText: 'All leave types' }).first().locator('xpath=../following-sibling::ol[1]/li[1]')],
        ],
    },
    {
        id: 'leave-statement', url: '/time-off/my', title: 'Month-wise Statement',
        scrollTo: (page) => scope(page).getByRole('button', { name: 'My Balances', exact: true }).first(),
        prepare: async (page) => {
            await scope(page).getByRole('button', { name: 'Month-wise Statement', exact: true }).first().click();
            await page.waitForTimeout(1200);
        },
        markers: [
            ['Leave type', null, (page) => scope(page).getByRole('button', { name: 'Month-wise Statement', exact: true }).first().locator('xpath=ancestor::div[2]/following-sibling::*[1]//select')],
            ['Statement', 'opening, movements and closing by month', (page) => scope(page).getByRole('button', { name: 'Month-wise Statement', exact: true }).first().locator('xpath=ancestor::div[2]/following-sibling::*[1]')],
        ],
    },
    {
        id: 'leave-apply', url: '/time-off/my', title: 'Applying for leave',
        prepare: openModal(button('Apply Leave'), 'Request Time Off'),
        markers: [
            ['Leave type', 'your balance shows below', field('Leave Type')],
            ['Dates', null, (page) => scope(page).getByText('Start Date', { exact: true }).first().locator("xpath=ancestor::div[contains(@class,'grid')][1]")],
            ['Half day', null, (page) => scope(page).locator('#half_day_toggle').locator('xpath=ancestor::div[1]')],
            ['Reason', null, field('Reason', { exact: true })],
            ['Attachment', 'medical certificate etc.', (page) => scope(page).getByText('Attachment', { exact: true }).first().locator('xpath=ancestor::div[2]')],
            ['Submit', null, (page) => scope(page).locator('button[type=submit]').first()],
        ],
    },
    {
        id: 'leave-applications', url: '/time-off/my', title: 'Tracking your leave applications',
        scrollTo: button('Leave Applications', { exact: false }),
        markers: [
            ['Filters', 'search, status, type, year', (page) => page.getByPlaceholder(/search by/i).first().locator('xpath=..')],
            ['Status', 'with approval progress dots', (page) => scope(page).locator('table span.rounded-full', { hasText: /pending/i }).first()],
            ['Reviewer', null, (page) => scope(page).locator('table').getByText('Jordan Reed').first()],
            ['Cancel', 'withdraw the request', (page) => scope(page).locator('table').getByRole('button', { name: /cancel/i }).first()],
            ['Encashment history', null, button('Encashment History', { exact: false })],
        ],
    },
    {
        id: 'leave-calendar', url: '/time-off/my', title: 'Leave calendar and policy explorer',
        scrollTo: text('Leave Forecast', { exact: false }),
        markers: [
            ['Holiday work', 'ask to work on a holiday', card('Holiday Work', { exact: false })],
            ['Policy explorer', 'your leave rules', card('Policy Explorer', { exact: false })],
            ['Leave calendar', 'approved, pending, holidays', text('Leave Calendar', { exact: false })],
        ],
    },
    {
        id: 'wfh', url: '/wfh/my', title: 'Work From Home',
        markers: [
            ['Request WFH', null, button('Request WFH', { exact: false })],
            ['Totals', null, card('Total Requests', { exact: false })],
            ['Filters', null, (page) => page.getByRole('combobox').first().locator("xpath=ancestor::*[contains(@class,'rounded')][1]")],
            ['Status', null, text('PENDING', { exact: false })],
            ['Cancel', 'while pending', button('Cancel')],
        ],
    },
    {
        id: 'wfh-request', url: '/wfh/my', title: 'Requesting work from home',
        prepare: openModal(button('Request WFH', { exact: false }), 'Reason'),
        markers: [
            ['Dates', null, (page) => scope(page).getByText('Start Date', { exact: true }).first().locator("xpath=ancestor::div[contains(@class,'grid')][1]")],
            ['Half day', null, text('Half day')],
            ['Reason', null, field('Reason', { exact: true })],
        ],
    },
    {
        id: 'overtime', url: '/overtime/my', title: 'My Overtime',
        markers: [
            ['Request OT', null, button('Request OT', { exact: false })],
            ['Approved OT hours', null, card('OT Hours', { exact: false })],
            ['Status', null, text('APPROVED', { exact: false })],
            ['Reviewer', null, text('Jordan Reed')],
        ],
    },
    {
        id: 'overtime-request', url: '/overtime/my', title: 'Requesting overtime',
        prepare: openModal(button('Request OT', { exact: false }), 'Work Date'),
        markers: [
            ['Work date', null, field('Work Date')],
            ['Start and end time', null, (page) => scope(page).getByText('OT Start Time').first().locator("xpath=ancestor::div[contains(@class,'grid')][1]")],
            ['Reason', null, field('Reason for OT')],
        ],
    },
    {
        id: 'payslips', url: '/payroll/my-payslips', title: 'My Payslips',
        scrollTo: (page) => scope(page).getByText(/current monthly/i).first(),
        markers: [
            ['Pay summary', 'monthly salary, CTC, last payslip, deductions', (page) => page.getByText(/current monthly/i).first().locator("xpath=ancestor::div[contains(@class,'grid')][1]")],
            ['Current payslip', 'earnings, deductions, net pay', card('Current Payslip (', { exact: false })],
            ['Download / email', 'PDF, email, salary structure', (page) => scope(page).getByText('Quick Actions', { exact: false }).last().locator('xpath=following-sibling::*[1]')],
            ['Payslip history', 'tick months to print together', text('Payslip History')],
        ],
    },
    {
        id: 'expenses', url: '/operations/expenses', title: 'Expense claims',
        markers: [
            ['New Claim', null, button('New Claim', { exact: false })],
            ['Filters', null, (page) => page.getByPlaceholder(/search title/i).first().locator("xpath=ancestor::*[contains(@class,'rounded')][1]")],
            ['Pending claim', null, (page) => scope(page).locator('table').getByText(/^\s*pending\s*$/i).first()],
            ['Approved claim', 'reimbursed through payroll', (page) => scope(page).locator('table').getByText(/^\s*approved\s*$/i).first()],
        ],
    },
    {
        id: 'expense-new', url: '/operations/expenses', title: 'Submitting an expense claim',
        prepare: openModal(button('New Claim', { exact: false }), 'Amount'),
        markers: [
            ['Title', null, field('Title', { exact: true })],
            ['Category', null, field('Category', { exact: true })],
            ['Amount', null, field('Amount', { exact: false })],
            ['Expense date', null, field('Expense Date')],
            ['Receipt', 'PDF, JPG or PNG', (page) => scope(page).locator('input[type=file]').first().locator('xpath=..')],
            ['Submit', null, button('Submit Claim', { exact: false })],
        ],
    },
    {
        id: 'performance', url: '/performance/dashboard', title: 'My Performance',
        markers: [
            ['Cycle score', 'appears once a review cycle includes you', text('No performance cycle data', { exact: false })],
            ['Performance timeline', null, text('Performance Timeline')],
            ['Warnings summary', null, text('Active Warnings', { exact: false })],
        ],
    },
    {
        id: 'my-review', url: '/performance/my', title: 'My Review (self-assessment)',
        markers: [
            ['Active assessments', 'open reviews you need to complete', text('Active Assessments')],
            ['Past reviews', 'finished, locked reviews', text('Past Reviews')],
        ],
    },
    {
        id: 'goals', url: '/performance/goals', title: 'My Goals',
        markers: [
            ['Add Goal', null, button('Add Goal', { exact: false })],
            ['Active goal', 'tick the circle when done', card('Complete the AWS Cloud Practitioner', { exact: false })],
            ['Completed', null, text('Completed')],
        ],
    },
    {
        id: 'goal-new', url: '/performance/goals', title: 'Adding a goal',
        prepare: openModal(button('Add Goal', { exact: false }), 'Goal Title'),
        markers: [
            ['Goal title', null, field('Goal Title')],
            ['How it is measured', null, field('Description / Metrics')],
            ['Target date', 'optional', field('Target Date')],
        ],
    },
    {
        id: 'documents', url: '/documents', title: 'Documents',
        markers: [
            ['Upload My Document', null, button('Upload My Document', { exact: false })],
            ['Search and filter', null, (page) => page.getByPlaceholder(/search documents/i).first().locator('xpath=ancestor::div[2]')],
            ['Library', 'documents shared with you', button('Library')],
        ],
    },
    {
        id: 'onboarding', url: '/my-onboarding', title: 'My Onboarding',
        markers: [
            ['Progress', null, (page) => page.getByText('My Onboarding', { exact: true }).last().locator("xpath=ancestor::*[contains(@class,'rounded')][1]")],
            ['Your tasks', 'tick when done', card('Your tasks', { exact: false })],
            ['Handled for you', 'other teams set these up', card(/^\s*handled for you\s*$/i, {})],
        ],
    },
    {
        id: 'notifications', url: '/notifications', title: 'Inbox (notifications)',
        markers: [
            ['Mark all read', null, button('Mark All Read', { exact: false })],
            ['Filters', 'all, unread, read, priority, dates', (page) => page.getByRole('button', { name: /^Unread/ }).first().locator('xpath=..')],
            ['Unread notification', 'blue edge and dot', text('Leave request submitted')],
            ['View', 'open the related page', (page) => page.getByRole('button', { name: /^View/ }).first().or(page.getByRole('link', { name: /^View/ }).first()).first()],
        ],
    },
];

// ── Capture ────────────────────────────────────────────────────────────────

async function login(page) {
    await page.goto('/login');
    await page.fill('input[name=email]', EMAIL);
    await page.fill('input[name=password]', PASSWORD);
    await Promise.all([
        page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 30000 }),
        page.click('[data-test=login-button]'),
    ]);
}

/** Replace org-wide values that could name a real colleague. */
async function scrub(page) {
    await page.evaluate(() => {
        const label = [...document.querySelectorAll('*')].find((el) => el.childElementCount === 0 && el.textContent.trim() === 'Cycle');
        const value = label?.nextElementSibling?.textContent.trim() ?? label?.parentElement?.nextElementSibling?.textContent.trim();
        if (!value || value === '—') {
            return;
        }
        const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
        while (walker.nextNode()) {
            if (walker.currentNode.textContent.trim() === value) {
                walker.currentNode.textContent = 'Annual Review 2026';
            }
        }
    });
}

async function drawMarkers(page, boxes) {
    await page.evaluate((items) => {
        const layer = document.createElement('div');
        layer.id = '__guide_markers';
        // A manual popover joins the browser's top layer, so the markers sit
        // above open <dialog>s and dropdown popovers, which z-index cannot beat.
        layer.setAttribute('popover', 'manual');
        layer.style.cssText = 'position:fixed;inset:0;width:100vw;height:100vh;margin:0;padding:0;border:0;background:transparent;overflow:visible;pointer-events:none;';
        items.forEach(({ x, y, width, height }, i) => {
            const pad = 4;
            const frame = document.createElement('div');
            frame.style.cssText = `position:absolute;left:${x - pad}px;top:${y - pad}px;width:${width + pad * 2}px;height:${height + pad * 2}px;border:3px solid #f97316;border-radius:12px;box-shadow:0 0 0 4px rgba(249,115,22,.18);`;
            const badge = document.createElement('div');
            const bx = Math.min(Math.max(x - pad - 14, 4), window.innerWidth - 34);
            const by = Math.min(Math.max(y - pad - 14, 4), window.innerHeight - 34);
            badge.textContent = String(i + 1);
            badge.style.cssText = `position:absolute;left:${bx}px;top:${by}px;width:30px;height:30px;border-radius:999px;background:#f97316;color:#fff;font:800 15px/30px system-ui,sans-serif;text-align:center;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.35);`;
            layer.append(frame, badge);
        });
        document.body.append(layer);
        layer.showPopover();
    }, boxes);
}

async function capture(page, shot) {
    page.__scope = undefined;
    const response = await page.goto(shot.url, { waitUntil: 'networkidle' });
    const status = response?.status();
    const landed = new URL(page.url()).pathname;

    if (status !== 200 || landed !== shot.url) {
        throw new Error(`[${shot.id}] ${shot.url} answered ${status} and landed on ${landed}. An Employee should be able to open this page — check the route guards before regenerating the guide.`);
    }

    await page.waitForTimeout(1200);

    if (shot.scrollTo) {
        const target = shot.scrollTo(page);
        await expect(target, `[${shot.id}] scroll target missing`).toBeVisible({ timeout: 10000 });
        await target.evaluate((el) => {
            el.scrollIntoView({ block: 'start' });
            window.scrollBy(0, -90);
        });
        await page.waitForTimeout(900);
    }

    if (shot.prepare) {
        await shot.prepare(page);
    }

    await scrub(page);
    await page.mouse.move(0, 0);
    await page.waitForTimeout(300);

    const viewport = page.viewportSize();
    const boxes = [];
    for (const [label, , locate] of shot.markers) {
        const locator = await locate(page);
        await expect(locator, `[${shot.id}] marker "${label}" not found — the page changed`).toBeVisible({ timeout: 10000 });
        const box = await locator.boundingBox();
        if (!box || box.y + 10 > viewport.height || box.y + box.height < 10) {
            throw new Error(`[${shot.id}] marker "${label}" is outside the captured area`);
        }
        boxes.push({
            x: Math.max(box.x, 2),
            y: Math.max(box.y, 2),
            width: Math.min(box.width, viewport.width - Math.max(box.x, 2) - 2),
            height: Math.min(box.height, viewport.height - Math.max(box.y, 2) - 2),
        });
    }

    // Originals only back the lightbox's "Show original", so JPEG keeps the
    // repo small; the annotated copies the guide displays stay lossless PNG.
    const file = `${shot.id}.jpg`;
    await page.screenshot({ path: path.join(OUT_DIR, file), type: 'jpeg', quality: 85, animations: 'disabled' });

    await drawMarkers(page, boxes);
    await page.screenshot({ path: path.join(OUT_DIR, 'annotated', `${shot.id}.png`), animations: 'disabled' });
    await page.evaluate(() => document.getElementById('__guide_markers')?.remove());

    return {
        file,
        annotated: `annotated/${shot.id}.png`,
        title: shot.title,
        width: viewport.width,
        height: viewport.height,
        markers: shot.markers.map(([label, note]) => (note ? { label, note } : { label })),
    };
}

test('capture Employee Guide screenshots', async ({ browser, baseURL }) => {
    fs.mkdirSync(path.join(OUT_DIR, 'annotated'), { recursive: true });

    const only = process.env.GUIDE_ONLY ? process.env.GUIDE_ONLY.split(',') : null;
    const manifest = fs.existsSync(MANIFEST) ? JSON.parse(fs.readFileSync(MANIFEST, 'utf8')) : { shots: {} };
    const desktop = await browser.newContext({ baseURL, viewport: { width: 1440, height: 900 }, colorScheme: 'light' });
    const page = await desktop.newPage();
    await login(page);

    const state = await desktop.storageState();
    const mobile = await browser.newContext({ baseURL, storageState: state, viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, colorScheme: 'light' });
    const mobilePage = await mobile.newPage();

    const ordered = {};
    for (const shot of SHOTS) {
        if (only && !only.includes(shot.id)) {
            if (manifest.shots[shot.id]) {
                ordered[shot.id] = manifest.shots[shot.id];
            }
            continue;
        }
        await test.step(shot.id, async () => {
            ordered[shot.id] = await capture(shot.mobile ? mobilePage : page, shot);
            // Saved after every shot so a later failure keeps what already worked.
            fs.writeFileSync(MANIFEST, JSON.stringify({ ...manifest, shots: { ...manifest.shots, ...ordered } }, null, 2) + '\n');
        });
    }

    manifest.shots = ordered;
    manifest.captured_at = new Date().toISOString();
    manifest.account = 'Employee role demo account (php artisan guide:demo-employee); all data fictional';
    fs.writeFileSync(MANIFEST, JSON.stringify(manifest, null, 2) + '\n');

    await mobile.close();
    await desktop.close();
});
