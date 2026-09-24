<?php

use App\Http\Controllers\Api\Admin\AppointmentController as AdminAppointmentController;
use App\Http\Controllers\Api\Admin\AppointmentStatusController;
use App\Http\Controllers\Api\Admin\AuditLogController;
use App\Http\Controllers\Api\Admin\BlogCategoryController;
use App\Http\Controllers\Api\Admin\BlogController as AdminBlogController;
use App\Http\Controllers\Api\Admin\CustomerController as AdminCustomerController;
use App\Http\Controllers\Api\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Api\Admin\DealerTierController;
use App\Http\Controllers\Api\Admin\DoctorController as AdminDoctorController;
use App\Http\Controllers\Api\Admin\DoctorInvitationController;
use App\Http\Controllers\Api\Admin\DoctorScheduleController;
use App\Http\Controllers\Api\Admin\DoctorServiceController;
use App\Http\Controllers\Api\Admin\DoctorTimeOffController;
use App\Http\Controllers\Api\Admin\InventoryController;
use App\Http\Controllers\Api\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Api\Admin\ProductImageController;
use App\Http\Controllers\Api\Admin\ProductMasterController;
use App\Http\Controllers\Api\Admin\ProductVariantController;
use App\Http\Controllers\Api\Admin\ProductWizardController;
use App\Http\Controllers\Api\Admin\RetailPriceItemController;
use App\Http\Controllers\Api\Admin\RetailPriceListController;
use App\Http\Controllers\Api\Admin\SalesOrderController;
use App\Http\Controllers\Api\Admin\ServiceCategoryController;
use App\Http\Controllers\Api\Admin\ServiceController as AdminServiceController;
use App\Http\Controllers\Api\Admin\StaffController;
use App\Http\Controllers\Api\Admin\StockMovementController;
use App\Http\Controllers\Api\Admin\WarehouseController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AvailableSlotController;
use App\Http\Controllers\Api\BlogController;
use App\Http\Controllers\Api\DoctorController;
use App\Http\Controllers\Api\DoctorPasswordSetupController;
use App\Http\Controllers\Api\GuestAppointmentController;
use App\Http\Controllers\Api\GuestBookingVerificationController;
use App\Http\Controllers\Api\LoyaltyController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProductCatalogController;
use App\Http\Controllers\Api\PublicReviewController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\ServiceCategoryController as PublicServiceCategoryController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\Staff\DoctorPortalController;
use App\Http\Controllers\Api\Staff\ReceptionistAppointmentController;
use App\Http\Controllers\Api\VoucherController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/auth/setup-password', DoctorPasswordSetupController::class)
    ->middleware('throttle:doctor-password-setup')
    ->name('auth.setup-password');

Route::apiResource('services', ServiceController::class)->only(['index', 'show']);
Route::get('/products', [ProductCatalogController::class, 'index'])->name('products.index');
Route::get('/product-filters', [ProductCatalogController::class, 'filters'])->name('products.filters');
Route::get('/products/{product}', [ProductCatalogController::class, 'show'])->name('products.show');
Route::get('/service-categories', [PublicServiceCategoryController::class, 'index'])
    ->name('service-categories.index');
Route::get('/service-categories/{serviceCategory:slug}', [PublicServiceCategoryController::class, 'show'])
    ->name('service-categories.show');
Route::get('/service-categories/{serviceCategory:slug}/services/{service}', [PublicServiceCategoryController::class, 'showService'])
    ->name('service-categories.services.show');
Route::apiResource('doctors', DoctorController::class)->only(['index', 'show']);
Route::get('/doctors/{doctor}/reviews', [PublicReviewController::class, 'doctor'])->name('doctors.reviews');
Route::get('/services/{service}/reviews', [PublicReviewController::class, 'service'])->name('services.reviews');
Route::get('/blogs', [BlogController::class, 'index'])->name('blogs.index');
Route::get('/blogs/{blog:slug}', [BlogController::class, 'show'])->name('blogs.show');
Route::get('/doctors/{doctor}/available-slots', AvailableSlotController::class)
    ->name('doctors.available-slots');

Route::post('/guest-booking/request-otp', [GuestBookingVerificationController::class, 'requestOtp'])
    ->middleware('throttle:guest-otp-request')
    ->name('guest-booking.request-otp');
Route::post('/guest-booking/verify-otp', [GuestBookingVerificationController::class, 'verifyOtp'])
    ->middleware('throttle:guest-otp-verify')
    ->name('guest-booking.verify-otp');
Route::post('/appointments', [AppointmentController::class, 'store'])
    ->middleware(['optional.sanctum', 'throttle:guest-booking'])
    ->name('appointments.store');
Route::post('/guest/appointments/lookup', [GuestAppointmentController::class, 'lookup'])
    ->middleware('throttle:guest-lookup')
    ->name('guest.appointments.lookup');
