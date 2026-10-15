import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { login, nextWeekday } from './helpers.js';

/**
 * Sick Leave needs a supporting document. On a phone, the file the employee
 * picks must be uploaded before Submit can be pressed, and a valid file must
 * never be reported as missing. Desktop project; sets phone viewports itself.
 * Screenshots land in storage/logs/e2e-artifacts/leave-attachment.
 */
test.describe.configure({ mode: 'serial' });

const SHOTS = path.resolve('storage/logs/e2e-artifacts/leave-attachment');
fs.mkdirSync(SHOTS, { recursive: true });

// A real JPEG header followed by padding: about the size of a phone photo.
const JPEG = Buffer.from('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=', 'base64');
const DOCUMENT_TYPE = 'Bereavement Leave';
const phonePhoto = (kb) => ({ name: 'IMG_20261015_131904.jpg', mimeType: 'image/jpeg', buffer: Buffer.concat([JPEG, Buffer.alloc(kb * 1024)]) });

async function openDocumentLeaveForm(page, width, weeksAhead = 1) {
    await page.setViewportSize({ width, height: 800 });
    await page.goto('/time-off/my');
    await page.locator('button[wire\\:click="openRequestModal"]').filter({ visible: true }).first().click();
    const modal = page.locator('[data-app-modal]');
    await expect(modal).toBeVisible();

    await modal.getByRole('button', { name: /Select type/ }).click();
    // The e2e company runs the Conexus policy (no separate Sick Leave); Bereavement
    // Leave needs a supporting document the same way.
    await modal.getByText(DOCUMENT_TYPE, { exact: true }).first().click();

    // One request per run: each on its own day, so none overlaps another.
    const day = nextWeekday(3 + 7 * weeksAhead);
    await modal.locator('input[type="date"]').nth(0).fill(day);
    await modal.locator('input[type="date"]').nth(1).fill(day);
    await modal.locator('#leave-reason').fill('Fever and a doctor visit');

    return modal;
}

for (const width of [320, 375, 390, 430]) {
    test(`a picked JPG uploads before Submit can be pressed, then the request goes through (${width}px)`, async ({ page }) => {
        // A phone on mobile data: the upload takes a few seconds.
        await page.route('**/upload-file**', async (route) => { await new Promise((r) => setTimeout(r, 3000)); await route.continue(); });

        await login(page, 'employee');
        const modal = await openDocumentLeaveForm(page, width, 1 + [320, 375, 390, 430].indexOf(width));
        const submit = page.locator('[data-submit-leave]');

        await modal.locator('#leave-attachment').setInputFiles(phonePhoto(2500));
        // While it uploads Submit cannot be pressed and says why.
        await expect(submit).toBeDisabled();
        await expect(submit).toContainText('Uploading attachment');
        await expect(modal.locator('[data-upload-progress]')).toBeVisible();
        await modal.locator('#leave-attachment').scrollIntoViewIfNeeded();
        await page.screenshot({ path: path.join(SHOTS, `uploading-${width}.png`) });

        // Uploaded: the file is shown as ready, Submit works, no false "required".
        await expect(modal.locator('[data-attachment-ready]')).toContainText('IMG_20261015_131904.jpg');
        await expect(modal.locator('[data-attachment-ready]')).toContainText('Uploaded ✓');
        await modal.locator('[data-attachment-ready]').scrollIntoViewIfNeeded();
        await page.screenshot({ path: path.join(SHOTS, `uploaded-${width}.png`) });
        await submit.click();

        await expect(page.getByText(/Leave request submitted/)).toBeVisible({ timeout: 30000 });
        await expect(page.getByText(/attachment is required/i)).toHaveCount(0);
    });
}

test('a missing document is said once, and clears as soon as a valid file is chosen (375px)', async ({ page }) => {
    await login(page, 'employee');
    const modal = await openDocumentLeaveForm(page, 375);
    const submit = page.locator('[data-submit-leave]');

    await submit.click();
    await expect(modal.getByText(`An attachment is required for ${DOCUMENT_TYPE}.`).first()).toBeVisible();

    await modal.locator('#leave-attachment').setInputFiles(phonePhoto(200));
    await expect(modal.locator('[data-attachment-ready]')).toBeVisible();
    await expect(modal.getByText(/attachment is required/i)).toHaveCount(0);
    await modal.locator('[data-attachment-ready]').scrollIntoViewIfNeeded();
    await page.screenshot({ path: path.join(SHOTS, `error-cleared-375.png`) });
});

test('an oversized file is refused on the phone before it is sent (375px)', async ({ page }) => {
    const uploads = [];
    page.on('request', (r) => { if (r.url().includes('upload-file')) uploads.push(r.url()); });

    await login(page, 'employee');
    const modal = await openDocumentLeaveForm(page, 375);

    await modal.locator('#leave-attachment').setInputFiles(phonePhoto(6 * 1024));
    await expect(modal.locator('[data-upload-error]')).toHaveText('Attachment must be 5 MB or smaller.');
    await expect(modal.locator('[data-attachment-ready]')).toHaveCount(0);
    expect(uploads, 'nothing is uploaded').toEqual([]);
    await modal.locator('[data-upload-error]').scrollIntoViewIfNeeded();
    await page.screenshot({ path: path.join(SHOTS, `too-large-375.png`) });
});
