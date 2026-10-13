import { expect, test } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

const screenshots = path.resolve('storage/app/browser-screenshots');
const clientFile = 'C:/Users/Dell/Downloads/05AABCP5818M1Z7_GSTR1_AUG-2026_07102026_131943.json';
const php = process.env.BILLIQ_PHP || 'C:/MAMP/bin/php_old/php8.2.14/php.exe';

function phpArgs(script, action) {
  return [
    '-n',
    '-d', 'extension_dir=C:/MAMP/bin/php_old/php8.2.14/ext',
    '-d', 'extension=php_fileinfo.dll',
    '-d', 'extension=php_mbstring.dll',
    '-d', 'extension=php_pdo_sqlite.dll',
    '-d', 'extension=php_sqlite3.dll',
    '-d', 'extension=php_pdo_mysql.dll',
    '-d', 'extension=php_mysqli.dll',
    '-d', 'extension=php_zip.dll',
    '-d', 'extension=php_openssl.dll',
    script,
    action,
  ];
}

function e2eEnv() {
  return {
    ...process.env,
    APP_ENV: 'testing',
    APP_URL: process.env.BILLIQ_BASE_URL || 'http://127.0.0.1:8000',
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: path.resolve('storage/framework/testing/browser-e2e.sqlite'),
    SESSION_DRIVER: 'file',
    CACHE_STORE: 'array',
    QUEUE_CONNECTION: 'sync',
    MAIL_MAILER: 'array',
  };
}

function dbState() {
  return JSON.parse(execFileSync(php, phpArgs('tests/e2e/e2e-db.php', 'state'), { env: e2eEnv(), encoding: 'utf8' }).replace(/(?:PHP )?Warning:[^\n]+\n/g, ''));
}

function resetDb() {
  execFileSync(php, phpArgs('tests/e2e/e2e-db.php', 'reset'), { env: e2eEnv(), encoding: 'utf8' });
}

test.beforeAll(() => {
  resetDb();
});

async function shot(page, name) {
  fs.mkdirSync(screenshots, { recursive: true });
  await page.screenshot({ path: path.join(screenshots, name), fullPage: true });
}

async function login(page) {
  await page.goto('/login');
  await expect(page.getByRole('heading', { name: /Login to Bill IQ/i })).toBeVisible();
  await shot(page, '01-login.png');
  await page.getByPlaceholder('admin@gmail.com').fill('browser.tester@example.test');
  await page.getByPlaceholder('Enter password').fill('browser-safe-password');
  await page.getByRole('button', { name: /Open Dashboard/i }).click();
  await expect(page).toHaveURL(/\/app/);
}

async function uploadFile(page, filePath = clientFile, period = '082026') {
  await page.getByLabel('Expected filing period').fill(period);
  await page.locator('input[type="file"]').setInputFiles(filePath);
  await page.getByRole('button', { name: /Upload & Preview/i }).click();
}

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

test('login, dashboard, onboarding, GST validation, preview, reconciliation and evidence commit', async ({ page }) => {
  const before = dbState().counts;

  await login(page);
  await expect(page.getByText('ABC Retail Pvt Ltd').first()).toBeVisible();
  await shot(page, '02-dashboard.png');

  const forbidden = await postWithCsrf(page, '/app/context', { business_id: 999999 });
  expect(forbidden).toBe(403);

  await page.goto('/app/setup/companies/onboarding');
  await expect(page.getByRole('textbox', { name: 'Legal Name' })).toHaveValue('PHOOLTAS TRANSRAIL LIMITED');
  await expect(page.getByText(/No company will be created from this screen/i)).toBeVisible();
  await shot(page, '03-onboarding-form.png');
  await page.getByRole('button', { name: /Validate Draft/i }).click();
  await expect(page.getByText(/Draft validated\. Company creation still requires explicit approval/i)).toBeVisible();

  await page.goto('/app/accounting/gst-imports');
  await expect(page.getByRole('heading', { name: /GST Import Workspace/i })).toBeVisible();
  await shot(page, '04-gst-import-workspace.png');

  await uploadFile(page);
  await expect(page.getByText(/File GSTIN does not match the selected company GSTIN/i)).toBeVisible();
  await shot(page, '06-validation-errors.png');

  const contextStatus = await postWithCsrf(page, '/app/context', { business_id: 2 });
  expect([200, 302]).toContain(contextStatus);
  await page.goto('/app/accounting/gst-imports');
  await expect(page.locator('body')).toContainText('Disposable GST Fixture Ltd');

  const invalidPeriod = path.resolve('storage/framework/testing/gstr1-wrong-period.json');
  const payload = JSON.parse(fs.readFileSync(clientFile, 'utf8'));
  payload.fp = '072026';
  fs.writeFileSync(invalidPeriod, JSON.stringify(payload));
  await uploadFile(page, invalidPeriod, '082026');
  await expect(page.getByText(/File return period does not match the expected filing period/i)).toBeVisible();

  await uploadFile(page, clientFile, '082026');
  await expect(page.getByText(/File parsed for preview/i)).toBeVisible();
  await expect(page.locator('body')).toContainText('05AABCP5818M1Z7');
  await expect(page.locator('body')).toContainText('082026');
  await expect(page.getByText('17,62,57,540.36')).toBeVisible();
  await expect(page.getByText('gstr1_hsn_summary_evidence').first()).toBeVisible();
  await expect(page.getByText('gstr1_document_issue_evidence').first()).toBeVisible();
  await shot(page, '05-file-preview.png');
  await shot(page, '07-reconciliation-summary.png');

  await uploadFile(page, clientFile, '082026');
  await expect(page.getByText(/already been uploaded/i)).toBeVisible();

  await page.getByRole('button', { name: /Approve Evidence/i }).click();
  await expect(page.getByText(/approved for reconciliation evidence/i)).toBeVisible();
  await page.getByRole('button', { name: /Commit Evidence/i }).click();
  await expect(page.getByText(/committed as GST evidence only/i)).toBeVisible();
  await expect(page.getByText(/committed_as_evidence/i).first()).toBeVisible();

  await page.goto('/app/accounting/gst-imports');
  await expect(page.getByRole('heading', { name: /Import History/i })).toBeVisible();
  await expect(page.locator('body')).toContainText('05AABCP5818M1Z7');

  await page.goto('/logout');
  await expect(page.getByRole('heading', { name: /Login to Bill IQ/i })).toBeVisible();

  const after = dbState().counts;
  expect(after.sales_vouchers).toBe(before.sales_vouchers);
  expect(after.purchase_vouchers).toBe(before.purchase_vouchers);
  expect(after.stock_ledgers).toBe(before.stock_ledgers);
  expect(after.journal_vouchers).toBe(before.journal_vouchers);
  expect(after.products).toBe(before.products);
});
