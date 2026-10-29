import { expect, test } from '@playwright/test';
import { resolve } from 'node:path';

const api = 'http://127.0.0.1:8001';
const stateDirectory = resolve(import.meta.dirname, '../../../.codex/browser-qa/react-phase4/state');
const uploadImage = resolve(import.meta.dirname, '../../storage/app/public/products/demo/DEMO-PRD-12.jpg');

async function rolePage(browser, role) {
  const context = await browser.newContext({
    baseURL: 'http://127.0.0.1:5173',
    storageState: resolve(stateDirectory, `${role}.json`),
  });
  return { context, page: await context.newPage() };
}

test('admin creates and edits a service with a publicly readable QA image', async ({ browser }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'One isolated QA write flow.');
  const { context, page } = await rolePage(browser, 'admin');
  const name = `QA Readiness Service ${Date.now()}`;
  try {
    await page.goto('/admin/services');
    await page.getByRole('button', { name: 'Thêm dịch vụ' }).first().click();
    const dialog = page.getByRole('dialog', { name: 'Thêm dịch vụ' });
    await expect(dialog).toBeVisible();
    await dialog.getByLabel('Tên dịch vụ *').fill(name);
    await dialog.getByLabel('Danh mục *').selectOption({ index: 1 });
    await dialog.getByLabel('Thời lượng (phút) *').fill('30');
    await dialog.getByLabel('Giá *').fill('100000');
    await dialog.getByLabel('Ảnh dịch vụ / thumbnail').setInputFiles(uploadImage);
    const created = page.waitForResponse((response) => response.url() === `${api}/api/admin/services` && response.request().method() === 'POST');
    await dialog.getByRole('button', { name: 'Thêm dịch vụ' }).click();
    const createResponse = await created;
    expect(createResponse.status()).toBe(201);
    const service = (await createResponse.json()).data;
    expect(service.name).toBe(name);
    expect(service.image).toMatch(/^http:\/\/127\.0\.0\.1:8001\/(?:blade-qa-)?storage\//);
    expect((await page.request.get(service.image)).status()).toBe(200);

    await page.goto(`/admin/services/${service.id}`);
    await page.getByLabel('Mô tả ngắn').fill('QA service profile updated');
    const updated = page.waitForResponse((response) => response.url() === `${api}/api/admin/services/${service.id}` && response.request().method() === 'POST');
    await page.getByRole('button', { name: 'Lưu thay đổi' }).click();
    expect((await updated).status()).toBe(200);
    await page.reload();
    await expect(page.getByLabel('Mô tả ngắn')).toHaveValue('QA service profile updated');
    expect((await page.request.get(service.image)).status()).toBe(200);
  } finally {
    await context.close();
  }
});

test('admin creates and updates a doctor profile with a readable QA avatar', async ({ browser }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'One isolated QA write flow; invitation uses QA mail log.');
  const { context, page } = await rolePage(browser, 'admin');
  const name = `QA Doctor ${Date.now()}`;
  try {
    await page.goto('/admin/doctors');
    await page.getByRole('button', { name: 'Thêm bác sĩ' }).first().click();
    const dialog = page.getByRole('dialog', { name: 'Thêm bác sĩ mới' });
    await dialog.getByLabel('Tên bác sĩ *').fill(name);
    await dialog.getByLabel('Chuyên môn *').fill('QA Dermatology');
    await dialog.getByLabel('Email đăng nhập *').fill(`qa.doctor.${Date.now()}@demo.local`);
    await dialog.getByLabel('Ảnh bác sĩ').setInputFiles(uploadImage);
    const created = page.waitForResponse((response) => response.url() === `${api}/api/admin/doctors` && response.request().method() === 'POST');
    await dialog.getByRole('button', { name: 'Thêm bác sĩ' }).click();
    const createResponse = await created;
    expect(createResponse.status()).toBe(201);
    const doctor = (await createResponse.json()).data;
    expect(doctor.name).toBe(name);
    expect(doctor.avatar).toMatch(/^http:\/\/127\.0\.0\.1:8001\/(?:blade-qa-)?storage\//);
    expect((await page.request.get(doctor.avatar)).status()).toBe(200);
    await expect(page).toHaveURL(new RegExp(`/admin/doctors/${doctor.id}$`));
    await page.getByLabel('Họ tên bác sĩ *').fill(`${name} Updated`);
    const updated = page.waitForResponse((response) => response.url() === `${api}/api/admin/doctors/${doctor.id}` && response.request().method() === 'POST');
    await page.getByRole('button', { name: 'Lưu thông tin' }).first().click();
    expect((await updated).status()).toBe(200);
    await page.reload();
    await expect(page.getByLabel('Họ tên bác sĩ *')).toHaveValue(`${name} Updated`);
    expect((await page.request.get(doctor.avatar)).status()).toBe(200);
  } finally {
    await context.close();
  }
});

