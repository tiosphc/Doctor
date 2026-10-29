# Aura Beauty Bookings

I am building the frontend for a premium aesthetic clinic / cosmetic dermatology booking website.

I have uploaded reference UI screenshots generated from Google Stitch.

IMPORTANT:

Use the uploaded screenshots as the PRIMARY VISUAL REFERENCE.

Recreate the visual language, proportions, spacing, typography hierarchy, card styles, image ratios, navigation, section layouts, and responsive behavior shown in the uploaded screenshots.

Do NOT redesign the project into a different style.

However, do not blindly create one static screenshot.

Build a real reusable React frontend based on the reference designs.

==================================================

CORE REQUIREMENT

==================================================

Build BOTH:

1. DESKTOP VERSION

    Target: approximately 1440px

2. MOBILE VERSION

    Target: approximately 390px

Both must look intentionally designed.

Do NOT create a mobile-first layout and simply stretch it to desktop.

Do NOT create a desktop layout and simply shrink it for mobile.

The desktop layout must make proper use of large screen space.

The mobile layout must reorganize content appropriately for small screens.

Use the uploaded desktop screenshots as the reference for desktop.

Use the uploaded mobile screenshots as the reference for mobile.

If screenshots and this prompt differ:

- Use screenshots for visual design

- Use this prompt for features, routes, data structure, and behavior

==================================================

TECHNOLOGY

==================================================

Build the frontend using:

React

TypeScript

Vite

Tailwind CSS

React Router

Use Lucide icons where needed.

Use reusable React components.

Do NOT build a backend yet.

Do NOT integrate Supabase.

Do NOT integrate Firebase.

Do NOT create a real database.

Do NOT implement real authentication yet.

Do NOT implement payment yet.

Use local mock data for all content.

The frontend will later connect to a Laravel REST API.

Therefore, keep data separated from UI components.

Do not hardcode business data directly inside large components.

==================================================

LANGUAGE

==================================================

All visible website text must be Vietnamese.

Code, component names, variable names, and file names should be English.

==================================================

PROJECT CONCEPT

==================================================

This is a premium aesthetic clinic booking website.

Users can:

- Browse clinic services

- View service details

- Browse doctors

- View doctor profiles

- Check appointment availability

- Book appointments WITHOUT logging in

- Look up guest appointments

- Optionally register/login

- Manage appointments after login

- View treatment history

- View membership level

- View personalized loyalty discounts

IMPORTANT:

Guest booking is supported.

Login is OPTIONAL.

Do not force users to login before booking.

Personalized loyalty discounts belong inside the customer account only.

DO NOT create a large promotion section on the public homepage.

==================================================

VISUAL STYLE

==================================================

Follow the uploaded Stitch screenshots closely.

Overall visual direction:

Premium

Luxury

Medical

Elegant

Clean

Minimal

Professional

Trustworthy

Main palette should stay close to the reference design.

Suggested base palette:

Primary Navy: #0D315D

Dark Navy: #082342

White: #FFFFFF

Warm Background: #F8F8F6

Light Gray: #EFEFEF

Primary Text: #222222

Secondary Text: #6B7280

Typography:

Use an elegant serif typeface for large headings.

Prefer:

Playfair Display

or another close match from the screenshots.

Use a modern sans-serif for body/UI.

Prefer:

Inter

Do not make the website look like:

- a generic SaaS dashboard

- an e-commerce marketplace

- a cheap spa website

- a colorful beauty shop

Avoid excessive:

- gradients

- glassmorphism

- rounded pills

- heavy shadows

- colorful icons

- animations

Use subtle premium transitions only.

==================================================

GLOBAL RESPONSIVE LAYOUT

==================================================

Desktop:

Use a centered max-width container around 1200–1280px where appropriate.

Some hero / image areas can extend wider.

Use:

- multi-column sections

- wide photography

- editorial layouts

- generous whitespace

- desktop grids

- horizontal navigation

Mobile:

Use approximately 390px as the primary mobile reference.

Use:

- stacked layouts

- touch-friendly buttons

- readable typography

- appropriate mobile spacing

- mobile drawer navigation

- full-width forms

- no horizontal overflow

