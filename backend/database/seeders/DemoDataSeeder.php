<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\DealerAccount;
use App\Models\DealerApplication;
use App\Models\DealerTier;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\SalesPromotion;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseServiceArea;
use App\Services\BookingService;
use App\Services\DealerApplicationService;
use App\Services\DealerQuickOrderService;
use App\Services\DealerWalletService;
use App\Services\InventoryService;
use App\Services\PaymentService;
use App\Services\ProcurementService;
use App\Services\RefundService;
use App\Services\SalesOrderService;
use App\Services\SalesPromotionService;
use App\Services\SalesReturnService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Ramsey\Uuid\Uuid;
use RuntimeException;

class DemoDataSeeder extends Seeder
{
    private const NOTE = 'Demo data';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demo seeding is restricted to local and testing.');
        }
        Notification::fake();
        $users = $this->users();
        $this->clinic($users);
        $warehouses = $this->warehouses();
        $this->call(DealerTierPresetSeeder::class);
        $this->call(ProductUnitSeeder::class);
        $variants = $this->catalog();
        $dealers = $this->dealers($users);
        $this->promotions($users['admin']);
        $suppliers = $this->suppliers();
        $this->procurement($users['admin'], $suppliers, $warehouses, $variants);
        $this->stock($users['admin'], $warehouses, $variants);
        $this->call(DemoGiftPromotionSeeder::class);
        $this->call(DemoProductImageSeeder::class);
        $this->deposits($users['admin'], $dealers);
        $this->retail($users, $warehouses[0], $variants);
        $this->dealerOrders($users['admin'], $dealers, $variants);
        $this->command?->info('Demo data ready. Admin: admin@demo.local; Retail: retail1@demo.local; Dealer: dealer1@demo.local.');
        foreach (['users', 'dealer_accounts', 'suppliers', 'warehouses', 'products', 'product_variants', 'sales_orders', 'purchase_orders', 'appointments'] as $table) {
            $this->command?->line($table.': '.DB::table($table)->count());
        }
    }

    /** @return array<string, User> */
    private function users(): array
    {
        $password = env('DEMO_ADMIN_PASSWORD') ?: 'DemoOnly!2026';
        $people = [['admin', 'Admin Demo', 'admin'], ['staff1', 'Staff Demo One', 'receptionist'], ['staff2', 'Staff Demo Two', 'receptionist']];
        foreach (range(1, 3) as $i) {
            $people[] = ['doctor'.$i, 'Doctor Demo '.$i, 'doctor'];
        }
        foreach (range(1, 10) as $i) {
            $people[] = ['retail'.$i, 'Retail Demo '.$i, 'customer'];
        }
        foreach (range(1, 5) as $i) {
            $people[] = ['dealer'.$i, 'Dealer Demo '.$i, 'customer'];
        }
        $users = [];
        foreach ($people as $index => [$key, $name, $role]) {
            $email = $key.'@demo.local';
            $existing = User::query()->where('email', $email)->first();
            if ($existing !== null && ($existing->name !== $name || $existing->role !== $role)) {
                throw new RuntimeException("Non-demo user collision: {$email}");
            }
            $users[$key] = $existing ?? User::query()->create(['name' => $name, 'email' => $email,
                'phone' => sprintf('090%07d', $index + 1), 'role' => $role,
                'password' => $password, 'email_verified_at' => now()]);
        }

        return $users;
    }

    /** @param array<string, User> $users */
    private function clinic(array $users): void
    {
        $category = ServiceCategory::query()->firstOrCreate(['slug' => 'demo-clinic'], ['name' => 'Demo Clinic', 'short_description' => self::NOTE]);
        $services = [];
        foreach (range(1, 3) as $i) {
            $services[] = Service::query()->firstOrCreate(['slug' => 'demo-service-'.$i], [
                'category_id' => $category->id, 'name' => 'Demo Skin Consultation '.$i,
                'description' => self::NOTE, 'duration' => 30, 'price' => 250000 + $i * 100000, 'status' => 'active']);
        }
        $doctors = [];
        foreach (range(1, 3) as $i) {
            $user = $users['doctor'.$i];
            $doctors[] = $doctor = Doctor::query()->firstOrCreate(['user_id' => $user->id], [
                'name' => $user->name, 'email' => $user->email, 'specialty' => 'Skin care', 'bio' => self::NOTE, 'status' => 'active']);
            $doctor->services()->syncWithoutDetaching(array_column($services, 'id'));
            foreach (range(1, 7) as $day) {
                DoctorSchedule::query()->firstOrCreate(['doctor_id' => $doctor->id,
                    'day_of_week' => $day, 'start_time' => '08:00:00', 'end_time' => '17:00:00']);
            }
        }
        $booking = app(BookingService::class);
        foreach (range(1, 25) as $i) {
            $code = sprintf('DEMO-APT-%03d', $i);
            if (Appointment::query()->where('booking_code', $code)->exists()) {
                continue;
            }
            $doctorIndex = ($i - 1) % 3;
            $date = CarbonImmutable::today()->subDays($i === 1 ? 0 : ($i * 3) % 86);
            $hour = 8 + ($i % 9);
            $appointment = Appointment::query()->create(['booking_code' => $code,
                'user_id' => $users['retail'.(($i - 1) % 10 + 1)]->id,
                'doctor_id' => $doctors[$doctorIndex]->id, 'service_id' => $services[$doctorIndex]->id,
                'appointment_date' => $date->toDateString(), 'start_time' => sprintf('%02d:00:00', $hour),
                'end_time' => sprintf('%02d:30:00', $hour), 'status' => 'pending',
                'original_price' => $services[$doctorIndex]->price, 'discount_amount' => '0.00',
                'final_price' => $services[$doctorIndex]->price, 'note' => self::NOTE]);
            $steps = [[], ['confirmed'], ['confirmed', 'checked_in'],
                ['confirmed', 'checked_in', 'in_progress'],
                ['confirmed', 'checked_in', 'in_progress', 'treatment_done'],
                ['confirmed', 'checked_in', 'in_progress', 'treatment_done', 'completed'], ['cancelled']][$i % 7];
            foreach ($steps as $status) {
                $actor = in_array($status, ['in_progress', 'treatment_done'], true) ? $users['doctor'.($doctorIndex + 1)] : $users['admin'];
                $appointment = $booking->transitionAppointment($appointment, $status, $actor);
            }
        }
    }

    /** @return list<Warehouse> */
    private function warehouses(): array
    {
        $hasDefault = Warehouse::query()->where('is_default_sales', true)->exists();

        $warehouses = [Warehouse::query()->firstOrCreate(['code' => 'DEMO-WH-HCM'], [
            'name' => 'Kho TP.HCM', 'city' => 'Ho Chi Minh', 'province' => 'Ho Chi Minh', 'country' => 'VN',
            'status' => 'active', 'is_default_sales' => ! $hasDefault]),
            Warehouse::query()->firstOrCreate(['code' => 'DEMO-WH-HN'], [
                'name' => 'Kho Ha Noi', 'city' => 'Ha Noi', 'province' => 'Ha Noi', 'country' => 'VN',
                'status' => 'active'])];
        foreach (['79' => $warehouses[0], '1' => $warehouses[1]] as $provinceCode => $warehouse) {
            WarehouseServiceArea::query()->firstOrCreate([
                'warehouse_id' => $warehouse->id, 'province_code' => $provinceCode,
            ], ['priority' => 1]);
        }

        return $warehouses;
    }

    /** @return list<ProductVariant> */
    private function catalog(): array
    {
        $unitIds = Unit::query()->pluck('id', 'code');
        $retail = PriceList::query()->firstOrCreate(['code' => 'DEMO-RETAIL'], ['name' => 'Demo Retail', 'pricing_context' => 'retail', 'scope_type' => 'all', 'currency' => 'VND', 'status' => 'active']);
        $tier = DealerTier::query()->where('status', 'active')->where('is_default_initial', true)->firstOrFail();
        $dealer = PriceList::query()->where('code', 'DEMO-DEALER-'.$tier->code)->first()
            ?? PriceList::query()->where('code', 'DEMO-DEALER-DEMO-INITIAL')->first()
            ?? PriceList::query()->create(['code' => 'DEMO-DEALER-'.$tier->code, 'name' => 'Demo Dealer', 'pricing_context' => 'dealer', 'scope_type' => 'tier', 'dealer_tier_id' => $tier->id, 'currency' => 'VND', 'status' => 'active']);
        $names = ['Hydrating serum', 'Barrier cream', 'Gentle cleanser', 'Skin booster kit', 'Recovery mask', 'Vitamin serum', 'Cooling gel', 'Sun care lotion', 'Peptide cream', 'Moisture ampoule', 'Treatment pads', 'Hyaluronic mist', 'Aftercare set'];
        $variants = [];
        foreach ($names as $index => $name) {
            $n = $index + 1;
            $category = ProductCategory::query()->firstOrCreate(['code' => 'DEMO-CAT-'.($index % 4 + 1)], ['name' => ['Skin care', 'Serum', 'Cosmetic supply', 'Skin booster'][$index % 4], 'status' => 'active']);
            $product = Product::query()->firstOrCreate(['product_code' => sprintf('DEMO-PRD-%02d', $n)], [
                'name' => $name, 'slug' => sprintf('demo-product-%02d', $n), 'description' => self::NOTE,
                'product_category_id' => $category->id, 'status' => 'active', 'track_inventory' => true]);
            foreach (range(1, $index < 4 ? 3 : 2) as $j) {
                $variant = ProductVariant::query()->firstOrCreate(['sku' => sprintf('DEMO-SKU-%02d-%d', $n, $j)], [
                    'product_id' => $product->id, 'variant_name' => ['Small', 'Medium', 'Large'][$j - 1],
                    'unit_id' => $unitIds[ProductUnitSeeder::unitCodeForDemoProduct($product->product_code)],
                    'status' => 'active', 'sellable_retail' => true, 'sellable_dealer' => true, 'track_inventory' => true]);
                $variants[] = $variant;
                PriceListItem::query()->firstOrCreate(['price_list_id' => $retail->id, 'product_variant_id' => $variant->id], [
                    'unit_price' => (string) (250000 + $index * 60000 + $j * 30000), 'minimum_quantity' => '1', 'status' => 'active']);
                PriceListItem::query()->firstOrCreate(['price_list_id' => $dealer->id, 'product_variant_id' => $variant->id], [
                    'unit_price' => (string) (190000 + $index * 45000 + $j * 25000), 'minimum_quantity' => '1', 'status' => 'active']);
            }
        }

        return $variants;
    }

    /** @param array<string, User> $users @return list<\App\Models\DealerAccount> */
    private function dealers(array $users): array
    {
        $service = app(DealerApplicationService::class);
        $accounts = [];
        foreach (['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon'] as $index => $label) {
            $owner = $users['dealer'.($index + 1)];
            $application = DealerApplication::query()->where('user_id', $owner->id)->where('email', $owner->email)->first();
            $application ??= $service->submit($owner, ['company_name' => 'DEMO Dealer '.$label,
                'trading_name' => 'DEMO '.$label, 'contact_name' => $owner->name, 'email' => $owner->email,
                'phone' => $owner->phone, 'business_address_line1' => 'Demo Street '.($index + 1),
                'city' => 'Ho Chi Minh', 'province' => 'Ho Chi Minh', 'country' => 'VN', 'note' => self::NOTE]);
            if ($application->company_name !== 'DEMO Dealer '.$label) {
                throw new RuntimeException('Dealer application collision: '.$owner->email);
            }
            if ($application->status === 'pending') {
                $application = $service->approve($application, $users['admin']);
            }
            $account = $application->approvedAccount()->firstOrFail();
            if ($account->status !== 'active' || $account->current_tier_id === null) {
                throw new RuntimeException('Demo Dealer is inactive or missing Tier.');
            }
            $accounts[] = $account;
        }

        return $accounts;
    }

    private function promotions(User $admin): void
    {
        foreach ([['DEMO10', 'percentage', '10.00', 'both'], ['DEMO50K', 'fixed_amount', '50000.00', 'retail'], ['DEMODEALER', 'percentage', '7.00', 'dealer']] as [$code, $type, $value, $scope]) {
            SalesPromotion::query()->firstOrCreate(['normalized_code' => $code], ['code' => $code,
                'name' => 'Demo promotion '.$code, 'discount_type' => $type, 'discount_value' => $value,
                'minimum_order_amount' => '150000.00', 'sales_scope' => $scope, 'status' => 'active', 'created_by_user_id' => $admin->id]);
        }
    }

    /** @return list<Supplier> */
    private function suppliers(): array
    {
        $result = [];
        foreach (['Medical Supply', 'Aesthetic Distribution', 'Cosmetic Supplier', 'Healthcare Supplier', 'Beauty Supply'] as $index => $name) {
            $n = $index + 1;
            $result[] = Supplier::query()->firstOrCreate(['code' => sprintf('DEMO-SUP-%03d', $n)], [
                'name' => 'DEMO '.$name, 'contact_name' => 'Demo Contact '.$n,
                'email' => 'supplier'.$n.'@demo.local', 'phone' => sprintf('028000%05d', $n),
                'address' => self::NOTE, 'status' => 'active']);
        }

        return $result;
    }

    /** @param list<Supplier> $suppliers @param list<Warehouse> $warehouses @param list<ProductVariant> $variants */
    private function procurement(User $admin, array $suppliers, array $warehouses, array $variants): void
    {
        $service = app(ProcurementService::class);
        foreach (range(1, 9) as $i) {
            $code = sprintf('DEMO-PO-%03d', $i);
            $order = PurchaseOrder::query()->where('code', $code)->first();
            if ($order === null) {
                $order = $service->createOrder(['supplier_id' => $suppliers[($i - 1) % 5]->id,
                    'warehouse_id' => $warehouses[($i - 1) % 2]->id, 'note' => self::NOTE.' '.$code,
                    'items' => [['product_variant_id' => $variants[($i - 1) % 20]->id,
                        'quantity' => (string) (70 + $i * 5), 'unit_price' => (string) (130000 + $i * 10000)]],
                ], $admin->id);
                $order->update(['code' => $code]);
            }
            if ($i === 9 || $order->status === 'cancelled') {
                continue;
            }
            if ($order->status === 'draft') {
                if ($i === 8) {
                    $service->cancelOrder($order);

                    continue;
                }
                $order = $service->issueOrder($order, $admin->id);
            }
            $item = $order->items()->firstOrFail();
            $quantity = (int) $item->ordered_quantity;
            $firstQuantity = $i <= 2 ? (int) floor($quantity * .6) : $quantity;
            $first = $service->receive($order, ['operation_key' => $this->key($code.'-receipt-1'),
                'supplier_reference' => $code.'-delivery-1', 'note' => self::NOTE,
                'items' => [['purchase_order_item_id' => $item->id, 'quantity' => (string) $firstQuantity]]], $admin->id);
            if ($i === 1) {
                $service->receive($order, ['operation_key' => $this->key($code.'-receipt-2'),
                    'supplier_reference' => $code.'-delivery-2', 'note' => self::NOTE,
                    'items' => [['purchase_order_item_id' => $item->id, 'quantity' => (string) ($quantity - $firstQuantity)]]], $admin->id);
            }
            if ($i >= 3 && $i <= 5) {
                $receiptItem = $first->items()->firstOrFail();
                $service->returnGoods($order, ['operation_key' => $this->key($code.'-return'),
                    'reason' => self::NOTE.' supplier return',
                    'items' => [['goods_receipt_item_id' => $receiptItem->id, 'quantity' => '5']]], $admin->id);
            }
        }
    }

    /** @param list<Warehouse> $warehouses @param list<ProductVariant> $variants */
    private function stock(User $admin, array $warehouses, array $variants): void
    {
        $inventory = app(InventoryService::class);
        $stockWarehouses = collect($warehouses)->push(Warehouse::query()->where('is_default_sales', true)->firstOrFail())->unique('id');
        foreach ($stockWarehouses as $warehouse) {
            foreach ($variants as $index => $variant) {
                if ($index >= count($variants) - 2 || DB::table('stock_movements')->where('warehouse_id', $warehouse->id)->where('product_variant_id', $variant->id)->exists()) {
                    continue;
                }
                $inventory->opening(['warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id,
                    'quantity' => (string) (45 + ($index * 13) % 65),
                    'operation_key' => $this->key('opening-'.$warehouse->code.'-'.$variant->sku),
                    'reason_detail' => self::NOTE.' opening stock'], $admin->id);
            }
        }
    }

    /** @param list<DealerAccount> $dealers */
    private function deposits(User $admin, array $dealers): void
    {
        $wallets = app(DealerWalletService::class);
        foreach ([25000000, 15000000, 7000000, 0, 12000000] as $index => $amount) {
            $wallets->ensure($dealers[$index]);
            if ($amount === 0) {
                continue;
            }
            $wallets->recordDeposit($dealers[$index], ['operation_key' => $this->key('deposit-'.($index + 1)),
                'amount' => (string) $amount, 'method' => 'other_manual',
                'external_reference' => 'DEMO-DEP-'.($index + 1), 'note' => self::NOTE], $admin);
        }
    }

    /** @param array<string, User> $users @param list<ProductVariant> $variants */
    private function retail(array $users, Warehouse $warehouse, array $variants): void
    {
        $orders = app(SalesOrderService::class);
        $payments = app(PaymentService::class);
        $promotions = app(SalesPromotionService::class);
        $returns = app(SalesReturnService::class);
        $refunds = app(RefundService::class);
        $admin = $users['admin'];
        foreach (range(1, 25) as $i) {
            $key = $this->key('retail-order-'.$i);
            if (SalesOrder::query()->where('creation_operation_key', $key)->exists()) {
                continue;
            }
            $customer = $users['retail'.(($i - 1) % 10 + 1)];
            $this->at($this->activityDate($i), function () use ($orders, $payments, $promotions, $returns, $refunds, $admin, $customer, $warehouse, $variants, $i, $key): void {
                $order = $orders->createDraft(['operation_key' => $key, 'sales_channel' => 'retail',
                    'buyer_user_id' => $customer->id, 'warehouse_id' => $warehouse->id, 'currency' => 'VND',
                    'items' => [['sku' => $variants[($i * 3) % 20]->sku, 'quantity' => (string) (1 + $i % 3)]],
                    'recipient_name' => $customer->name, 'recipient_phone' => $customer->phone,
                    'recipient_email' => $customer->email, 'shipping_address_line1' => 'Demo Street '.$i,
                    'shipping_city' => 'Ho Chi Minh', 'shipping_province' => 'Ho Chi Minh', 'shipping_country' => 'VN',
                    'delivery_note' => self::NOTE], $admin->id);
                if ($i % 4 === 0) {
                    $promotions->redeem($order, $i % 8 === 0 ? 'DEMO50K' : 'DEMO10');
                }
                $order = $orders->confirm($order, $this->key('retail-confirm-'.$i), $admin->id);
                if ($i === 7) {
                    $orders->cancel($order, $this->key('retail-cancel-'.$i), self::NOTE, $admin->id);

                    return;
                }
                if ($i % 5 !== 0) {
                    $payments->recordSettledPayment($order, ['operation_key' => $this->key('retail-payment-'.$i),
                        'amount' => $i === 3 ? bcdiv($order->grand_total, '2', 2) : $order->grand_total,
                        'payment_method' => $i % 2 === 0 ? 'cash' : 'bank_transfer',
                        'external_reference' => 'DEMO-PAY-'.$i, 'note' => self::NOTE], $admin->id);
                }
                if ($i % 3 !== 0) {
                    $quantities = $order->items->mapWithKeys(fn ($item): array => [$item->id => (string) (int) $item->quantity])->all();
                    $order = $orders->fulfill($order, $this->key('retail-fulfill-'.$i), $quantities, $admin->id);
                }
                if (in_array($i, [1, 2], true)) {
                    $item = $order->items()->firstOrFail();
                    $return = $returns->complete($order, ['operation_key' => $this->key('retail-return-'.$i),
                        'reason' => self::NOTE.' return', 'items' => [['item_id' => $item->id,
                            'quantity' => '1', 'restock_quantity' => '1']]], $admin->id);
                    $refunds->complete($order, ['operation_key' => $this->key('retail-refund-'.$i),
                        'amount' => '50000', 'refund_method' => 'cash', 'reason' => self::NOTE.' refund',
                        'return_id' => $return->id], $admin->id);
                }
                if ($i === 4) {
                    $refunds->complete($order, ['operation_key' => $this->key('retail-refund-'.$i),
                        'amount' => '30000', 'refund_method' => 'bank_transfer',
                        'reason' => self::NOTE.' goodwill'], $admin->id);
                }
            });
        }
    }

    /** @param list<DealerAccount> $dealers @param list<ProductVariant> $variants */
    private function dealerOrders(User $admin, array $dealers, array $variants): void
    {
        $quick = app(DealerQuickOrderService::class);
        $orders = app(SalesOrderService::class);
        $refunds = app(RefundService::class);
        foreach (range(1, 16) as $i) {
            $key = $this->key('dealer-order-'.$i);
            if (SalesOrder::query()->where('creation_operation_key', $key)->exists()) {
                continue;
            }
            $account = $dealers[$i % 4 === 0 ? 4 : (($i - 1) % 3)];
            $owner = $account->memberships()->where('membership_role', 'owner')->firstOrFail()->user;
            $items = [['product_variant_id' => $variants[($i * 2) % 20]->id, 'quantity' => (string) (1 + $i % 2)]];
            $this->at($this->activityDate($i + 10), function () use ($quick, $orders, $refunds, $admin, $account, $owner, $items, $i, $key): void {
                $review = $quick->review($owner, $account, $items);
                if (! $review['can_submit']) {
                    throw new RuntimeException('Demo Quick Order review failed: '.json_encode($review, JSON_THROW_ON_ERROR));
                }
                $order = $quick->submit($owner, $account, ['operation_key' => $key,
                    'review_fingerprint' => $review['review_fingerprint'], 'items' => $items,
                    'recipient_name' => $owner->name,
                    'recipient_phone' => $owner->phone, 'shipping_address_line1' => 'Demo Dealer Street',
                    'shipping_city' => 'Ho Chi Minh', 'shipping_province' => 'Ho Chi Minh',
                    'shipping_country' => 'VN', 'delivery_note' => self::NOTE]);
                if ($i % 3 !== 0) {
                    $quantities = $order->items->mapWithKeys(fn ($item): array => [$item->id => (string) (int) $item->quantity])->all();
                    $order = $orders->fulfill($order, $this->key('dealer-fulfill-'.$i), $quantities, $admin->id);
                }
                if ($i === 1) {
                    $refunds->complete($order, ['operation_key' => $this->key('dealer-refund-'.$i),
                        'amount' => '50000', 'refund_method' => 'dealer_wallet',
                        'reason' => self::NOTE.' dealer refund'], $admin->id);
                }
            });
        }
    }

    private function activityDate(int $index): CarbonImmutable
    {
        $days = [0, 1, 3, 6, 9, 14, 21, 29, 34, 41, 48, 55, 63, 72, 82];

        $offset = $days[($index - 1) % count($days)];

        return $offset === 0
            ? CarbonImmutable::now()->subMinute()
            : CarbonImmutable::now()->subDays($offset)->setTime(9 + $index % 6, 15);
    }

    /** @param \Closure(): void $callback */
    private function at(CarbonImmutable $date, \Closure $callback): void
    {
        $previous = CarbonImmutable::getTestNow();
        CarbonImmutable::setTestNow($date);
        try {
            $callback();
        } finally {
            CarbonImmutable::setTestNow($previous);
        }
    }

    private function key(string $name): string
    {
        return (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, 'junie-demo-'.$name);
    }
}
