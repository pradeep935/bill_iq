import { expect, test } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

const screenshots = path.resolve('storage/app/browser-screenshots');
const clientFile = 'C:/Users/Dell/Downloads/05AABCP5818M1Z7_GSTR1_AUG-2026_07102026_131943.json';

async function postWithCsrf(page, url, data) {
  return page.evaluate(async ({ url, data }) => {
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    const body = new URLSearchParams();
    Object.entries(data).forEach(([key, value]) => body.append(key, String(value)));
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        'X-CSRF-TOKEN': token || '',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body,
    });
    return response.status;
  }, { url, data });
}

test('mobile onboarding and GST workspace remain usable', async ({ page }) => {
  fs.mkdirSync(screenshots, { recursive: true });
  await page.goto('/login');
  await page.getByPlaceholder('admin@gmail.com').fill('browser.tester@example.test');
  await page.getByPlaceholder('Enter password').fill('browser-safe-password');
  await page.getByRole('button', { name: /Open Dashboard/i }).click();
  await expect(page).toHaveURL(/\/app/);

  await page.goto('/app/setup/companies/onboarding');
  await expect(page.getByRole('textbox', { name: 'Legal Name' })).toHaveValue('PHOOLTAS TRANSRAIL LIMITED');
  await page.screenshot({ path: path.join(screenshots, '08-mobile-onboarding.png'), fullPage: true });

  await page.goto('/app/accounting/gst-imports');
  await expect(page.getByRole('heading', { name: /GST Import Workspace/i })).toBeVisible();
  await postWithCsrf(page, '/app/context', { business_id: 2 });
  await page.goto('/app/accounting/gst-imports');
  await page.getByLabel('Expected filing period').fill('082026');
  await page.locator('input[type="file"]').setInputFiles(clientFile);
  await page.getByRole('button', { name: /Upload & Preview/i }).click();
  await expect(page.getByText(/File parsed for preview|already been uploaded/i)).toBeVisible();
  if (await page.getByText(/already been uploaded/i).isVisible()) {
    await page.getByRole('button', { name: /05AABCP5818M1Z7_GSTR1/i }).first().click();
  }
  await expect(page.getByRole('button', { name: /HSN Summary/i })).toBeVisible();
  await page.screenshot({ path: path.join(screenshots, '09-mobile-gst-workspace.png'), fullPage: true });
});
