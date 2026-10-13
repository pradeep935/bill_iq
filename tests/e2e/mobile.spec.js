import { expect, test } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

const screenshots = path.resolve('storage/app/browser-screenshots');

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
  await page.screenshot({ path: path.join(screenshots, '09-mobile-gst-workspace.png'), fullPage: true });
});