test('customer reschedules and clinic roles complete the same QA appointment', async ({ browser }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'One isolated QA appointment write flow.');
  test.setTimeout(150_000);
  const contexts = [];
  try {
    const customer = await rolePage(browser, 'retail');
    contexts.push(customer.context);
    const { page } = customer;
    const services = (await (await page.request.get(`${api}/api/services`)).json()).data;
    let booking;
    for (const service of services) {
      const doctors = (await (await page.request.get(`${api}/api/doctors?service_id=${service.id}&booking=1`)).json()).data;
      const doctor = doctors.find((entry) => entry.name === 'Doctor Demo 1');
      if (!doctor) continue;
      for (let offset = 2; offset <= 14; offset += 1) {
        const date = new Date(Date.now() + offset * 86_400_000).toISOString().slice(0, 10);
        const slots = await page.request.get(`${api}/api/doctors/${doctor.id}/available-slots?service_id=${service.id}&date=${date}`);
        if (!slots.ok()) continue;
        const time = (await slots.json()).data?.slots?.[0];
        if (time) {
          booking = { service, doctor, date, time };
          break;
        }
      }
      if (booking) break;
    }
    expect(booking, 'QA doctor must have a free slot').toBeTruthy();
    await page.goto('/booking');
    await page.locator('main button').filter({ hasText: booking.service.name }).first().click();
    await page.locator('input[type=date]').fill(booking.date);
    await page.locator('main button').filter({ hasText: booking.time }).first().click();
    await page.getByRole('button', { name: /Tiếp tục/ }).click();
    const created = page.waitForResponse((response) => response.url() === `${api}/api/appointments` && response.request().method() === 'POST');
    await page.getByRole('button', { name: 'Xác nhận đặt lịch' }).click();
    const createResponse = await created;
    expect(createResponse.status()).toBe(201);
    const id = (await createResponse.json()).data.id;

    let replacement;
    for (let offset = 2; offset <= 14; offset += 1) {
      const date = new Date(Date.now() + offset * 86_400_000).toISOString().slice(0, 10);
      if (date === booking.date) continue;
      const result = await page.request.get(`${api}/api/my-appointments/${id}/reschedule-slots?date=${date}`, {
        headers: { Accept: 'application/json', Origin: 'http://127.0.0.1:5173', Referer: 'http://127.0.0.1:5173/' },
      });
      if (!result.ok()) continue;
      const time = (await result.json()).data?.slots?.[0];
      if (time) {
        replacement = { date, time };
        break;
      }
    }
    expect(replacement, 'QA appointment must have a second free slot').toBeTruthy();
    await page.goto(`/account/appointments/${id}`);
    await page.getByRole('button', { name: 'Đổi lịch' }).click();
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel('Ngày khám mới').fill(replacement.date);
    await dialog.getByRole('button', { name: replacement.time, exact: true }).click();
    await dialog.getByRole('button', { name: 'Tiếp tục' }).click();
    const rescheduled = page.waitForResponse((response) => response.url() === `${api}/api/my-appointments/${id}/reschedule` && response.request().method() === 'PATCH');
    await dialog.getByRole('button', { name: 'Xác nhận đổi lịch' }).click();
    const rescheduleResponse = await rescheduled;
    expect(rescheduleResponse.status()).toBe(200);
    expect((await rescheduleResponse.json()).data.appointment_date).toBe(replacement.date);

    const admin = await rolePage(browser, 'admin');
    contexts.push(admin.context);
    await admin.page.goto(`/admin/appointments/${id}`);
    await expect(admin.page.locator('main')).toContainText(booking.service.name);
    await admin.page.locator('main').getByRole('button', { name: 'Xác nhận', exact: true }).click();
    const confirmed = admin.page.waitForResponse((response) => response.url() === `${api}/api/admin/appointments/${id}/status` && response.request().method() === 'PATCH');
    await admin.page.getByRole('alertdialog').getByRole('button', { name: 'Xác nhận lịch' }).click();
    expect((await confirmed).status()).toBe(200);

    const receptionist = await rolePage(browser, 'receptionist');
    contexts.push(receptionist.context);
    await receptionist.page.goto(`/receptionist/appointments/${id}`);
    await receptionist.page.getByRole('button', { name: 'Check-in', exact: true }).click();
    const checkedIn = receptionist.page.waitForResponse((response) => response.url() === `${api}/api/receptionist/appointments/${id}/check-in` && response.request().method() === 'PATCH');
    await receptionist.page.getByRole('alertdialog').getByRole('button', { name: 'Xác nhận check-in' }).click();
    expect((await checkedIn).status()).toBe(200);

    const doctor = await rolePage(browser, 'doctor');
    contexts.push(doctor.context);
    await doctor.page.goto(`/doctor/appointments/${id}`);
    await doctor.page.getByRole('button', { name: 'Bắt đầu khám', exact: true }).click();
    const started = doctor.page.waitForResponse((response) => response.url() === `${api}/api/doctor/appointments/${id}/start` && response.request().method() === 'PATCH');
    await doctor.page.getByRole('alertdialog').getByRole('button', { name: 'Bắt đầu khám' }).click();
    expect((await started).status()).toBe(200);
    await doctor.page.getByRole('button', { name: 'Hoàn thành khám', exact: true }).click();
    const treated = doctor.page.waitForResponse((response) => response.url() === `${api}/api/doctor/appointments/${id}/complete` && response.request().method() === 'PATCH');
    await doctor.page.getByRole('alertdialog').getByRole('button', { name: 'Hoàn thành khám' }).click();
    expect((await treated).status()).toBe(200);

    await receptionist.page.reload();
    await receptionist.page.getByRole('button', { name: 'Hoàn tất lịch hẹn', exact: true }).click();
    const completed = receptionist.page.waitForResponse((response) => response.url() === `${api}/api/receptionist/appointments/${id}/complete` && response.request().method() === 'PATCH');
    await receptionist.page.getByRole('alertdialog').getByRole('button', { name: 'Hoàn tất lịch hẹn' }).click();
    const completeResponse = await completed;
    expect(completeResponse.status()).toBe(200);
    expect((await completeResponse.json()).data.status).toBe('completed');
  } finally {
    await Promise.all(contexts.map((context) => context.close()));
  }
});
