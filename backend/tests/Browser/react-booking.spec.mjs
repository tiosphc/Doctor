import { expect, test } from '@playwright/test';
import { readFileSync, statSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

const api = 'http://127.0.0.1:8001';
const backendDirectory = resolve(import.meta.dirname, '../..');
const retailState = resolve(backendDirectory, '../.codex/browser-qa/react-phase4/state/retail.json');
const logConfig = execFileSync('php', ['artisan', 'config:show', 'logging.channels.single.path', '--env=blade_qa', '--no-interaction'], {
  cwd: backendDirectory,
  encoding: 'utf8',
});
const mailLog = logConfig.match(/\.\.\s+([^\r\n]+laravel\.log)/)?.[1]?.trim();
if (!mailLog || !mailLog.replaceAll('\\', '/').includes('/blade-qa/logs/')) {
  throw new Error('QA mail log is not in the isolated blade-qa storage path.');
}

async function availableBooking(request) {
  const services = await (await request.get(`${api}/api/services`)).json();
  for (const service of services.data) {
    const doctors = await (await request.get(`${api}/api/doctors?service_id=${service.id}&booking=1`)).json();
    const doctor = doctors.data[0];
    if (!doctor) continue;
    for (let offset = 1; offset <= 14; offset += 1) {
      const date = new Date(Date.now() + offset * 86_400_000).toISOString().slice(0, 10);
      const response = await request.get(`${api}/api/doctors/${doctor.id}/available-slots?service_id=${service.id}&date=${date}`);
      if (!response.ok()) continue;
      const slots = (await response.json()).data?.slots ?? [];
      if (slots.length) return { service, doctor, date, time: slots[0] };
    }
  }
  throw new Error('QA fixtures have no available appointment slot in the next 14 days.');
}

async function selectAppointment(page, booking) {
  await page.goto('/booking');
  await page.getByRole('button', { name: new RegExp(booking.service.name) }).first().click();
  const doctorCard = page.getByRole('region', { name: 'Bác sĩ trực tiếp thăm khám' });
  await expect(doctorCard).toContainText(booking.doctor.name);
  await expect(doctorCard).toContainText(booking.doctor.specialty);
  await expect(doctorCard.getByRole('img')).toHaveCount(1);
  await expect(doctorCard.getByRole('button')).toHaveCount(0);
  await page.getByRole('textbox', { name: 'Ngày hẹn' }).fill(booking.date);
  await page.getByRole('button', { name: booking.time, exact: true }).click();
  await page.getByRole('button', { name: 'Tiếp tục' }).click();
  await expect(page.getByRole('heading', { name: 'Hoàn tất lịch hẹn của bạn' })).toBeVisible();
  await expect(page.getByRole('complementary', { name: 'Thông tin lịch hẹn' })).toContainText(booking.doctor.name);
  await expect(page.getByRole('complementary', { name: 'Thông tin lịch hẹn' }).getByRole('img')).toHaveCount(1);
}

test('guest completes the two-step booking with QA mail OTP', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'One QA write flow; responsive coverage is separate.');
  const booking = await availableBooking(page.request);
  const email = `qa.react.booking.${Date.now()}@demo.local`;
  await selectAppointment(page, booking);
  await page.getByRole('textbox', { name: 'Họ và tên *' }).fill('QA React Guest');
  await page.getByRole('textbox', { name: 'Số điện thoại *' }).fill('0900000001');
  await page.getByRole('textbox', { name: 'Email xác minh OTP *' }).fill(email);
  const logSize = statSync(mailLog).size;
  await page.getByRole('button', { name: 'Xác nhận đặt lịch' }).click();
  const dialog = page.getByRole('dialog', { name: 'Xác minh email' });
  await expect(dialog).toBeVisible();
  await expect(dialog.getByRole('status')).toContainText('OTP');
  await expect.poll(() => statSync(mailLog).size).toBeGreaterThan(logSize);
  const appended = readFileSync(mailLog).subarray(logSize).toString('utf8');
  if (!appended.includes(email)) throw new Error('QA mail log did not contain the synthetic recipient.');
  const code = appended.slice(appended.indexOf('Your verification code is:')).match(/\b[0-9]{6}\b/)?.[0];
  if (!code) throw new Error('QA mail OTP could not be parsed; no code was logged to test output.');
  await dialog.getByRole('textbox', { name: /Mã OTP/ }).fill(code);
  const created = page.waitForResponse((response) => response.url() === `${api}/api/appointments` && response.request().method() === 'POST');
  await dialog.getByRole('button', { name: 'Xác minh và đặt lịch' }).click();
  expect((await created).status()).toBe(201);
  await expect(page).toHaveURL(/\/booking\/success$/);
  await expect(page.getByRole('heading', { name: 'Đặt lịch thành công!' })).toBeVisible();
  await expect(page.locator('main')).toContainText(booking.service.name);
  await expect(page.locator('main')).toContainText('Chờ xác nhận');
  await expect(page.getByRole('link', { name: 'Xem lịch hẹn' })).toHaveAttribute('href', '/appointment-lookup');
});

test('customer books and cancels an appointment in React', async ({ browser }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'One QA write flow; responsive coverage is separate.');
  const context = await browser.newContext({ baseURL: 'http://127.0.0.1:5173', storageState: retailState });
  try {
    const page = await context.newPage();
    const booking = await availableBooking(page.request);
    await selectAppointment(page, booking);
    const created = page.waitForResponse((response) => response.url() === `${api}/api/appointments` && response.request().method() === 'POST');
    await page.getByRole('button', { name: 'Xác nhận đặt lịch' }).click();
    const result = await created;
    expect(result.status()).toBe(201);
    const id = (await result.json()).data.id;
    await expect(page).toHaveURL(/\/booking\/success$/);
    await expect(page.getByRole('heading', { name: 'Đặt lịch thành công!' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Xem lịch hẹn' })).toHaveAttribute('href', '/account/appointments');
    await page.goto(`/account/appointments/${id}`);
    await expect(page.locator('main')).toContainText(booking.service.name);
    await page.getByRole('button', { name: 'Hủy lịch' }).click();
    await expect(page.getByRole('alertdialog')).toBeVisible();
    await page.getByRole('alertdialog').getByRole('button', { name: 'Hủy lịch' }).click();
    await expect(page.getByRole('alertdialog')).toHaveCount(0);
    await expect(page.locator('main')).toContainText('Đã hủy');
  } finally {
    await context.close();
  }
});

test('booking API rejects a guest appointment without CSRF token', async ({ page }) => {
  const response = await page.request.post(`${api}/api/appointments`, {
    headers: { Accept: 'application/json', Origin: 'http://127.0.0.1:5173' },
    data: { doctor_id: 1, service_id: 1, appointment_date: '2000-01-01', start_time: '00:00' },
  });
  expect(response.status()).toBe(419);
});