Route::post('/guest/appointments/{bookingCode}/reschedule-slots', [GuestAppointmentController::class, 'rescheduleSlots'])
    ->middleware('throttle:guest-reschedule')
    ->name('guest.appointments.reschedule-slots');
Route::patch('/guest/appointments/{bookingCode}/reschedule', [GuestAppointmentController::class, 'reschedule'])
    ->middleware('throttle:guest-reschedule')
    ->name('guest.appointments.reschedule');
Route::patch('/guest/appointments/{bookingCode}/cancel', [GuestAppointmentController::class, 'cancel'])
    ->middleware('throttle:guest-cancel')
    ->name('guest.appointments.cancel');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/user', [AuthController::class, 'user']);
    Route::patch('/user', [AuthController::class, 'updateProfile']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount'])
        ->name('notifications.unread-count');
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead'])
        ->name('notifications.read');
    Route::patch('/notifications/read-all', [NotificationController::class, 'markAllRead'])
        ->name('notifications.read-all');
});

Route::middleware(['auth:sanctum', 'customer'])->group(function (): void {
    Route::get('/my-appointments', [AppointmentController::class, 'index'])->name('my-appointments.index');
    Route::get('/my-appointments/{appointment}', [AppointmentController::class, 'show'])->name('my-appointments.show');
    Route::get('/my-appointments/{appointment}/reschedule-slots', [AppointmentController::class, 'rescheduleSlots'])
        ->name('my-appointments.reschedule-slots');
    Route::patch('/my-appointments/{appointment}/reschedule', [AppointmentController::class, 'reschedule'])
        ->name('my-appointments.reschedule');
    Route::patch('/my-appointments/{appointment}/cancel', [AppointmentController::class, 'cancel'])
        ->name('my-appointments.cancel');
    Route::post('/my-appointments/{appointment}/review', [ReviewController::class, 'store'])
        ->name('my-appointments.review.store');
    Route::patch('/my-reviews/{review}', [ReviewController::class, 'update'])->name('my-reviews.update');
    Route::post('/my-vouchers/resolve', [VoucherController::class, 'resolve'])->name('my-vouchers.resolve');
    Route::get('/my-vouchers', [VoucherController::class, 'index'])->name('my-vouchers.index');
    Route::get('/my-loyalty', LoyaltyController::class)->name('my-loyalty.show');
});

