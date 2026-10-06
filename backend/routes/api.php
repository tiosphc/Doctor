<?php

use App\Http\Controllers\Api\Admin\AppointmentController as AdminAppointmentController;
use App\Http\Controllers\Api\Admin\AppointmentStatusController;
use App\Http\Controllers\Api\Admin\AuditLogController;
use App\Http\Controllers\Api\Admin\BlogCategoryController;
use App\Http\Controllers\Api\Admin\BlogController as AdminBlogController;
use App\Http\Controllers\Api\Admin\CatalogPricingController;
use App\Http\Controllers\Api\Admin\CustomerController as AdminCustomerController;
use App\Http\Controllers\Api\Admin\CustomerPurchaseController;
use App\Http\Controllers\Api\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Api\Admin\DealerAccountController as AdminDealerAccountController;
use App\Http\Controllers\Api\Admin\DealerAccountTierController as AdminDealerAccountTierController;
use App\Http\Controllers\Api\Admin\DealerApplicationController as AdminDealerApplicationController;
use App\Http\Controllers\Api\Admin\DealerTierController;
use App\Http\Controllers\Api\Admin\DealerWalletController as AdminDealerWalletController;
use App\Http\Controllers\Api\Admin\DealerWalletDepositRequestController as AdminDealerWalletDepositRequestController;
use App\Http\Controllers\Api\Admin\DealerWalletTopUpController as AdminDealerWalletTopUpController;
use App\Http\Controllers\Api\Admin\DoctorController as AdminDoctorController;
use App\Http\Controllers\Api\Admin\DoctorInvitationController;
use App\Http\Controllers\Api\Admin\DoctorScheduleController;
use App\Http\Controllers\Api\Admin\DoctorServiceController;
use App\Http\Controllers\Api\Admin\DoctorTimeOffController;
use App\Http\Controllers\Api\Admin\ErpReportController;
use App\Http\Controllers\Api\Admin\GoodsReceiptController;
use App\Http\Controllers\Api\Admin\InventoryController;
use App\Http\Controllers\Api\Admin\PaymentController;
use App\Http\Controllers\Api\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Api\Admin\ProductImageController;
use App\Http\Controllers\Api\Admin\ProductMasterController;
use App\Http\Controllers\Api\Admin\ProductPricingController;
use App\Http\Controllers\Api\Admin\ProductVariantController;
use App\Http\Controllers\Api\Admin\ProductWizardController;
use App\Http\Controllers\Api\Admin\PurchaseOrderController;
use App\Http\Controllers\Api\Admin\PurchaseReturnController;
use App\Http\Controllers\Api\Admin\RefundController;
use App\Http\Controllers\Api\Admin\RetailPriceItemController;
use App\Http\Controllers\Api\Admin\RetailPriceListController;
use App\Http\Controllers\Api\Admin\SalesOrderController;
use App\Http\Controllers\Api\Admin\SalesPromotionController;
use App\Http\Controllers\Api\Admin\SalesReturnController;
use App\Http\Controllers\Api\Admin\SalesVoucherController;
use App\Http\Controllers\Api\Admin\ServiceCategoryController;
use App\Http\Controllers\Api\Admin\ServiceController as AdminServiceController;
use App\Http\Controllers\Api\Admin\StaffController;
use App\Http\Controllers\Api\Admin\StockMovementController;
use App\Http\Controllers\Api\Admin\SupplierController;
use App\Http\Controllers\Api\Admin\WarehouseController;
use App\Http\Controllers\Api\AdministrativeLocationController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AuthDiagnosticController;
use App\Http\Controllers\Api\AvailableSlotController;
use App\Http\Controllers\Api\BlogController;
use App\Http\Controllers\Api\CustomerSalesReturnController;
use App\Http\Controllers\Api\DealerAccountController;
use App\Http\Controllers\Api\DealerAccountTierController;
use App\Http\Controllers\Api\DealerApplicationController;
use App\Http\Controllers\Api\DealerOrderController;
use App\Http\Controllers\Api\DealerOrderImportController;
use App\Http\Controllers\Api\DealerPriceQuoteController;
use App\Http\Controllers\Api\DealerProductController;
use App\Http\Controllers\Api\DealerQuickOrderController;
use App\Http\Controllers\Api\DealerShippingAddressController;
use App\Http\Controllers\Api\DealerWalletController;
use App\Http\Controllers\Api\DealerWalletDepositRequestController;
use App\Http\Controllers\Api\DealerWalletTopUpController;
use App\Http\Controllers\Api\DoctorController;
use App\Http\Controllers\Api\DoctorPasswordSetupController;
use App\Http\Controllers\Api\GiftPromotionCatalogController;
use App\Http\Controllers\Api\GuestAppointmentController;
use App\Http\Controllers\Api\GuestBookingVerificationController;
use App\Http\Controllers\Api\LoyaltyController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PayOsWebhookController;
use App\Http\Controllers\Api\ProductCatalogController;
use App\Http\Controllers\Api\PublicReviewController;
use App\Http\Controllers\Api\RetailCartController;
use App\Http\Controllers\Api\RetailCheckoutController;
use App\Http\Controllers\Api\RetailOrderController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\ServiceCategoryController as PublicServiceCategoryController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\Staff\DoctorPortalController;
use App\Http\Controllers\Api\Staff\ReceptionistAppointmentController;
use App\Http\Controllers\Api\StorageDiagnosticController;
use App\Http\Controllers\Api\VoucherController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
Route::get('/internal/diagnostics/storage', StorageDiagnosticController::class)
    ->middleware('throttle:10,1')
    ->name('internal.diagnostics.storage');
