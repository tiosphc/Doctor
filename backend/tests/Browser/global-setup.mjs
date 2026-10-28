import { chromium } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const backendDirectory = resolve(import.meta.dirname, '../..');
const stateDirectory = resolve(backendDirectory, '../.codex/browser-qa/state');

function artisanOutput(command, ...args) {
  return execFileSync('php', ['artisan', command, '--env=blade_qa', '--no-interaction', ...args], {
    cwd: backendDirectory,
    encoding: 'utf8',
    timeout: 20_000,
  });
}

function requireConfig(key, value) {
  const output = artisanOutput('config:show', key);
  if (!output.includes(value)) {
    throw new Error(`QA preflight failed: ${key} must be ${value}.`);
  }
}

function qaPassword() {
  const file = readFileSync(resolve(backendDirectory, '.env.blade_qa'), 'utf8');
  const line = file.split(/\r?\n/).find((entry) => entry.startsWith('DEMO_ADMIN_PASSWORD='));
  const value = line?.slice('DEMO_ADMIN_PASSWORD='.length).trim();
  if (!value) {
    throw new Error('QA preflight failed: DEMO_ADMIN_PASSWORD is missing.');
  }

  return value.length > 1 && value[0] === value.at(-1) && ["'", '"'].includes(value[0])
    ? value.slice(1, -1)
    : value;
}

function verifyDatabaseIdentity() {
  const code = "echo 'QA_IDENTITY='.json_encode([DB::selectOne('SELECT DATABASE() AS db_name, CURRENT_USER() AS db_user'), App\\Models\\User::query()->whereIn('email', ['admin@demo.local', 'retail1@demo.local', 'dealer1@demo.local'])->count(), App\\Models\\ProductVariant::query()->where('sku', 'DEMO-SKU-01-1')->count()]).PHP_EOL;";
  const output = artisanOutput('tinker', '--execute', code);
  const line = output.split(/\r?\n/).find((entry) => entry.startsWith('QA_IDENTITY='));
  if (!line) {
    throw new Error('QA preflight failed: database identity query returned no result.');
  }

  const [identity, accountCount, skuCount] = JSON.parse(line.slice('QA_IDENTITY='.length));
  if (identity.db_name !== 'aesthetic_clinic_blade_qa'
    || identity.db_user !== 'doctor_blade_qa@127.0.0.1'
    || accountCount !== 3
    || skuCount !== 1) {
    throw new Error('QA preflight failed: database or synthetic fixtures do not match.');
  }
}

async function saveRoleState(browser, role, email, password) {
  const context = await browser.newContext({ baseURL: 'http://127.0.0.1:8001' });
  try {
    const page = await context.newPage();
    const response = await page.goto('/login');
    if (response?.status() !== 200) {
      throw new Error(`QA login page failed for ${role}.`);
    }
    await page.locator('input[name=email]').fill(email);
    await page.locator('input[name=password]').fill(password);
    await page.locator('button[type=submit]').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
    await context.storageState({ path: resolve(stateDirectory, `${role}.json`) });
  } finally {
    await context.close();
  }
}

export default async function globalSetup() {
  requireConfig('app.env', 'blade_qa');
  requireConfig('database.default', 'mysql');
  requireConfig('database.connections.mysql.host', '127.0.0.1');
  requireConfig('database.connections.mysql.database', 'aesthetic_clinic_blade_qa');
  requireConfig('database.connections.mysql.username', 'doctor_blade_qa');
  verifyDatabaseIdentity();

  const response = await fetch('http://127.0.0.1:8001/up');
  if (!response.ok) {
    throw new Error('QA preflight failed: server on port 8001 is not healthy.');
  }

  mkdirSync(stateDirectory, { recursive: true });
  const password = qaPassword();
  const browser = await chromium.launch({ channel: 'chrome' });
  try {
    await saveRoleState(browser, 'admin', 'admin@demo.local', password);
    await saveRoleState(browser, 'retail', 'retail1@demo.local', password);
    await saveRoleState(browser, 'dealer', 'dealer1@demo.local', password);
  } finally {
    await browser.close();
  }
}