Tablet should adapt naturally between both.

==================================================

ROUTING

==================================================

Create the following routes:

/

/services

/services/:slug

/doctors

/doctors/:slug

/booking

/booking/success

/appointment-lookup

/login

/register

/forgot-password

/account

/account/appointments

/account/history

/account/loyalty

/account/profile

Create a proper React Router setup.

==================================================

GLOBAL HEADER

==================================================

DESKTOP:

Use the uploaded desktop screenshot as reference.

Structure:

Clinic logo

Navigation:

Trang chủ

Dịch vụ

Bác sĩ

Về chúng tôi

Tin tức

Right:

Đăng nhập

Primary CTA:

Đặt lịch

The header should feel premium and spacious.

MOBILE:

Create a separate compact header.

Use:

menu button

logo

compact Đặt lịch CTA

Opening the menu should show a drawer containing:

Trang chủ

Dịch vụ

Bác sĩ

Về chúng tôi

Tin tức

Tra cứu lịch hẹn

Đăng nhập

and a strong:

ĐẶT LỊCH

==================================================

HOME PAGE

==================================================

Build the homepage according to the uploaded Stitch design.

Required sections:

1. Hero

2. Featured Services

3. Doctors

4. Why Choose Us

5. Before & After

6. Customer Reviews

7. News / Knowledge

8. Final Booking CTA

9. Footer

Do NOT add a public promotion banner.

---

HERO

---

Example Vietnamese copy:

"Vẻ đẹp tự nhiên bắt đầu từ sự chăm sóc đúng cách"

"Giải pháp thẩm mỹ hiện đại được thiết kế riêng cho bạn."

Buttons:

"Đặt lịch ngay"

"Khám phá dịch vụ"

Desktop:

Follow the reference screenshot.

Use a large editorial composition.

Do not create a narrow centered mobile-style hero.

Mobile:

Recompose the hero based on the mobile screenshot.

Keep CTA clearly visible.

==================================================

FEATURED SERVICES

==================================================

Title:

"Dịch vụ nổi bật"

Create service cards.

Each service:

image

name

short description

duration

price

Xem chi tiết

Đặt lịch

Example mock service:

Laser Toning

"Cải thiện sắc tố và hỗ trợ làn da sáng khỏe."

45 phút

Từ 800.000 VNĐ

Desktop:

Use the same grid proportions as reference.

Mobile:

Use cards appropriate for phone width.

==================================================

DOCTORS

==================================================

Title:

"Đội ngũ bác sĩ"

Each card:

doctor portrait

name

specialization

experience

Xem hồ sơ

Đặt lịch

Example:

BS. Nguyễn Minh Anh

Da liễu thẩm mỹ

8 năm kinh nghiệm

==================================================

WHY CHOOSE US

==================================================

Include:

Bác sĩ chuyên môn cao

Công nghệ hiện đại

Quy trình chuẩn hóa

Chăm sóc cá nhân hóa

Follow the composition from the reference UI.

==================================================

BEFORE & AFTER

==================================================

Title:

"Kết quả khách hàng"

Create attractive result comparison cards.

Include:

Before

After

Service name

Short result description

Keep it professional and medical.

==================================================

REVIEWS

==================================================

Title:

"Khách hàng nói gì về chúng tôi"

Mock content:

★★★★★

"Dịch vụ chuyên nghiệp, bác sĩ tư vấn rất kỹ và dễ hiểu."

Nguyễn H.

Laser Toning

Desktop can display multiple testimonials.

Mobile should be swipe-friendly or stacked according to the reference.

==================================================

NEWS

==================================================

Title:

"Kiến thức & Tin tức"

Each article:

image

category

title

excerpt

date

Đọc thêm

Use mock articles.

==================================================

FOOTER

==================================================

Include:

Clinic brand

short introduction

Links:

Dịch vụ

Bác sĩ

Về chúng tôi

Tin tức

Customer links:

Đặt lịch

Tra cứu lịch hẹn

Đăng nhập

Contact:

Địa chỉ

Điện thoại

Email

Giờ làm việc

Social icons

==================================================

SERVICES PAGE

==================================================

Create a full services listing page.

Title:

"Dịch vụ"

Filters:

