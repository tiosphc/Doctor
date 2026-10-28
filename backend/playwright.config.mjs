import { defineConfig } from '@playwright/test';
import { resolve } from 'node:path';

const reportDirectory = resolve(import.meta.dirname, '../.codex/browser-qa');

export default defineConfig({
  testDir: './tests/Browser',
  testMatch: '**/*.spec.mjs',
  globalSetup: './tests/Browser/global-setup.mjs',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 60_000,
  expect: { timeout: 10_000 },
  outputDir: resolve(reportDirectory, 'artifacts'),
  reporter: [
    ['list'],
    ['html', { outputFolder: resolve(reportDirectory, 'html'), open: 'never' }],
    ['json', { outputFile: resolve(reportDirectory, 'results.json') }],
  ],
  use: {
    baseURL: 'http://127.0.0.1:8001',
    browserName: 'chromium',
    channel: 'chrome',
    trace: 'retain-on-failure',
  },
  projects: [
    { name: 'desktop-1440', use: { viewport: { width: 1440, height: 900 } } },
    { name: 'tablet-768', use: { viewport: { width: 768, height: 1024 } } },
    { name: 'mobile-390', use: { viewport: { width: 390, height: 844 } } },
  ],
});