Route::prefix('admin')->name('admin.')->middleware(['auth:sanctum', 'admin'])->group(function (): void {
    Route::get('/dealer-tiers', [DealerTierController::class, 'index'])->name('dealer-tiers.index');
    Route::post('/dealer-tiers', [DealerTierController::class, 'store'])->name('dealer-tiers.store');
    Route::patch('/dealer-tiers/{tier}', [DealerTierController::class, 'update'])->name('dealer-tiers.update');
    Route::get('/product-wizard/drafts', [ProductWizardController::class, 'index'])->name('product-wizard.drafts.index');
    Route::post('/product-wizard/drafts', [ProductWizardController::class, 'store'])->name('product-wizard.drafts.store');
    Route::get('/product-wizard/drafts/{product}', [ProductWizardController::class, 'show'])->name('product-wizard.drafts.show');
    Route::patch('/product-wizard/drafts/{product}', [ProductWizardController::class, 'update'])->name('product-wizard.drafts.update');
    Route::post('/product-wizard/drafts/{product}/complete', [ProductWizardController::class, 'complete'])->name('product-wizard.drafts.complete');
    Route::get('/product-wizard/sku-availability', [ProductWizardController::class, 'skuAvailability'])->name('product-wizard.sku-availability');
    Route::apiResource('warehouses', WarehouseController::class)->except(['destroy']);
    Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');
    Route::get('/inventory/reconciliation', [InventoryController::class, 'reconciliation'])->name('inventory.reconciliation');
    Route::post('/inventory/opening-stock', [InventoryController::class, 'opening'])->name('inventory.opening-stock');
    Route::post('/inventory/receipts', [InventoryController::class, 'receipt'])->name('inventory.receipts');
    Route::post('/inventory/adjustments', [InventoryController::class, 'adjustment'])->name('inventory.adjustments');
    Route::get('/stock-movements', [StockMovementController::class, 'index'])->name('stock-movements.index');
    Route::get('/stock-movements/{movement}', [StockMovementController::class, 'show'])->name('stock-movements.show');
    Route::get('/product-master/{kind}', [ProductMasterController::class, 'index'])->whereIn('kind', ['categories', 'brands', 'units'])->name('product-master.index');
    Route::post('/product-master/{kind}', [ProductMasterController::class, 'store'])->whereIn('kind', ['categories', 'brands', 'units'])->name('product-master.store');
    Route::get('/product-master/{kind}/{id}', [ProductMasterController::class, 'show'])->whereIn('kind', ['categories', 'brands', 'units'])->name('product-master.show');
    Route::patch('/product-master/{kind}/{id}', [ProductMasterController::class, 'update'])->whereIn('kind', ['categories', 'brands', 'units'])->name('product-master.update');
    Route::apiResource('products', AdminProductController::class)->except(['destroy']);
    Route::post('/products/{product}/variants', [ProductVariantController::class, 'store'])->name('products.variants.store');
    Route::patch('/products/{product}/variants/{variant}', [ProductVariantController::class, 'update'])->name('products.variants.update');
    Route::post('/products/{product}/images', [ProductImageController::class, 'store'])->name('products.images.store');
    Route::patch('/products/{product}/images/{image}', [ProductImageController::class, 'update'])->name('products.images.update');
    Route::delete('/products/{product}/images/{image}', [ProductImageController::class, 'destroy'])->name('products.images.destroy');
    Route::get('/sales-orders/buyers', [SalesOrderController::class, 'buyers'])->name('sales-orders.buyers');
    Route::get('/sales-orders/reservation-reconciliation', [SalesOrderController::class, 'reconciliation'])->name('sales-orders.reservation-reconciliation');
    Route::get('/sales-orders', [SalesOrderController::class, 'index'])->name('sales-orders.index');
    Route::post('/sales-orders', [SalesOrderController::class, 'store'])->name('sales-orders.store');
    Route::get('/sales-orders/{order}', [SalesOrderController::class, 'show'])->name('sales-orders.show');
    Route::post('/sales-orders/{order}/reprice', [SalesOrderController::class, 'reprice'])->name('sales-orders.reprice');
    Route::post('/sales-orders/{order}/confirm', [SalesOrderController::class, 'confirm'])->name('sales-orders.confirm');
    Route::post('/sales-orders/{order}/cancel', [SalesOrderController::class, 'cancel'])->name('sales-orders.cancel');
    Route::post('/sales-orders/{order}/fulfill', [SalesOrderController::class, 'fulfill'])->name('sales-orders.fulfill');
    Route::apiResource('retail-price-lists', RetailPriceListController::class)->except(['destroy'])->parameters(['retail-price-lists' => 'priceList']);
    Route::post('/retail-price-lists/{priceList}/items', [RetailPriceItemController::class, 'store'])->name('retail-price-lists.items.store');
    Route::patch('/retail-price-lists/{priceList}/items/{item}', [RetailPriceItemController::class, 'update'])->name('retail-price-lists.items.update');
    Route::get('/dashboard', AdminDashboardController::class)->name('dashboard');
    Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
    Route::get('/audit-logs/{auditLog}', [AuditLogController::class, 'show'])->name('audit-logs.show');
    Route::scopeBindings()->group(function (): void {
        Route::get('/doctors/{doctor}/schedules', [DoctorScheduleController::class, 'index'])
            ->name('doctors.schedules.index');
        Route::put('/doctors/{doctor}/schedules', [DoctorScheduleController::class, 'replace'])
            ->name('doctors.schedules.replace');
        Route::post('/doctors/{doctor}/schedules', [DoctorScheduleController::class, 'store'])
            ->name('doctors.schedules.store');
        Route::match(['put', 'patch'], '/doctors/{doctor}/schedules/{schedule}', [DoctorScheduleController::class, 'update'])
            ->name('doctors.schedules.update');
        Route::delete('/doctors/{doctor}/schedules/{schedule}', [DoctorScheduleController::class, 'destroy'])
            ->name('doctors.schedules.destroy');

        Route::get('/doctors/{doctor}/time-offs', [DoctorTimeOffController::class, 'index'])
            ->name('doctors.time-offs.index');
        Route::post('/doctors/{doctor}/time-offs', [DoctorTimeOffController::class, 'store'])
            ->name('doctors.time-offs.store');
        Route::match(['put', 'patch'], '/doctors/{doctor}/time-offs/{timeOff}', [DoctorTimeOffController::class, 'update'])
            ->name('doctors.time-offs.update');
        Route::delete('/doctors/{doctor}/time-offs/{timeOff}', [DoctorTimeOffController::class, 'destroy'])
            ->name('doctors.time-offs.destroy');
    });

    Route::put('/doctors/{doctor}/services', [DoctorServiceController::class, 'update'])
        ->name('doctors.services.update');
    Route::post('/doctors/{doctor}/resend-invitation', DoctorInvitationController::class)
        ->middleware('throttle:doctor-invitation')
        ->name('doctors.resend-invitation');
    Route::post('/doctors/{doctor}', [AdminDoctorController::class, 'update'])
        ->name('doctors.update-with-upload');
    Route::get('/appointments', [AdminAppointmentController::class, 'index'])->name('appointments.index');
    Route::get('/appointments/{appointment}', [AdminAppointmentController::class, 'show'])->name('appointments.show');
    Route::patch('/appointments/{appointment}/status', AppointmentStatusController::class)
        ->name('appointments.status.update');
    Route::get('/staff', [StaffController::class, 'index'])->name('staff.index');
    Route::post('/staff', [StaffController::class, 'store'])->name('staff.store');
    Route::get('/staff/{user}', [StaffController::class, 'show'])->name('staff.show');
    Route::patch('/staff/{user}', [StaffController::class, 'update'])->name('staff.update');
    Route::delete('/staff/{user}', [StaffController::class, 'destroy'])->name('staff.destroy');
    Route::apiResource('doctors', AdminDoctorController::class);
    Route::apiResource('services', AdminServiceController::class);
    Route::get('/service-categories', [ServiceCategoryController::class, 'index'])
        ->name('service-categories.index');
    Route::post('/service-categories', [ServiceCategoryController::class, 'store'])
        ->name('service-categories.store');
    Route::apiResource('customers', AdminCustomerController::class)->only(['index', 'show']);
    Route::get('/blogs', [AdminBlogController::class, 'index'])->name('blogs.index');
    Route::post('/blogs', [AdminBlogController::class, 'store'])->name('blogs.store');
    Route::get('/blog-categories', [BlogCategoryController::class, 'index'])->name('blog-categories.index');
    Route::post('/blog-categories', [BlogCategoryController::class, 'store'])->name('blog-categories.store');
    Route::get('/reviews', [App\Http\Controllers\Api\Admin\ReviewController::class, 'index'])->name('reviews.index');
    Route::patch('/reviews/{review}', [App\Http\Controllers\Api\Admin\ReviewController::class, 'update'])->name('reviews.update');
    Route::get('/vouchers/generate-code', [App\Http\Controllers\Api\Admin\VoucherController::class, 'generateCode'])->name('vouchers.generate-code');
    Route::get('/vouchers', [App\Http\Controllers\Api\Admin\VoucherController::class, 'index'])->name('vouchers.index');
    Route::post('/vouchers', [App\Http\Controllers\Api\Admin\VoucherController::class, 'store'])->name('vouchers.store');
    Route::patch('/vouchers/{voucher}/revoke', [App\Http\Controllers\Api\Admin\VoucherController::class, 'revoke'])->name('vouchers.revoke');
    Route::delete('/vouchers/{voucher}', [App\Http\Controllers\Api\Admin\VoucherController::class, 'destroy'])->name('vouchers.destroy');
});