Tất cả

Chăm sóc da

Laser

Tiêm thẩm mỹ

Trẻ hóa

Điều trị sắc tố

Desktop:

Use an elegant multi-column grid.

Mobile:

Use horizontal scrollable category filters.

Service cards should reuse the same ServiceCard component used on Home.

==================================================

SERVICE DETAIL PAGE

==================================================

Create route:

/services/:slug

Top section:

large image

service name

short description

duration

starting price

CTA:

"Đặt lịch"

Content sections:

Tổng quan

Lợi ích

Quy trình thực hiện

Đối tượng phù hợp

Các bác sĩ thực hiện

Kết quả khách hàng

Câu hỏi thường gặp

Create FAQ accordions.

Create doctor cards linked to doctor detail pages.

==================================================

DOCTORS PAGE

==================================================

Route:

/doctors

Title:

"Đội ngũ bác sĩ"

Use mock doctor data.

Create a responsive doctors grid.

==================================================

DOCTOR DETAIL PAGE

==================================================

Route:

/doctors/:slug

Show:

doctor portrait

name

specialization

years of experience

introduction

expertise

services performed

CTA:

"Đặt lịch với bác sĩ"

Desktop should use a strong two-column composition.

Mobile should stack naturally.

==================================================

BOOKING

==================================================

This is a major feature.

Do NOT create one long form.

Create a 5-step booking experience:

1. Chọn dịch vụ

2. Chọn bác sĩ

3. Chọn ngày & giờ

4. Thông tin khách hàng

5. Xác nhận

Manage booking state locally for now.

For example:

selectedService

selectedDoctor

selectedDate

selectedTime

customerInformation

==================================================

DESKTOP BOOKING DESIGN

==================================================

Desktop should use a large two-column layout.

Main content:

booking step

Right side:

sticky booking summary

Example:

Main content Appointment summary

Service Dịch vụ

Doctor Bác sĩ

Calendar Ngày

Time Giờ

Form Giá

Use a visible horizontal stepper on desktop.

==================================================

MOBILE BOOKING DESIGN

==================================================

Create a separate mobile composition.

Show:

Bước X / 5

progress bar

current step title

step content

navigation CTA

Do NOT display a desktop sidebar on mobile.

==================================================

STEP 1 — SERVICE

==================================================

Selectable service cards.

Show:

name

description

duration

price

Selected state must be very clear.

==================================================

STEP 2 — DOCTOR

==================================================

Selectable doctor cards.

Also include:

"Không yêu cầu bác sĩ cụ thể"

Description:

"Hệ thống sẽ chọn bác sĩ phù hợp còn lịch."

==================================================

STEP 3 — DATE & TIME

==================================================

Create a calendar UI.

Use mock availability data.

Example structure:

[

{

    date: "2026-09-20",

    slots: [

      { time: "09:00", available: true },

      { time: "10:30", available: true },

      { time: "14:30", available: true },

      { time: "15:30", available: false }

    ]

}

]

Show:

Buổi sáng

Buổi chiều

Selected slot:

navy background

check icon

Unavailable:

disabled

Desktop:

calendar and slots can sit side by side.

Mobile:

slots below calendar.

==================================================

STEP 4 — CUSTOMER INFORMATION

==================================================

IMPORTANT:

Guest booking must work.

Fields:

Họ và tên *

Số điện thoại *

Email

Ghi chú

Message:

"Bạn không cần tạo tài khoản để đặt lịch."

Add frontend validation.

If mock authenticated state is enabled, autofill the customer information.

==================================================

STEP 5 — CONFIRMATION

==================================================

Show a final appointment summary.

Dịch vụ

Bác sĩ

Ngày

Giờ

Thời lượng

Giá dự kiến

Customer:

Họ tên

Số điện thoại

Email

Buttons:

Quay lại

Xác nhận đặt lịch

Submitting currently only simulates success.

Navigate to:

/booking/success

==================================================

BOOKING SUCCESS

==================================================

Show:

success icon

"Đặt lịch thành công"

Example booking code:

BK202609200012

Show:

service

doctor

date

time

Buttons:

"Về trang chủ"

"Tra cứu lịch hẹn"

