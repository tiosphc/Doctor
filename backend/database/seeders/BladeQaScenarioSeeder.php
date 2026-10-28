<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Cart;
use App\Models\DealerWalletDepositRequest;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesPromotion;
use App\Models\SalesVoucher;
use App\Models\User;
use App\Services\DealerContextService;
use App\Services\DealerWalletDepositRequestService;
use App\Services\RegisteredCustomerService;
use App\Services\RetailCartService;
use App\Services\SalesPromotionWriteService;
use App\Services\SalesVoucherWriteService;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BladeQaScenarioSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $identity = DB::selectOne('SELECT DATABASE() AS database_name, CURRENT_USER() AS mysql_user');
        if (! app()->environment('blade_qa')
            || app()->configurationIsCached()
            || config('database.default') !== 'mysql'
            || config('database.connections.mysql.host') !== '127.0.0.1'
            || config('database.connections.mysql.database') !== 'aesthetic_clinic_blade_qa'
            || config('database.connections.mysql.username') !== 'doctor_blade_qa'
            || $identity?->database_name !== 'aesthetic_clinic_blade_qa'
            || $identity?->mysql_user !== 'doctor_blade_qa@127.0.0.1'
            || ! env('DEMO_ADMIN_PASSWORD')) {
            throw new RuntimeException('Blade QA scenarios require the isolated QA connection and a demo password.');
        }

        $this->call(DemoDataSeeder::class);

        $customers = app(RegisteredCustomerService::class);
        User::query()->where('role', 'customer')->where('email', 'like', '%@demo.local')->orderBy('id')->each(
            static function (User $user) use ($customers): void {
                $customers->ensureForUser($user);
            }
        );

        $brand = Brand::query()->firstOrCreate(['code' => 'QA-BLADE-BRAND'], [
            'name' => 'QA Demo Brand',
            'status' => 'active',
        ]);
        Product::query()->where('product_code', 'like', 'DEMO-PRD-%')->whereNull('brand_id')
            ->update(['brand_id' => $brand->id]);

        $admin = User::query()->where('email', 'admin@demo.local')->firstOrFail();
        $retailBuyer = User::query()->where('email', 'retail1@demo.local')->firstOrFail();
        $product = Product::query()->where('product_code', 'DEMO-PRD-01')->firstOrFail();
        $variant = ProductVariant::query()->where('sku', 'DEMO-SKU-01-1')->firstOrFail();

        if (! SalesPromotion::query()->where('normalized_code', 'QA-BLADE-10')->exists()) {
            app(SalesPromotionWriteService::class)->create([
                'code' => 'QA-BLADE-10',
                'name' => 'QA Product Discount',
                'discount_type' => 'percentage',
                'discount_value' => '10.00',
                'sales_scope' => 'retail',
                'status' => 'active',
                'product_ids' => [$product->id],
            ], $admin);
        }
        if (! SalesVoucher::query()->where('normalized_code', 'QA-BLADE-V5')->exists()) {
            app(SalesVoucherWriteService::class)->create([
                'code' => 'QA-BLADE-V5',
                'name' => 'QA Retail Voucher',
                'discount_type' => 'percentage',
                'discount_value' => '5.00',
                'status' => 'active',
            ], $admin);
        }

        $cart = Cart::query()->where('user_id', $retailBuyer->id)->where('context', 'retail')
            ->where('status', 'active')->first();
        if (! $cart?->items()->where('product_variant_id', $variant->id)->exists()) {
            app(RetailCartService::class)->add($retailBuyer->id, $variant->id, '1');
        }

        if (! DealerWalletDepositRequest::query()->where('transaction_reference', 'QA-PROOF-001')->exists()) {
            $dealer = User::query()->where('email', 'dealer1@demo.local')->firstOrFail();
            $account = app(DealerContextService::class)->primaryAccount($dealer);
            if ($account === null) {
                throw new RuntimeException('The QA dealer has no active account.');
            }
            $proof = UploadedFile::fake()->createWithContent('qa-proof.png', base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='
            ));
            app(DealerWalletDepositRequestService::class)->create($account, $dealer, [
                'amount' => '80000.00',
                'payment_proof' => $proof,
                'transaction_reference' => 'QA-PROOF-001',
                'note' => 'Synthetic QA proof',
            ]);
        }
    }
}