Route::prefix('receptionist')->name('receptionist.')->middleware(['auth:sanctum', 'receptionist'])->group(function (): void {
    Route::get('/dashboard', [ReceptionistAppointmentController::class, 'dashboard'])->name('dashboard');
    Route::get('/appointments', [ReceptionistAppointmentController::class, 'index'])->name('appointments.index');
    Route::get('/appointments/{appointment}', [ReceptionistAppointmentController::class, 'show'])->name('appointments.show');
    Route::patch('/appointments/{appointment}/confirm', [ReceptionistAppointmentController::class, 'confirm'])->name('appointments.confirm');
    Route::patch('/appointments/{appointment}/check-in', [ReceptionistAppointmentController::class, 'checkIn'])->name('appointments.check-in');
    Route::patch('/appointments/{appointment}/complete', [ReceptionistAppointmentController::class, 'complete'])->name('appointments.complete');
    Route::patch('/appointments/{appointment}/no-show', [ReceptionistAppointmentController::class, 'noShow'])->name('appointments.no-show');
    Route::patch('/appointments/{appointment}/cancel', [ReceptionistAppointmentController::class, 'cancel'])->name('appointments.cancel');
    Route::get('/customers', [ReceptionistAppointmentController::class, 'customers'])->name('customers.index');
});

Route::prefix('doctor')->name('doctor.')->middleware(['auth:sanctum', 'doctor'])->group(function (): void {
    Route::get('/dashboard', [DoctorPortalController::class, 'dashboard'])->name('dashboard');
    Route::get('/appointments', [DoctorPortalController::class, 'index'])->name('appointments.index');
    Route::get('/appointments/{appointment}', [DoctorPortalController::class, 'show'])->name('appointments.show');
    Route::patch('/appointments/{appointment}/start', [DoctorPortalController::class, 'start'])->name('appointments.start');
    Route::patch('/appointments/{appointment}/complete', [DoctorPortalController::class, 'complete'])->name('appointments.complete');
    Route::get('/schedule', [DoctorPortalController::class, 'schedule'])->name('schedule');
    Route::get('/reviews', [DoctorPortalController::class, 'reviews'])->name('reviews.index');
});