Route::get('/internal/diagnostics/auth', [AuthDiagnosticController::class, 'show'])
    ->middleware('throttle:10,1')
    ->name('internal.diagnostics.auth');
Route::post('/internal/diagnostics/auth/login', [AuthDiagnosticController::class, 'login'])
    ->middleware('throttle:5,1')
    ->name('internal.diagnostics.auth.login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/webhooks/payos', PayOsWebhookController::class)->middleware('throttle:120,1')->name('webhooks.payos');
Route::post('/auth/setup-password', DoctorPasswordSetupController::class)
    ->middleware('throttle:doctor-password-setup')
    ->name('auth.setup-password');

Route::apiResource('services', ServiceController::class)->only(['index', 'show']);
Route::get('/products', [ProductCatalogController::class, 'index'])->name('products.index');
Route::get('/gift-promotions', [GiftPromotionCatalogController::class, 'retail'])->name('gift-promotions.retail');
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
    Route::get('/administrative/provinces', [AdministrativeLocationController::class, 'provinces']);
    Route::get('/administrative/wards', [AdministrativeLocationController::class, 'wards']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::patch('/user', [AuthController::class, 'updateProfile']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/dealer-applications', [DealerApplicationController::class, 'store'])->name('dealer-applications.store');
    Route::get('/dealer-applications/my', [DealerApplicationController::class, 'mine'])->name('dealer-applications.my');
    Route::get('/dealer-applications/{application}', [DealerApplicationController::class, 'show'])->name('dealer-applications.show');
    Route::get('/dealer/accounts', [DealerAccountController::class, 'index'])->name('dealer.accounts.index');
    Route::get('/dealer/accounts/{dealer}', [DealerAccountController::class, 'show'])->name('dealer.accounts.show');
    Route::get('/dealer/accounts/{dealer}/tier', [DealerAccountTierController::class, 'show'])->name('dealer.accounts.tier');
    Route::get('/dealer/accounts/{dealer}/auto-tier', [DealerAccountTierController::class, 'autoTier'])->name('dealer.accounts.auto-tier');
    Route::get('/dealer/accounts/{dealer}/wallet', [DealerWalletController::class, 'show'])->name('dealer.accounts.wallet.show');
    Route::get('/dealer/accounts/{dealer}/wallet/transactions', [DealerWalletController::class, 'transactions'])->name('dealer.accounts.wallet.transactions');
    Route::get('/dealer/accounts/{dealer}/wallet/deposit-requests', [DealerWalletDepositRequestController::class, 'index'])->name('dealer.accounts.wallet.deposit-requests.index');
    Route::post('/dealer/accounts/{dealer}/wallet/deposit-requests', [DealerWalletDepositRequestController::class, 'store'])->name('dealer.accounts.wallet.deposit-requests.store');
    Route::get('/dealer/accounts/{dealer}/wallet/deposit-requests/{depositRequest}/proof', [DealerWalletDepositRequestController::class, 'proof'])->name('dealer.accounts.wallet.deposit-requests.proof');
    Route::get('/dealer/accounts/{dealer}/wallet/top-ups', [DealerWalletTopUpController::class, 'index'])->name('dealer.accounts.wallet.top-ups.index');
    Route::post('/dealer/accounts/{dealer}/wallet/top-ups', [DealerWalletTopUpController::class, 'store'])->name('dealer.accounts.wallet.top-ups.store');
    Route::get('/dealer/accounts/{dealer}/wallet/top-ups/{topUp}', [DealerWalletTopUpController::class, 'show'])->name('dealer.accounts.wallet.top-ups.show');
    Route::post('/dealer/accounts/{dealer}/wallet/top-ups/{topUp}/refresh', [DealerWalletTopUpController::class, 'refresh'])->middleware('throttle:10,1')->name('dealer.accounts.wallet.top-ups.refresh');
    Route::get('/dealer/accounts/{dealer}/products', [DealerProductController::class, 'index'])->name('dealer.accounts.products.index');
    Route::get('/dealer/accounts/{dealer}/shipping-addresses', [DealerShippingAddressController::class, 'index']);
    Route::post('/dealer/accounts/{dealer}/shipping-addresses', [DealerShippingAddressController::class, 'store']);
    Route::put('/dealer/accounts/{dealer}/shipping-addresses/{address}', [DealerShippingAddressController::class, 'update']);
    Route::delete('/dealer/accounts/{dealer}/shipping-addresses/{address}', [DealerShippingAddressController::class, 'destroy']);
    Route::get('/dealer/accounts/{dealer}/gift-promotions', [GiftPromotionCatalogController::class, 'dealer'])->name('dealer.accounts.gift-promotions.index');
    Route::get('/dealer/accounts/{dealer}/products/{product}', [DealerProductController::class, 'show'])->name('dealer.accounts.products.show');
    Route::post('/dealer/accounts/{dealer}/pricing/quote', DealerPriceQuoteController::class)->name('dealer.accounts.pricing.quote');
    Route::post('/dealer/accounts/{dealer}/quick-order/review', [DealerQuickOrderController::class, 'review'])->name('dealer.accounts.quick-order.review');
    Route::post('/dealer/accounts/{dealer}/quick-order', [DealerQuickOrderController::class, 'store'])->name('dealer.accounts.quick-order.store');
    Route::get('/dealer/accounts/{dealer}/order-imports/template', [DealerOrderImportController::class, 'template'])->name('dealer.accounts.order-imports.template');
    Route::get('/dealer/accounts/{dealer}/order-imports', [DealerOrderImportController::class, 'index'])->name('dealer.accounts.order-imports.index');
    Route::post('/dealer/accounts/{dealer}/order-imports', [DealerOrderImportController::class, 'store'])->name('dealer.accounts.order-imports.store');
    Route::get('/dealer/accounts/{dealer}/order-imports/{import}', [DealerOrderImportController::class, 'show'])->name('dealer.accounts.order-imports.show');
    Route::post('/dealer/accounts/{dealer}/order-imports/{import}/revalidate', [DealerOrderImportController::class, 'revalidate'])->name('dealer.accounts.order-imports.revalidate');
    Route::post('/dealer/accounts/{dealer}/order-imports/{import}/confirm', [DealerOrderImportController::class, 'confirm'])->name('dealer.accounts.order-imports.confirm');
    Route::get('/dealer/accounts/{dealer}/orders', [DealerOrderController::class, 'index'])->name('dealer.accounts.orders.index');
    Route::get('/dealer/accounts/{dealer}/orders/{order}', [DealerOrderController::class, 'show'])->name('dealer.accounts.orders.show');
    Route::get('/dealer/accounts/{dealer}/orders/{order}/return-eligibility', [CustomerSalesReturnController::class, 'dealerEligibility'])->name('dealer.accounts.orders.return-eligibility');
    Route::get('/dealer/accounts/{dealer}/orders/{order}/returns', [CustomerSalesReturnController::class, 'dealerIndex'])->name('dealer.accounts.orders.returns.index');
    Route::post('/dealer/accounts/{dealer}/orders/{order}/returns', [CustomerSalesReturnController::class, 'dealerStore'])->name('dealer.accounts.orders.returns.store');
    Route::get('/dealer/accounts/{dealer}/returns/{salesReturn}', [CustomerSalesReturnController::class, 'dealerShow'])->name('dealer.accounts.returns.show');
    Route::post('/dealer/accounts/{dealer}/orders/{order}/cancel', [DealerOrderController::class, 'cancel'])->name('dealer.accounts.orders.cancel');
    Route::prefix('retail')->name('retail.')->group(function (): void {
        Route::get('/cart', [RetailCartController::class, 'show'])->name('cart.show');
        Route::post('/cart/items', [RetailCartController::class, 'add'])->name('cart.items.add');
        Route::patch('/cart/items/{cartItem}', [RetailCartController::class, 'update'])->name('cart.items.update');
        Route::delete('/cart/items/{cartItem}', [RetailCartController::class, 'remove'])->name('cart.items.remove');
        Route::delete('/cart', [RetailCartController::class, 'clear'])->name('cart.clear');
        Route::put('/cart/voucher', [RetailCartController::class, 'voucher'])->name('cart.voucher');
        Route::get('/checkout/review', [RetailCheckoutController::class, 'review'])->name('checkout.review');
        Route::post('/checkout', [RetailCheckoutController::class, 'store'])->name('checkout.store');
        Route::get('/orders', [RetailOrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/{order}', [RetailOrderController::class, 'show'])->name('orders.show');
        Route::get('/orders/{order}/return-eligibility', [CustomerSalesReturnController::class, 'retailEligibility'])->name('orders.return-eligibility');
        Route::get('/orders/{order}/returns', [CustomerSalesReturnController::class, 'retailIndex'])->name('orders.returns.index');
        Route::post('/orders/{order}/returns', [CustomerSalesReturnController::class, 'retailStore'])->name('orders.returns.store');
        Route::get('/returns/{salesReturn}', [CustomerSalesReturnController::class, 'retailShow'])->name('returns.show');
    });
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
    Route::get('/dealer-wallets', [AdminDealerWalletController::class, 'index'])->name('dealer-wallets.index');
    Route::get('/dealer-wallet-transactions', [AdminDealerWalletController::class, 'allTransactions'])->name('dealer-wallet-transactions.index');
    Route::get('/dealer-wallet-top-ups', [AdminDealerWalletTopUpController::class, 'index'])->name('dealer-wallet-top-ups.index');
    Route::get('/dealer-wallet-deposit-requests', [AdminDealerWalletDepositRequestController::class, 'index'])->name('dealer-wallet-deposit-requests.index');
    Route::get('/dealer-wallet-deposit-requests/{depositRequest}', [AdminDealerWalletDepositRequestController::class, 'show'])->name('dealer-wallet-deposit-requests.show');
    Route::get('/dealer-wallet-deposit-requests/{depositRequest}/proof', [AdminDealerWalletDepositRequestController::class, 'proof'])->name('dealer-wallet-deposit-requests.proof');
    Route::post('/dealer-wallet-deposit-requests/{depositRequest}/approve', [AdminDealerWalletDepositRequestController::class, 'approve'])->name('dealer-wallet-deposit-requests.approve');
    Route::post('/dealer-wallet-deposit-requests/{depositRequest}/reject', [AdminDealerWalletDepositRequestController::class, 'reject'])->name('dealer-wallet-deposit-requests.reject');
    Route::get('/dealer-applications', [AdminDealerApplicationController::class, 'index'])->name('dealer-applications.index');
    Route::get('/dealer-applications/{application}', [AdminDealerApplicationController::class, 'show'])->name('dealer-applications.show');
    Route::post('/dealer-applications/{application}/approve', [AdminDealerApplicationController::class, 'approve'])->name('dealer-applications.approve');
    Route::post('/dealer-applications/{application}/reject', [AdminDealerApplicationController::class, 'reject'])->name('dealer-applications.reject');
    Route::get('/dealers', [AdminDealerAccountController::class, 'index'])->name('dealers.index');
    Route::get('/dealers/{dealer}', [AdminDealerAccountController::class, 'show'])->name('dealers.show');
    Route::get('/dealers/{dealer}/wallet', [AdminDealerWalletController::class, 'show'])->name('dealers.wallet.show');
    Route::post('/dealers/{dealer}/wallet/deposits', [AdminDealerWalletController::class, 'store'])->name('dealers.wallet.deposits.store');
    Route::get('/dealers/{dealer}/wallet/transactions', [AdminDealerWalletController::class, 'transactions'])->name('dealers.wallet.transactions');
    Route::get('/dealers/{dealer}/wallet/deposits', [AdminDealerWalletController::class, 'deposits'])->name('dealers.wallet.deposits');
    Route::patch('/dealers/{dealer}', [AdminDealerAccountController::class, 'update'])->name('dealers.update');
    Route::post('/dealers/{dealer}/suspend', [AdminDealerAccountController::class, 'suspend'])->name('dealers.suspend');
    Route::post('/dealers/{dealer}/activate', [AdminDealerAccountController::class, 'activate'])->name('dealers.activate');
    Route::post('/dealers/{dealer}/inactivate', [AdminDealerAccountController::class, 'inactivate'])->name('dealers.inactivate');
    Route::get('/dealers/{dealer}/tier', [AdminDealerAccountTierController::class, 'show'])->name('dealers.tier.show');
    Route::get('/dealers/{dealer}/auto-tier', [AdminDealerAccountTierController::class, 'autoTier'])->name('dealers.auto-tier.show');
    Route::post('/dealers/{dealer}/tier/change', [AdminDealerAccountTierController::class, 'change'])->name('dealers.tier.change');
    Route::get('/dealers/{dealer}/tier-history', [AdminDealerAccountTierController::class, 'history'])->name('dealers.tier.history');
    Route::get('/dealers/{dealer}/tier-overrides', [AdminDealerAccountTierController::class, 'overrides'])->name('dealers.tier.overrides');
    Route::post('/dealers/{dealer}/tier-overrides', [AdminDealerAccountTierController::class, 'createOverride'])->name('dealers.tier.overrides.store');
    Route::post('/dealers/{dealer}/tier-overrides/{override}/cancel', [AdminDealerAccountTierController::class, 'cancelOverride'])->name('dealers.tier.overrides.cancel');
    Route::get('/dealer-tiers', [DealerTierController::class, 'index'])->name('dealer-tiers.index');
    Route::get('/dealer-tiers/auto-policy', [DealerTierController::class, 'autoPolicy'])->name('dealer-tiers.auto-policy');
    Route::put('/dealer-tiers/auto-policy', [DealerTierController::class, 'setAutoPolicy'])->name('dealer-tiers.auto-policy.update');
    Route::patch('/dealer-tiers/{tier}', [DealerTierController::class, 'update'])->name('dealer-tiers.update');
    Route::post('/product-wizard/drafts', [ProductWizardController::class, 'store'])->name('product-wizard.drafts.store');
    Route::get('/product-wizard/drafts/{product}', [ProductWizardController::class, 'show'])->name('product-wizard.drafts.show');
    Route::post('/product-wizard/drafts/{product}/complete', [ProductWizardController::class, 'complete'])->name('product-wizard.drafts.complete');
    Route::get('/product-wizard/sku-availability', [ProductWizardController::class, 'skuAvailability'])->name('product-wizard.sku-availability');
    Route::apiResource('warehouses', WarehouseController::class)->except(['destroy']);
    Route::apiResource('suppliers', SupplierController::class)->except(['destroy']);
    Route::get('/purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase-orders.index');
    Route::post('/purchase-orders', [PurchaseOrderController::class, 'store'])->name('purchase-orders.store');
    Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->name('purchase-orders.show');
    Route::patch('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])->name('purchase-orders.update');
    Route::post('/purchase-orders/{purchaseOrder}/issue', [PurchaseOrderController::class, 'issue'])->name('purchase-orders.issue');
    Route::post('/purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchase-orders.cancel');
    Route::post('/purchase-orders/{purchaseOrder}/payments', [PurchaseOrderController::class, 'recordPayment'])->name('purchase-orders.payments.store');
    Route::get('/goods-receipts', [GoodsReceiptController::class, 'index'])->name('goods-receipts.index');
    Route::get('/goods-receipts/{goodsReceipt}', [GoodsReceiptController::class, 'show'])->name('goods-receipts.show');
    Route::post('/purchase-orders/{purchaseOrder}/goods-receipts', [GoodsReceiptController::class, 'store'])->name('goods-receipts.store');
    Route::get('/purchase-returns', [PurchaseReturnController::class, 'index'])->name('purchase-returns.index');
    Route::get('/purchase-returns/{purchaseReturn}', [PurchaseReturnController::class, 'show'])->name('purchase-returns.show');
    Route::post('/purchase-orders/{purchaseOrder}/returns', [PurchaseReturnController::class, 'store'])->name('purchase-returns.store');
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
    Route::apiResource('products', AdminProductController::class);
    Route::get('/products/{product}/pricing', [ProductPricingController::class, 'show'])->name('products.pricing.show');
    Route::patch('/products/{product}/pricing', [ProductPricingController::class, 'update'])->name('products.pricing.update');
    Route::get('/retail-prices', [CatalogPricingController::class, 'retailIndex'])->name('retail-prices.index');
    Route::patch('/retail-prices/{variant}', [CatalogPricingController::class, 'retailUpdate'])->name('retail-prices.update');
    Route::get('/dealer-prices', [CatalogPricingController::class, 'dealerIndex'])->name('dealer-prices.index');
    Route::put('/dealer-prices/{variant}/{tier}', [CatalogPricingController::class, 'dealerUpdate'])->name('dealer-prices.update');
    Route::delete('/dealer-prices/{variant}/{tier}', [CatalogPricingController::class, 'dealerDelete'])->name('dealer-prices.delete');
    Route::post('/products/{product}/variants', [ProductVariantController::class, 'store'])->name('products.variants.store');
    Route::patch('/products/{product}/variants/{variant}', [ProductVariantController::class, 'update'])->name('products.variants.update');
    Route::post('/products/{product}/images', [ProductImageController::class, 'store'])->name('products.images.store');
    Route::patch('/products/{product}/images/{image}', [ProductImageController::class, 'update'])->name('products.images.update');
    Route::delete('/products/{product}/images/{image}', [ProductImageController::class, 'destroy'])->name('products.images.destroy');
    Route::get('/sales-promotions/generate-code', [SalesPromotionController::class, 'generateCode'])->name('sales-promotions.generate-code');
    Route::get('/sales-promotions', [SalesPromotionController::class, 'index'])->name('sales-promotions.index');
    Route::post('/sales-promotions', [SalesPromotionController::class, 'store'])->name('sales-promotions.store');
    Route::get('/sales-promotions/{promotion}', [SalesPromotionController::class, 'show'])->name('sales-promotions.show');
    Route::match(['PUT', 'PATCH'], '/sales-promotions/{promotion}', [SalesPromotionController::class, 'update'])->name('sales-promotions.update');
    Route::post('/sales-promotions/{promotion}/activate', [SalesPromotionController::class, 'activate'])->name('sales-promotions.activate');
    Route::post('/sales-promotions/{promotion}/deactivate', [SalesPromotionController::class, 'deactivate'])->name('sales-promotions.deactivate');
    Route::get('/sales-vouchers/generate-code', [SalesVoucherController::class, 'generateCode'])->name('sales-vouchers.generate-code');
    Route::get('/sales-vouchers', [SalesVoucherController::class, 'index'])->name('sales-vouchers.index');
    Route::post('/sales-vouchers', [SalesVoucherController::class, 'store'])->name('sales-vouchers.store');
    Route::get('/sales-vouchers/{voucher}', [SalesVoucherController::class, 'show'])->name('sales-vouchers.show');
    Route::put('/sales-vouchers/{voucher}', [SalesVoucherController::class, 'update'])->name('sales-vouchers.update');
    Route::get('/sales-orders/buyers', [SalesOrderController::class, 'buyers'])->name('sales-orders.buyers');
    Route::post('/sales-orders/buyers', [SalesOrderController::class, 'createBuyer'])->name('sales-orders.buyers.store');
    Route::get('/sales-orders/buyers/{buyer}', [SalesOrderController::class, 'buyer'])->name('sales-orders.buyers.show');
    Route::post('/sales-orders/retail-preview', [SalesOrderController::class, 'previewRetail'])->name('sales-orders.retail-preview');
    Route::get('/sales-orders/reservation-reconciliation', [SalesOrderController::class, 'reconciliation'])->name('sales-orders.reservation-reconciliation');
    Route::get('/sales-orders', [SalesOrderController::class, 'index'])->name('sales-orders.index');
    Route::post('/sales-orders', [SalesOrderController::class, 'store'])->name('sales-orders.store');
    Route::get('/sales-orders/{order}', [SalesOrderController::class, 'show'])->name('sales-orders.show');
    Route::post('/sales-orders/{order}/draft', [SalesOrderController::class, 'updateDraft'])->name('sales-orders.draft.update');
    Route::get('/sales-orders/{order}/payments', [PaymentController::class, 'index'])->name('sales-orders.payments.index');
    Route::post('/sales-orders/{order}/payments', [PaymentController::class, 'store'])->name('sales-orders.payments.store');
    Route::get('/payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
    Route::get('/sales-orders/{order}/refunds', [RefundController::class, 'index'])->name('sales-orders.refunds.index');
    Route::post('/sales-orders/{order}/refunds', [RefundController::class, 'store'])->name('sales-orders.refunds.store');
    Route::get('/refunds/{refund}', [RefundController::class, 'show'])->name('refunds.show');
    Route::get('/sales-orders/{order}/returns', [SalesReturnController::class, 'index'])->name('sales-orders.returns.index');
    Route::post('/sales-orders/{order}/returns', [SalesReturnController::class, 'store'])->name('sales-orders.returns.store');
    Route::get('/sales-orders/{order}/returnable-items', [SalesReturnController::class, 'returnable'])->name('sales-orders.returnable-items');
    Route::get('/returns', [SalesReturnController::class, 'pending'])->name('returns.pending');
    Route::get('/returns/{salesReturn}', [SalesReturnController::class, 'show'])->name('returns.show');
    Route::post('/returns/{salesReturn}/process', [SalesReturnController::class, 'process'])->name('returns.process');
    Route::post('/returns/{salesReturn}/approve', [SalesReturnController::class, 'approve'])->name('returns.approve');
    Route::post('/returns/{salesReturn}/reject', [SalesReturnController::class, 'reject'])->name('returns.reject');
    Route::post('/returns/{salesReturn}/receive', [SalesReturnController::class, 'receive'])->name('returns.receive');
    Route::post('/sales-orders/{order}/reprice', [SalesOrderController::class, 'reprice'])->name('sales-orders.reprice');
    Route::post('/sales-orders/{order}/confirm', [SalesOrderController::class, 'confirm'])->name('sales-orders.confirm');
    Route::post('/sales-orders/{order}/cancel', [SalesOrderController::class, 'cancel'])->name('sales-orders.cancel');
    Route::post('/sales-orders/{order}/fulfill', [SalesOrderController::class, 'fulfill'])->name('sales-orders.fulfill');
    Route::post('/sales-orders/{order}/advance', [SalesOrderController::class, 'advance'])->name('sales-orders.advance');
    Route::post('/sales-orders/{order}/warehouse', [SalesOrderController::class, 'warehouse'])->name('sales-orders.warehouse');
    Route::apiResource('retail-price-lists', RetailPriceListController::class)->except(['destroy'])->parameters(['retail-price-lists' => 'priceList']);
    Route::post('/retail-price-lists/{priceList}/items', [RetailPriceItemController::class, 'store'])->name('retail-price-lists.items.store');
    Route::patch('/retail-price-lists/{priceList}/items/{item}', [RetailPriceItemController::class, 'update'])->name('retail-price-lists.items.update');
    Route::get('/dashboard', AdminDashboardController::class)->name('dashboard');
    Route::prefix('reports')->name('reports.')->group(function (): void {
        Route::get('/overview', [ErpReportController::class, 'overview'])->name('overview');
        Route::get('/sales', [ErpReportController::class, 'sales'])->name('sales');
        Route::get('/orders', [ErpReportController::class, 'orders'])->name('orders');
        Route::get('/products', [ErpReportController::class, 'products'])->name('products');
        Route::get('/inventory', [ErpReportController::class, 'inventory'])->name('inventory');
        Route::get('/procurement', [ErpReportController::class, 'procurement'])->name('procurement');
        Route::get('/dealers', [ErpReportController::class, 'dealers'])->name('dealers');
        Route::get('/wallets', [ErpReportController::class, 'wallets'])->name('wallets');
        Route::get('/promotions', [ErpReportController::class, 'promotions'])->name('promotions');
        Route::get('/clinic-summary', [ErpReportController::class, 'clinic'])->name('clinic-summary');
    });
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
    Route::get('customers/{customer}/purchases', [CustomerPurchaseController::class, 'show']);
    Route::get('customers/{customer}/purchased-products', [CustomerPurchaseController::class, 'products']);
    Route::get('customers/{customer}/vouchers', [CustomerPurchaseController::class, 'vouchers']);
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
