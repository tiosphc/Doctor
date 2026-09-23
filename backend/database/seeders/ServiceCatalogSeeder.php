<?php

namespace Database\Seeders;

use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ServiceCatalogSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $categories = collect($this->categories())
                ->mapWithKeys(function (array $category): array {
                    $model = ServiceCategory::query()->updateOrCreate(
                        ['slug' => $category['slug']],
                        $category,
                    );

                    return [$category['slug'] => $model];
                });

            foreach ($this->services() as $serviceData) {
                $legacySlug = $serviceData['legacy_slug'];
                unset($serviceData['legacy_slug']);

                $categorySlug = $serviceData['category_slug'];
                unset($serviceData['category_slug']);

                $service = Service::query()->where('slug', $serviceData['slug'])->first()
                    ?? Service::query()->where('slug', $legacySlug)->first()
                    ?? new Service;

                $service->fill([
                    ...$serviceData,
                    'category_id' => $categories->get($categorySlug)->id,
                ])->save();
            }

            ServiceCategory::query()
                ->whereIn('slug', ['chua-phan-loai', 'tu-van', 'cham-soc-da', 'tham-my'])
                ->doesntHave('services')
                ->delete();
        });
    }

    /** @return list<array{name: string, slug: string, short_description: string}> */
    private function categories(): array
    {
        return [
            [
                'name' => 'Tư vấn da liễu',
                'slug' => 'tu-van-da-lieu',
                'short_description' => 'Thăm khám, phân tích tình trạng da và xây dựng hướng chăm sóc phù hợp cùng bác sĩ.',
            ],
            [
                'name' => 'Chăm sóc & phục hồi da',
                'slug' => 'cham-soc-phuc-hoi-da',
                'short_description' => 'Các liệu trình hỗ trợ làm dịu, nuôi dưỡng và phục hồi hàng rào bảo vệ da.',
            ],
            [
                'name' => 'Trẻ hóa thẩm mỹ',
                'slug' => 'tre-hoa-tham-my',
                'short_description' => 'Giải pháp trẻ hóa và tạo đường nét được tư vấn theo nhu cầu của từng khách hàng.',
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function services(): array
    {
        return [
            $this->service(
                legacySlug: 'aesthetic-consultation',
                categorySlug: 'tu-van-da-lieu',
                name: 'Tư vấn da chuyên sâu',
                slug: 'tu-van-da-chuyen-sau',
                description: 'Bác sĩ thăm khám, phân tích tình trạng da và đề xuất lộ trình chăm sóc cá nhân hóa.',
                duration: 45,
                price: '500000.00',
                sortOrder: 10,
                highlights: ['Thăm khám trực tiếp cùng bác sĩ', 'Đánh giá thói quen chăm sóc da', 'Đề xuất lộ trình phù hợp'],
            ),
            $this->service(
                legacySlug: 'skin-consultation',
                categorySlug: 'tu-van-da-lieu',
                name: 'Điều trị mụn & sẹo rỗ',
                slug: 'dieu-tri-mun-seo-ro',
                description: 'Đánh giá tình trạng mụn, sẹo và xây dựng phác đồ điều trị theo từng giai đoạn.',
                duration: 60,
                price: '1200000.00',
                sortOrder: 20,
                highlights: ['Đánh giá nguyên nhân và mức độ tổn thương', 'Theo dõi đáp ứng qua từng giai đoạn', 'Hướng dẫn chăm sóc tại nhà'],
            ),
            $this->service(
                legacySlug: 'facial-care-consultation',
                categorySlug: 'cham-soc-phuc-hoi-da',
                name: 'Chăm sóc phục hồi làn da',
                slug: 'cham-soc-phuc-hoi-lan-da',
                description: 'Liệu trình hỗ trợ làm dịu và phục hồi làn da nhạy cảm hoặc đang suy yếu.',
                duration: 60,
                price: '900000.00',
                sortOrder: 30,
                highlights: ['Làm sạch dịu nhẹ', 'Hỗ trợ cấp ẩm và làm dịu', 'Tư vấn duy trì hàng rào bảo vệ da'],
            ),
            $this->service(
                legacySlug: 'cosmetic-procedure-consultation',
                categorySlug: 'tre-hoa-tham-my',
                name: 'Trẻ hóa Ultherapy & Thermage',
                slug: 'tre-hoa-ultherapy-thermage',
                description: 'Tư vấn giải pháp nâng cơ và trẻ hóa không phẫu thuật dựa trên tình trạng thực tế.',
                duration: 90,
                price: '8500000.00',
                sortOrder: 40,
                highlights: ['Đánh giá vùng cần cải thiện', 'Cá nhân hóa mức năng lượng và vùng điều trị', 'Hướng dẫn theo dõi sau liệu trình'],
            ),
            $this->service(
                legacySlug: 'follow-up-consultation',
                categorySlug: 'tre-hoa-tham-my',
                name: 'Tiêm Botox & Filler chuẩn y khoa',
                slug: 'tiem-botox-filler-chuan-y-khoa',
                description: 'Thăm khám và tư vấn phương án điều chỉnh đường nét với ưu tiên sự hài hòa, an toàn.',
                duration: 60,
                price: '4500000.00',
                sortOrder: 50,
                highlights: ['Tư vấn trực tiếp cùng bác sĩ', 'Lựa chọn phương án theo đường nét khuôn mặt', 'Hẹn kiểm tra và theo dõi sau thực hiện'],
            ),
        ];
    }

    /** @param list<string> $highlights */
    private function service(
        string $legacySlug,
        string $categorySlug,
        string $name,
        string $slug,
        string $description,
        int $duration,
        string $price,
        int $sortOrder,
        array $highlights,
    ): array {
        return [
            'legacy_slug' => $legacySlug,
            'category_slug' => $categorySlug,
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'short_description' => $description,
            'introduction' => "{$name} là gì? {$description}",
            'duration' => $duration,
            'duration_note' => "Khoảng {$duration} phút",
            'price' => $price,
            'price_note' => 'Chi phí tham khảo, bác sĩ sẽ tư vấn cụ thể sau khi thăm khám.',
            'hero_disclaimer' => 'Kết quả và thời gian đáp ứng có thể khác nhau tùy tình trạng của mỗi khách hàng.',
            'cta_label' => 'Đặt lịch tư vấn',
            'status' => Service::STATUS_ACTIVE,
            'sort_order' => $sortOrder,
            'seo_title' => "{$name} | Junie Clinic",
            'seo_description' => $description,
            'content' => [
                'benefits' => [
                    'title' => 'Tác dụng và ưu điểm',
                    'description' => 'Các điểm nổi bật sẽ được bác sĩ trao đổi dựa trên nhu cầu và tình trạng thực tế.',
                    'items' => array_map(
                        fn (string $highlight): array => [
                            'title' => $highlight,
                            'description' => 'Thông tin tham khảo, cần được cá nhân hóa sau khi thăm khám.',
                        ],
                        $highlights,
                    ),
                ],
                'process' => [
                    'title' => 'Quy trình thực hiện',
                    'description' => 'Quy trình được điều chỉnh theo dịch vụ và kế hoạch chăm sóc của từng khách hàng.',
                    'steps' => [
                        ['title' => 'Thăm khám và tư vấn', 'description' => 'Bác sĩ trao đổi nhu cầu và đánh giá tình trạng hiện tại.'],
                        ['title' => 'Thống nhất kế hoạch', 'description' => 'Tư vấn phương án, thời gian và chi phí tham khảo phù hợp.'],
                        ['title' => 'Theo dõi và chăm sóc', 'description' => 'Hướng dẫn chăm sóc sau dịch vụ và lịch kiểm tra khi cần thiết.'],
                    ],
                ],
                'results' => [
                    'title' => 'Hiệu quả trước và sau',
                    'description' => 'Hình ảnh kết quả được cập nhật theo từng dịch vụ khi có tư liệu phù hợp.',
                    'cases' => [],
                    'disclaimer' => 'Kết quả và trải nghiệm có thể khác nhau tùy từng trường hợp.',
                ],
                'faq' => [
                    'title' => 'Câu hỏi thường gặp',
                    'items' => [
                        ['question' => 'Dịch vụ có cần tư vấn trước không?', 'answer' => 'Bác sĩ sẽ tư vấn trước để xác định nhu cầu và phương án phù hợp.'],
                        ['question' => 'Chi phí thực tế được xác định như thế nào?', 'answer' => 'Chi phí tham khảo sẽ được xác nhận sau khi thăm khám và thống nhất kế hoạch.'],
                    ],
                ],
            ],
        ];
    }
}