Then show optional account CTA:

"Quản lý lịch hẹn dễ dàng hơn"

"Tạo tài khoản để xem lịch sử dịch vụ, quản lý lịch hẹn và nhận ưu đãi thành viên."

Buttons:

"Tạo tài khoản"

"Để sau"

Also:

"Bạn đã có tài khoản? Đăng nhập"

Do NOT automatically open registration.

==================================================

APPOINTMENT LOOKUP

==================================================

Route:

/appointment-lookup

Fields:

Mã lịch hẹn

Số điện thoại

Button:

"Tra cứu"

For mock data, use:

BK202609200012

0901234567

Display a sample result.

Include status badges:

Chờ xác nhận

Đã xác nhận

Hoàn thành

Đã hủy

==================================================

LOGIN

==================================================

No real backend authentication yet.

Create the UI only.

Fields:

Email

Mật khẩu

Button:

Đăng nhập

Links:

Quên mật khẩu?

Chưa có tài khoản?

Tạo tài khoản

For demo purposes, clicking login may set a temporary mock authenticated state using localStorage and navigate to /account.

Do not use Supabase Auth.

==================================================

REGISTER

==================================================

Fields:

Họ và tên

Số điện thoại

Email

Mật khẩu

Xác nhận mật khẩu

Button:

Tạo tài khoản

No real account creation yet.

==================================================

CUSTOMER ACCOUNT LAYOUT

==================================================

Desktop:

Use a clean sidebar.

Navigation:

Tổng quan

Lịch hẹn của tôi

Lịch sử dịch vụ

Ưu đãi của tôi

Thông tin cá nhân

Đăng xuất

Mobile:

Do not keep the permanent sidebar.

Create a compact account navigation drawer/dropdown.

==================================================

CUSTOMER DASHBOARD

==================================================

Route:

/account

Title:

"Xin chào, Nguyễn Minh Anh"

Create:

1. Upcoming appointment

2. Membership

3. Current personalized discount

4. Recent treatment history

Example appointment:

Laser Toning

BS. Nguyễn Minh Anh

20/09/2026

14:30

Đã xác nhận

Membership:

GOLD MEMBER

"Bạn đã hoàn thành 7 dịch vụ"

Progress:

7 / 10

"Còn 3 lần để đạt PLATINUM"

Discount:

"GIẢM 10%"

"Áp dụng cho lần đặt lịch tiếp theo"

CTA:

"Đặt lịch"

IMPORTANT:

Only display personalized membership discounts in the authenticated account area.

Do NOT show this discount card on the public homepage.

==================================================

MY APPOINTMENTS

==================================================

Route:

/account/appointments

Filters:

Sắp tới

Hoàn thành

Đã hủy

Create mock appointment cards.

Upcoming actions:

Xem chi tiết

Đổi lịch

Hủy lịch

Completed action:

Đánh giá dịch vụ

==================================================

SERVICE HISTORY

==================================================

Route:

/account/history

Display completed services.

Each item:

service

doctor

date

price

status

Action:

"Đặt lại dịch vụ"

==================================================

LOYALTY

==================================================

Route:

/account/loyalty

Title:

"Hạng thành viên"

Levels:

Member

0–2 lần

0%

Silver

3–5 lần

5%

Gold

6–9 lần

10%

Platinum

10+ lần

15%

Current mock status:

GOLD

7 / 10

"Còn 3 lần để đạt PLATINUM"

Create a premium visual presentation.

Do NOT make it look like a game or achievement system.

==================================================

PROFILE

==================================================

Route:

/account/profile

Fields:

Họ và tên

Số điện thoại

Email

Ngày sinh

Giới tính

Button:

"Lưu thay đổi"

Separate section:

"Đổi mật khẩu"

Use mock behavior only.

==================================================

MOCK DATA

==================================================

Create separate data files.

Example:

src/data/services.ts

src/data/doctors.ts

src/data/reviews.ts

src/data/articles.ts

src/data/appointments.ts

src/data/availability.ts

Do not place all mock data inside page components.

Service type should approximately support:

id

slug

name

shortDescription

description

price

duration

image

category

doctorIds

Doctor type:

id

slug

name

specialization

experienceYears

image

bio

serviceIds

Appointment:

id

bookingCode

service

doctor

date

time

status

==================================================

COMPONENT ARCHITECTURE

==================================================

Create reusable components such as:

Header

DesktopNav

MobileNav

Footer

Container

SectionHeading

Button

Input

Textarea

Select

Badge

Tabs

Modal

Drawer

Accordion

ServiceCard

DoctorCard

ReviewCard

ArticleCard

AppointmentCard

MembershipCard

BookingStepper

BookingSummary

Calendar

TimeSlot

EmptyState

LoadingState

ErrorState

Skeleton

Do not create one giant Home component.

Break sections into maintainable components.

==================================================

SUGGESTED PROJECT STRUCTURE

==================================================

src/

components/

    common/

    layout/

    home/

    services/

    doctors/

    booking/

    account/

pages/

    HomePage.tsx

    ServicesPage.tsx

    ServiceDetailPage.tsx

    DoctorsPage.tsx

    DoctorDetailPage.tsx

    BookingPage.tsx

    BookingSuccessPage.tsx

    AppointmentLookupPage.tsx

    LoginPage.tsx

    RegisterPage.tsx

pages/account/

    AccountDashboardPage.tsx

    AppointmentsPage.tsx

    HistoryPage.tsx

    LoyaltyPage.tsx

    ProfilePage.tsx

data/

hooks/

types/

utils/

==================================================

FUTURE LARAVEL INTEGRATION

==================================================

The backend will later use Laravel REST API.

Prepare the frontend so mock data can later be replaced easily.

Create a simple API service structure such as:

src/services/api.ts

src/services/serviceApi.ts

src/services/doctorApi.ts

src/services/appointmentApi.ts

src/services/authApi.ts

For now these can use mock data or remain prepared for future implementation.

Do not tightly couple components to static data.

==================================================

QUALITY REQUIREMENTS

==================================================

The application must:

- Run without errors

- Have working navigation

- Have working booking step transitions

- Have form validation

- Have selectable services

- Have selectable doctors

- Have selectable dates and times

- Have desktop and mobile layouts

- Have proper responsive breakpoints

- Avoid horizontal overflow

- Use semantic UI structure

- Use reusable components

- Use realistic mock data

- Look polished

==================================================

DO NOT DO THESE

==================================================

Do NOT:

- integrate Supabase

- integrate Firebase

- implement Laravel yet

- implement payment

- build an admin dashboard yet

- add a giant homepage promotion section

- force login before booking

- create only mobile UI

- create only desktop UI

- generate a generic template unrelated to the screenshots

- replace the Stitch design with your own random style

- put everything inside one component

- hardcode all data directly into JSX

- use lorem ipsum

- use English user-facing text

- overuse gradient backgrounds

- overuse glass cards

- overuse rounded pills

==================================================

FINAL GOAL

==================================================

The result should be a polished React frontend closely matching the uploaded Stitch reference designs.

It must provide BOTH:

A professional premium DESKTOP experience

AND

A carefully designed MOBILE experience.

The public site should feel like a real premium Vietnamese aesthetic clinic.

The booking experience should be simple and professional.

Visitors can book as guests.

Accounts are optional.

Logged-in customers can manage their appointments and loyalty benefits.

Build the frontend in a clean way so we can connect it to a Laravel REST API later without rebuilding the UI architecture.

Start by carefully inspecting all uploaded reference screenshots and reproducing their design system consistently across the entire application.

This project was built with [Lovable](https://lovable.dev).

## Build with Lovable

Continue developing this project in the [Lovable editor](https://lovable.dev/projects/7d7a8426-f34b-4e8b-8a1d-bc244b9d1fde).

- **Ship faster**: describe what you want to build and Lovable handles the code.
- **Stay in sync**: every change made in Lovable is committed straight to this repository.
- **Full ownership**: this code is yours. Push to `main` on GitHub and your changes sync back into Lovable, ready for your next prompt.

## Development

Prefer working locally? You need Node.js and npm — [install with nvm](https://github.com/nvm-sh/nvm#installing-and-updating).

```sh
git clone <this-repository-url>
cd <repository-name>
npm i
npm run dev
```
