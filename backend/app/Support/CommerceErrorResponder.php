<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class CommerceErrorResponder
{
    private const ADMIN_AREAS = [
        'dealer-wallet-top-ups', 'dealer-applications', 'dealers', 'dealer-tiers',
        'product-wizard', 'warehouses', 'suppliers', 'purchase-orders',
        'goods-receipts', 'purchase-returns', 'inventory', 'stock-movements',
        'product-master', 'products', 'sales-promotions', 'sales-vouchers', 'sales-orders',
        'payments', 'refunds', 'returns', 'retail-price-lists',
    ];

    private const FIELD_NAMES = [
        'name' => 'tên', 'code' => 'mã', 'sku' => 'SKU', 'base_sku' => 'SKU gốc',
        'slug' => 'đường dẫn sản phẩm', 'quantity' => 'số lượng',
        'retail_price' => 'giá bán lẻ', 'variant_retail_prices' => 'giá bán lẻ theo SKU',
        'dealer_rules' => 'quy tắc giá đại lý', 'min_quantity' => 'số lượng đặt tối thiểu',
        'sellable_retail' => 'kênh bán lẻ', 'sellable_dealer' => 'kênh đại lý',
        'channels' => 'kênh bán', 'product_ids' => 'sản phẩm áp dụng',
        'category_ids' => 'danh mục áp dụng', 'description' => 'mô tả',
        'default_low_stock_threshold' => 'ngưỡng cảnh báo tồn kho',
        'ordered_quantity' => 'số lượng đặt', 'received_quantity' => 'số lượng nhận',
        'restock_quantity' => 'số lượng nhập lại kho', 'minimum_quantity' => 'số lượng tối thiểu',
        'minimum_order_quantity' => 'số lượng đặt tối thiểu',
        'product_variant_id' => 'SKU', 'product_id' => 'sản phẩm',
        'product_category_id' => 'danh mục', 'category_id' => 'danh mục',
        'brand_id' => 'thương hiệu', 'unit_id' => 'đơn vị', 'default_unit_id' => 'đơn vị',
        'warehouse_id' => 'kho', 'dealer_account_id' => 'tài khoản đại lý',
        'dealer_tier_id' => 'hạng đại lý', 'dealer_tier_ids' => 'hạng đại lý',
        'tier_id' => 'hạng đại lý', 'supplier_id' => 'nhà cung cấp',
        'unit_price' => 'đơn giá', 'price' => 'giá bán', 'amount' => 'số tiền',
        'discount_value' => 'mức giảm', 'max_discount_amount' => 'mức giảm tối đa',
        'minimum_order_amount' => 'giá trị đơn tối thiểu',
        'discount_type' => 'kiểu giảm giá', 'sales_scope' => 'phạm vi áp dụng',
        'starts_at' => 'thời gian bắt đầu', 'ends_at' => 'thời gian kết thúc',
        'effective_to' => 'thời gian kết thúc hiệu lực', 'items' => 'mặt hàng',
        'total_usage_limit' => 'giới hạn lượt dùng',
        'per_buyer_usage_limit' => 'giới hạn mỗi người mua',
        'buy_product_id' => 'sản phẩm mua', 'buy_variant_id' => 'SKU mua',
        'gift_product_id' => 'sản phẩm quà tặng', 'gift_variant_id' => 'SKU quà tặng',
        'minimum_buy_quantity' => 'số lượng mua tối thiểu',
        'gift_quantity' => 'số lượng quà tặng', 'gift_rule' => 'điều kiện quà tặng',
        'repeat_per_multiple' => 'tặng lặp theo số lượng mua',
        'recipient_name' => 'tên người nhận', 'recipient_phone' => 'số điện thoại người nhận',
        'recipient_email' => 'email người nhận', 'shipping_address_line1' => 'địa chỉ giao hàng',
        'shipping_city' => 'thành phố', 'shipping_district' => 'quận/huyện',
        'shipping_province' => 'tỉnh/thành', 'shipping_country' => 'quốc gia',
        'shipping_postal_code' => 'mã bưu chính', 'shipping_address_line2' => 'địa chỉ bổ sung',
        'delivery_note' => 'ghi chú giao hàng', 'payment_method' => 'phương thức thanh toán',
        'operation_key' => 'mã xác nhận thao tác', 'checkout_operation_key' => 'mã xác nhận đặt hàng',
        'review_fingerprint' => 'bản xem trước đơn hàng',
        'checkout_review_fingerprint' => 'bản xem trước thanh toán',
        'file' => 'file Excel', 'reason' => 'lý do', 'status' => 'trạng thái',
        'email' => 'email', 'phone' => 'số điện thoại', 'street' => 'địa chỉ',
        'postal_code' => 'mã bưu chính', 'reason_code' => 'mã lý do',
        'reason_detail' => 'giải thích lý do',
    ];

    public function appliesTo(Request $request): bool
    {
        $segments = explode('/', trim($request->path(), '/'));
        if (($segments[0] ?? null) !== 'api') {
            return false;
        }

        $area = $segments[1] ?? '';
        if (in_array($area, ['dealer', 'dealer-applications', 'retail', 'products', 'product-filters', 'gift-promotions'], true)) {
            return true;
        }

        if ($area !== 'admin') {
            return false;
        }

        return in_array($segments[2] ?? '', self::ADMIN_AREAS, true)
            || (($segments[2] ?? '') === 'reports' && ($segments[3] ?? '') !== 'clinic-summary');
    }

    public function respond(Response $response, ?Throwable $exception, Request $request): Response
    {
        if (! $this->appliesTo($request) || $response->getStatusCode() < 400) {
            return $response;
        }

        $status = $response->getStatusCode();
        $payload = $response instanceof JsonResponse ? (array) $response->getData(true) : [];
        if ($exception instanceof ValidationException && $status === 422) {
            $errors = $this->validationErrors($exception, $request);
            $first = reset($errors);
            $payload = [...$payload, 'code' => 'VALIDATION_FAILED', 'errors' => $errors,
                'message' => $first[0] ?? 'Thông tin chưa hợp lệ. Vui lòng kiểm tra lại các trường đã nhập.'];
        } else {
            $code = is_string($payload['code'] ?? null) ? $payload['code'] : null;
            $payload['message'] = ($code === null ? null : $this->codeMessage($code, $payload))
                ?? $this->safeVietnameseMessage($payload['message'] ?? null)
                ?? $this->statusMessage($status);
        }

        if ($status >= 500) {
            $payload = ['message' => $this->statusMessage($status)];
        }

        if ($response instanceof JsonResponse) {
            $response->setData($payload);

            return $response;
        }

        return response()->json($payload, $status, $response->headers->all());
    }

    /** @return array<string, list<string>> */
    private function validationErrors(ValidationException $exception, Request $request): array
    {
        $failed = $exception->validator->failed();
        $errors = [];
        foreach ($exception->errors() as $field => $messages) {
            $rule = array_key_first($failed[$field] ?? []);
            $parameters = $rule === null ? [] : ($failed[$field][$rule] ?? []);
            $original = $messages[0] ?? null;
            $errors[$field] = [$rule === null
                ? ($this->safeVietnameseMessage($original) ?? $this->customValidationMessage($field, $original))
                : $this->ruleMessage($field, $rule, $parameters, $request)];
        }

        return $errors;
    }

    private function customValidationMessage(string $field, ?string $original): string
    {
        if ($original === 'Promotion code already exists.') {
            return 'Mã ưu đãi đã tồn tại. Vui lòng sử dụng mã khác.';
        }
        if ($original === 'Category hierarchy cannot contain a cycle or exceed 20 levels.') {
            return 'Danh mục cha không được tạo vòng lặp hoặc vượt quá 20 cấp.';
        }

        $specificMessages = [
            'Quantity must be a positive whole number.' => 'Số lượng phải là số nguyên lớn hơn 0. Vui lòng nhập lại.',
            'Quantity must be a non-zero whole number.' => 'Số lượng điều chỉnh phải là số nguyên khác 0. Vui lòng nhập lại.',
            'Quantity exceeds the SKU unit precision.' => 'Số lượng không đúng đơn vị của SKU. Vui lòng nhập lại.',
            'Quantity exceeds the SKU Unit precision.' => 'Số lượng không đúng đơn vị của SKU. Vui lòng nhập lại.',
            'A stock adjustment requires a reason code and detail.' => 'Vui lòng chọn lý do và nhập giải thích cho lần điều chỉnh tồn kho.',
            'Opening stock requires a source note.' => 'Vui lòng ghi rõ nguồn số dư khi nhập tồn kho đầu kỳ.',
            'Operation key must be a UUID.' => 'Mã xác nhận thao tác không hợp lệ. Vui lòng tải lại trang và thử lại.',
            'End time must be on or after start time.' => 'Thời gian kết thúc hiệu lực phải bằng hoặc sau thời gian bắt đầu.',
            'Item does not belong to this Sales Order.' => 'Mặt hàng không thuộc đơn bán hàng này. Vui lòng tải lại đơn.',
            'Gift rule or Product is no longer available.' => 'Điều kiện quà tặng hoặc sản phẩm đã thay đổi. Vui lòng chọn lại sản phẩm mua và quà tặng.',
        ];
        if (is_string($original) && isset($specificMessages[$original])) {
            return $specificMessages[$original];
        }

        return match ($field) {
            'gift_rule.buy_product_id' => 'Sản phẩm mua phải đang hoạt động và được phép bán trong kênh đã chọn.',
            'gift_rule.gift_product_id' => 'Sản phẩm quà tặng phải đang hoạt động và được phép làm quà.',
            'gift_rule.buy_variant_id' => 'SKU mua không khả dụng trong kênh bán đã chọn.',
            'gift_rule.gift_variant_id' => 'SKU quà tặng phải đang hoạt động và được theo dõi tồn kho.',
            'dealer_tier_ids' => 'Chỉ chọn hạng đại lý khi ưu đãi áp dụng cho đại lý.',
            'can_be_gift' => 'Sản phẩm chỉ dùng làm quà phải được bật tùy chọn quà tặng.',
            'track_inventory' => 'Sản phẩm quà tặng phải được theo dõi tồn kho.',
            default => ucfirst($this->fieldName($field)).' không hợp lệ. Vui lòng kiểm tra và nhập lại.',
        };
    }

    /** @param array<int, mixed> $parameters */
    private function ruleMessage(string $field, string $rule, array $parameters, Request $request): string
    {
        $name = $this->fieldName($field);
        $limit = (string) ($parameters[0] ?? '');
        if ($field === 'discount_value' && $request->input('discount_type') === 'percentage'
            && in_array($rule, ['Gt', 'Min', 'Max', 'Lte'], true)) {
            return 'Phần trăm giảm phải lớn hơn 0% và không vượt quá 100%.';
        }
        if ($field === 'discount_value' && $request->input('discount_type') === 'fixed_amount'
            && in_array($rule, ['Gt', 'Min'], true)) {
            return 'Số tiền giảm phải lớn hơn 0.';
        }
        if (in_array($field, ['sku', 'base_sku'], true) && $rule === 'Unique') {
            return 'SKU này đã tồn tại. Vui lòng sử dụng mã khác.';
        }
        if ($field === 'code' && $rule === 'Unique') {
            return 'Mã này đã tồn tại. Vui lòng sử dụng mã khác.';
        }
        if ($field === 'ends_at' && $rule === 'After') {
            return 'Thời gian kết thúc phải sau thời gian bắt đầu.';
        }
        if ($rule === 'Required' && $field === 'name' && $request->is('api/admin/products*')) {
            return 'Vui lòng nhập tên sản phẩm.';
        }
        if ($rule === 'Required' && $field === 'name' && $request->is('api/admin/sales-promotions*')) {
            return 'Vui lòng nhập tên ưu đãi.';
        }
        if ($rule === 'Required' && in_array($field, ['sku', 'base_sku'], true)) {
            return 'Vui lòng nhập SKU.';
        }
        if ($field === 'quantity' && $rule === 'NotIn') {
            return 'Số lượng điều chỉnh phải khác 0. Vui lòng nhập lại.';
        }
        if (in_array($field, ['gift_rule.minimum_buy_quantity', 'gift_rule.gift_quantity'], true)
            && in_array($rule, ['Min', 'Gt'], true)) {
            return ucfirst($name).' phải lớn hơn 0.';
        }

        return match ($rule) {
            'Required', 'RequiredIf', 'RequiredUnless', 'RequiredWith', 'RequiredWithout' => 'Vui lòng nhập hoặc chọn '.$name.'.',
            'Integer' => ucfirst($name).' phải là số nguyên.',
            'Numeric', 'Decimal' => ucfirst($name).' phải là số hợp lệ.',
            'Min' => ucfirst($name).' tối thiểu là '.$limit.'.',
            'Gt' => ucfirst($name).' phải lớn hơn '.$limit.'.',
            'Max', 'Lte' => ucfirst($name).' không được vượt quá '.$limit.'.',
            'Lt' => ucfirst($name).' phải nhỏ hơn '.$limit.'.',
            'Between' => ucfirst($name).' phải nằm trong khoảng cho phép.',
            'Exists', 'In', 'NotIn', 'Enum' => ucfirst($name).' đã chọn không tồn tại hoặc không còn khả dụng.',
            'Unique', 'Distinct' => ucfirst($name).' đã tồn tại. Vui lòng chọn giá trị khác.',
            'Email' => 'Email không đúng định dạng. Vui lòng kiểm tra lại.',
            'Uuid', 'Regex', 'AlphaDash' => ucfirst($name).' không đúng định dạng. Vui lòng kiểm tra lại.',
            'String' => ucfirst($name).' phải là văn bản hợp lệ.',
            'Array' => ucfirst($name).' không đúng định dạng. Vui lòng chọn lại.',
            'Boolean' => ucfirst($name).' phải là lựa chọn hợp lệ.',
            'Date', 'DateFormat', 'After', 'Before', 'AfterOrEqual', 'BeforeOrEqual' => ucfirst($name).' không đúng thời gian cho phép.',
            'Prohibited', 'ProhibitedIf', 'ProhibitedUnless' => 'Không được gửi '.$name.' trong thao tác này.',
            'File', 'Mimes', 'Mimetypes', 'Image' => ucfirst($name).' không đúng loại file cho phép.',
            default => ucfirst($name).' không hợp lệ. Vui lòng kiểm tra và nhập lại.',
        };
    }

    private function fieldName(string $field): string
    {
        $parts = explode('.', $field);
        $last = end($parts);

        return self::FIELD_NAMES[$last] ?? str_replace('_', ' ', $last);
    }

    /** @param array<string, mixed> $payload */
    private function codeMessage(string $code, array $payload): ?string
    {
        $details = is_array($payload['details'] ?? null) ? [...$payload, ...$payload['details']] : $payload;
        if ($code === 'VOUCHER_MINIMUM_NOT_MET' && is_numeric($details['minimum_order_amount'] ?? null)) {
            return 'Đơn hàng cần đạt tối thiểu '.number_format((float) $details['minimum_order_amount'], 0, ',', '.').'đ để sử dụng voucher này.';
        }
        $sku = is_string($details['sku'] ?? null) ? $details['sku'] : null;
        $available = $details['available'] ?? null;
        $requested = $details['requested'] ?? null;
        if (in_array($code, ['INSUFFICIENT_STOCK', 'INSUFFICIENT_AVAILABLE_STOCK', 'OUT_OF_STOCK'], true)) {
            if ($sku !== null && is_numeric($available) && is_numeric($requested)) {
                return "SKU {$sku} chỉ còn ".(int) $available.' sản phẩm, nhưng bạn đang đặt '.(int) $requested.'. Vui lòng giảm số lượng.';
            }

            return 'Sản phẩm không đủ tồn kho tại kho đã chọn. Vui lòng giảm số lượng hoặc chọn SKU khác.';
        }
        if ($code === 'DEALER_MOQ_NOT_MET') {
            $minimum = $details['minimum_quantity'] ?? null;
            if ($sku !== null && is_numeric($minimum)) {
                return "SKU {$sku} yêu cầu đặt tối thiểu ".(int) $minimum.' sản phẩm. Vui lòng tăng số lượng.';
            }

            return 'Số lượng chưa đạt mức đặt tối thiểu của đại lý. Vui lòng tăng số lượng.';
        }
        if ($code === 'DEALER_WALLET_INSUFFICIENT_BALANCE') {
            $balance = $details['balance'] ?? null;
            $required = $details['required'] ?? null;
            if (is_numeric($balance) && is_numeric($required)) {
                $shortfall = max(0, (float) $required - (float) $balance);

                return 'Số dư ví còn thiếu '.number_format($shortfall, 0, ',', '.').'đ để hoàn tất đơn hàng. Vui lòng nạp thêm tiền.';
            }

            return 'Số dư ví không đủ để hoàn tất đơn hàng. Vui lòng nạp thêm tiền.';
        }

        return match ($code) {
            'CART_EMPTY' => 'Giỏ hàng hiện không có sản phẩm. Vui lòng thêm sản phẩm trước khi đặt hàng.',
            'CHECKOUT_CHANGED', 'DEALER_ORDER_CHANGED', 'ORDER_PRICE_CHANGED' => 'Giá hoặc dữ liệu đơn hàng vừa thay đổi. Vui lòng xem lại và xác nhận lần nữa.',
            'CHECKOUT_ITEM_INVALID', 'RETAIL_SKU_NOT_ORDERABLE', 'DEALER_SKU_NOT_SELLABLE', 'SKU_UNAVAILABLE' => 'SKU này không còn được bán trong kênh đã chọn. Vui lòng chọn SKU khác.',
            'PRICE_NOT_FOUND', 'DEALER_PRICE_NOT_FOUND' => 'SKU chưa có giá hiện hành cho tài khoản của bạn. Vui lòng chọn SKU khác hoặc liên hệ hỗ trợ.',
            'PRICE_AMBIGUOUS', 'DEALER_PRICE_AMBIGUOUS' => 'Có nhiều mức giá cùng hiệu lực cho SKU. Vui lòng liên hệ quản trị viên kiểm tra bảng giá.',
            'GIFT_ONLY_PRODUCT_NOT_PURCHASABLE' => 'Sản phẩm này chỉ dùng làm quà tặng và không thể mua trực tiếp.',
            'WAREHOUSE_INACTIVE', 'CHECKOUT_WAREHOUSE_NOT_CONFIGURED', 'CHECKOUT_WAREHOUSE_AMBIGUOUS' => 'Chưa có kho bán hàng phù hợp. Vui lòng liên hệ quản trị viên kiểm tra cấu hình kho.',
            'SKU_NOT_INVENTORY_CAPABLE' => 'SKU này không được theo dõi tồn kho. Vui lòng chọn SKU khác.',
            'FULFILLMENT_EXCEEDS_RESERVATION' => 'Số lượng xuất vượt lượng hàng đã giữ cho đơn. Vui lòng kiểm tra lại.',
            'ORDER_INVALID_STATE', 'DEALER_WALLET_ORDER_INVALID_STATE' => 'Trạng thái đơn hàng hiện không cho phép thao tác này. Vui lòng tải lại đơn và kiểm tra.',
            'PAID_ORDER_REQUIRES_REFUND', 'DEALER_WALLET_REFUND_REQUIRED' => 'Đơn hàng đã thanh toán. Vui lòng hoàn tiền theo quy trình trước khi hủy.',
            'ORDER_ALREADY_PAID' => 'Đơn hàng đã được thanh toán đầy đủ. Không cần ghi nhận thêm thanh toán.',
            'PAYMENT_AMOUNT_INVALID', 'PAYMENT_EXCEEDS_OUTSTANDING' => 'Số tiền thanh toán không hợp lệ hoặc vượt số tiền còn phải thu. Vui lòng kiểm tra lại.',
            'PAYMENT_CURRENCY_MISMATCH' => 'Đơn vị tiền tệ không khớp với đơn hàng. Vui lòng chọn đúng tiền tệ.',
            'PAYMENT_EXTERNAL_REFERENCE_ALREADY_USED', 'PAYMENT_OPERATION_CONFLICT' => 'Khoản thanh toán này đã được ghi nhận hoặc mã giao dịch đã được dùng. Vui lòng kiểm tra lịch sử thanh toán.',
            'PAYMENT_LEGACY_STATUS_REQUIRES_REVIEW' => 'Dữ liệu thanh toán cũ cần được quản trị viên kiểm tra trước khi tiếp tục.',
            'NOTHING_TO_REFUND' => 'Đơn hàng chưa có khoản tiền đã thanh toán để hoàn.',
            'REFUND_EXCEEDS_REFUNDABLE_AMOUNT', 'REFUND_EXCEEDS_RETURN_VALUE' => 'Số tiền hoàn vượt quá khoản có thể hoàn. Vui lòng giảm số tiền hoàn.',
            'REFUND_AMOUNT_INVALID', 'REFUND_ALLOCATION_UNAVAILABLE' => 'Số tiền hoặc khoản thanh toán để hoàn không hợp lệ. Vui lòng kiểm tra lại.',
            'REFUND_EXTERNAL_REFERENCE_ALREADY_USED', 'REFUND_OPERATION_CONFLICT' => 'Khoản hoàn tiền này đã được xử lý. Vui lòng kiểm tra lịch sử hoàn tiền.',
            'RETURN_NOT_AVAILABLE', 'RETURN_QUANTITY_EXCEEDS_FULFILLED' => 'Chỉ có thể trả hàng đã giao và chưa được trả trước đó. Vui lòng giảm số lượng trả.',
            'RETURN_QUANTITY_INVALID', 'RETURN_UNIT_PRECISION_INVALID' => 'Số lượng trả phải là số nguyên dương. Vui lòng nhập lại.',
            'GIFT_RETURN_REQUIRED' => 'Đơn có quà tặng. Vui lòng chọn trả cả quà theo điều kiện ưu đãi.',
            'PROMOTION_NOT_FOUND', 'VOUCHER_INVALID' => 'Không tìm thấy mã ưu đãi hợp lệ. Vui lòng kiểm tra mã đã nhập.',
            'VOUCHER_NOT_FOUND' => 'Mã voucher không tồn tại.',
            'VOUCHER_INACTIVE' => 'Voucher này đã ngừng hoạt động.',
            'VOUCHER_NOT_STARTED' => 'Voucher này chưa đến thời gian sử dụng.',
            'VOUCHER_EXPIRED' => 'Voucher này đã hết hạn.',
            'VOUCHER_MINIMUM_NOT_MET' => 'Đơn hàng chưa đạt giá trị tối thiểu để sử dụng voucher này.',
            'VOUCHER_USAGE_LIMIT_REACHED' => 'Voucher này đã hết lượt sử dụng.',
            'VOUCHER_BUYER_LIMIT_REACHED' => 'Bạn đã sử dụng hết số lần cho phép của voucher này.',
            'VOUCHER_NOT_AVAILABLE_FOR_DEALER' => 'Voucher hiện không áp dụng cho đơn hàng đại lý.',
            'VOUCHER_DISCOUNT_TOO_SMALL', 'VOUCHER_ALREADY_APPLIED' => 'Voucher không thể áp dụng cho đơn hàng này.',
            'PROMOTION_NOT_STARTED' => 'Ưu đãi chưa đến thời gian áp dụng. Vui lòng chọn ưu đãi khác.',
            'PROMOTION_EXPIRED', 'PROMOTION_INACTIVE' => 'Ưu đãi đã hết hạn hoặc ngừng hoạt động. Vui lòng chọn ưu đãi khác.',
            'PROMOTION_MINIMUM_NOT_MET' => 'Đơn hàng chưa đạt giá trị tối thiểu của ưu đãi. Vui lòng thêm sản phẩm.',
            'PROMOTION_BUYER_LIMIT_REACHED', 'PROMOTION_USAGE_LIMIT_REACHED' => 'Ưu đãi đã hết lượt sử dụng. Vui lòng chọn ưu đãi khác.',
            'PROMOTION_NOT_APPLICABLE_TO_TIER' => 'Ưu đãi không áp dụng cho hạng đại lý hiện tại của bạn.',
            'PROMOTION_NOT_APPLICABLE_TO_CHANNEL' => 'Ưu đãi không áp dụng cho kênh bán này.',
            'PROMOTION_NO_ELIGIBLE_ITEMS', 'PROMOTION_DISCOUNT_TOO_SMALL' => 'Đơn hàng chưa có sản phẩm đủ điều kiện nhận ưu đãi.',
            'PROMOTION_GIFT_OUT_OF_STOCK' => 'Sản phẩm quà tặng đã hết hàng. Vui lòng chọn ưu đãi khác.',
            'PROMOTION_GIFT_RULE_INVALID' => 'Điều kiện quà tặng không còn hợp lệ. Vui lòng liên hệ quản trị viên.',
            'PROMOTION_STACKING_NOT_SUPPORTED' => 'Mỗi đơn chỉ áp dụng một ưu đãi. Vui lòng bỏ mã đang chọn trước khi đổi.',
            'DEALER_TIER_NOT_ASSIGNED', 'DEALER_INITIAL_TIER_NOT_CONFIGURED' => 'Tài khoản chưa có hạng đại lý hợp lệ. Vui lòng liên hệ quản trị viên.',
            'DEALER_TIER_INACTIVE' => 'Hạng đại lý hiện không hoạt động. Vui lòng liên hệ quản trị viên.',
            'DEALER_APPLICANT_INELIGIBLE' => 'Tài khoản này chưa đủ điều kiện đăng ký đại lý. Vui lòng kiểm tra thông tin tài khoản.',
            'DEALER_APPLICATION_PENDING' => 'Bạn đã có hồ sơ đại lý đang chờ duyệt. Vui lòng theo dõi hồ sơ hiện tại.',
            'DEALER_APPLICATION_NOT_PENDING' => 'Hồ sơ đại lý không còn ở trạng thái chờ duyệt. Vui lòng tải lại.',
            'DEALER_APPROVAL_INCOMPLETE' => 'Chưa thể duyệt hồ sơ vì thiếu thông tin đại lý. Vui lòng kiểm tra lại.',
            'DEALER_MEMBERSHIP_EXISTS' => 'Tài khoản này đã thuộc một đại lý. Vui lòng kiểm tra thông tin đại lý.',
            'DEALER_STATUS_TRANSITION_INVALID' => 'Trạng thái đại lý hiện không cho phép thay đổi này. Vui lòng tải lại.',
            'DEALER_TIER_ALREADY_CURRENT' => 'Đại lý đã ở hạng này. Vui lòng chọn hạng khác nếu cần thay đổi.',
            'DEALER_TIER_IN_USE' => 'Hạng đại lý đang được sử dụng và không thể ngừng hoạt động.',
            'DEALER_TIER_OPERATION_CONFLICT', 'DEALER_TIER_OVERRIDE_OVERLAP' => 'Thay đổi hạng đại lý trùng với một thay đổi khác. Vui lòng kiểm tra lịch sử hạng.',
            'DEALER_TIER_OVERRIDE_CANCELLED' => 'Lệnh thay đổi hạng này đã bị hủy trước đó.',
            'DEALER_TIER_OVERRIDE_AMBIGUOUS', 'DEALER_TIER_HISTORY_MISSING' => 'Lịch sử hạng đại lý cần được quản trị viên kiểm tra trước khi tiếp tục.',
            'AUTO_TIER_CHANGE_NOT_AVAILABLE', 'AUTO_TIER_POLICY_ENABLED' => 'Thay đổi hạng thủ công hiện không khả dụng do chính sách tự động. Vui lòng kiểm tra cấu hình.',
            'AUTO_TIER_RULES_INVALID', 'AUTO_TIER_LEDGER_INVALID' => 'Quy tắc hoặc dữ liệu xét hạng chưa hợp lệ. Vui lòng liên hệ quản trị viên.',
            'DEALER_TOP_UP_INVALID_STATE' => 'Yêu cầu nạp tiền không còn ở trạng thái có thể xử lý. Vui lòng tải lại.',
            'DEALER_TOP_UP_ACCOUNT_INACTIVE' => 'Tài khoản đại lý đã ngừng hoạt động. Không thể tạo yêu cầu nạp tiền.',
            'DEALER_TOP_UP_OPERATION_CONFLICT', 'DEALER_TOP_UP_PROVIDER_LINK_CONFLICT' => 'Yêu cầu nạp tiền đã được xử lý với dữ liệu khác. Vui lòng kiểm tra lịch sử nạp tiền.',
            'DEALER_TOP_UP_PROVIDER_MISMATCH', 'DEALER_TOP_UP_WEBHOOK_MISMATCH', 'DEALER_TOP_UP_WEBHOOK_INVALID' => 'Thông tin xác nhận từ cổng thanh toán không khớp. Vui lòng liên hệ hỗ trợ.',
            'DEALER_TOP_UP_PROVIDER_REFERENCE_MISSING' => 'Giao dịch chưa có mã xác nhận từ cổng thanh toán. Vui lòng thử làm mới trạng thái sau.',
            'DEALER_WALLET_NOT_FOUND' => 'Chưa tìm thấy ví đại lý. Vui lòng liên hệ quản trị viên.',
            'DEALER_WALLET_DEPOSIT_ACCOUNT_INACTIVE' => 'Tài khoản đại lý đã ngừng hoạt động. Không thể ghi nhận tiền nạp.',
            'DEALER_WALLET_DEPOSIT_AMOUNT_INVALID' => 'Số tiền nạp phải lớn hơn 0. Vui lòng nhập lại.',
            'DEALER_WALLET_DEPOSIT_OPERATION_CONFLICT', 'WALLET_DEPOSIT_REFERENCE_ALREADY_USED' => 'Giao dịch nạp tiền đã được xử lý. Vui lòng kiểm tra lịch sử ví.',
            'DEALER_WALLET_OPERATION_CONFLICT', 'DEALER_WALLET_ORDER_REQUIRED', 'DEALER_WALLET_ORDER_AMOUNT_INVALID' => 'Giao dịch ví không khớp với đơn hàng. Vui lòng tải lại và kiểm tra lịch sử ví.',
            'WIZARD_DRAFT', 'WIZARD_ALREADY_COMPLETED' => 'Sản phẩm đang ở trạng thái bản nháp. Vui lòng hoàn tất hoặc mở lại trình tạo sản phẩm.',
            'INVENTORY_VARIANT_IDENTITY_IMMUTABLE' => 'SKU đã phát sinh lịch sử kho nên không thể đổi mã hoặc cấu hình tồn kho.',
            'WAREHOUSE_CODE_IMMUTABLE', 'SUPPLIER_CODE_IMMUTABLE' => 'Mã đã dùng không thể thay đổi. Vui lòng giữ mã hiện tại.',
            'WAREHOUSE_UNIQUE_CONFLICT' => 'Mã kho hoặc kho bán hàng mặc định đã tồn tại. Vui lòng kiểm tra lại.',
            'PURCHASE_ORDER_NOT_DRAFT', 'PURCHASE_ORDER_NOT_RECEIVABLE', 'PURCHASE_ORDER_CANNOT_CANCEL' => 'Trạng thái đơn mua hàng không cho phép thao tác này. Vui lòng tải lại đơn.',
            'PURCHASE_ORDER_EMPTY' => 'Đơn mua hàng chưa có mặt hàng. Vui lòng thêm ít nhất một SKU.',
            'PURCHASE_ORDER_ITEM_INVALID', 'GOODS_RECEIPT_ITEM_INVALID' => 'Mặt hàng trên đơn mua không hợp lệ hoặc đã thay đổi. Vui lòng tải lại.',
            'PURCHASE_ORDER_HAS_NO_RECEIPTS' => 'Đơn mua chưa được nhận hàng nên chưa thể tạo phiếu trả.',
            'PURCHASE_ORDER_OVER_RECEIPT', 'PURCHASE_RETURN_EXCEEDS_RECEIPT' => 'Số lượng vượt quá số lượng có thể nhận hoặc trả. Vui lòng giảm số lượng.',
            'SUPPLIER_INACTIVE' => 'Nhà cung cấp đã ngừng hoạt động. Vui lòng chọn nhà cung cấp khác.',
            'OPENING_STOCK_ALREADY_RECORDED' => 'SKU này đã có số dư đầu kỳ tại kho đã chọn. Vui lòng dùng phiếu điều chỉnh.',
            'RETURN_ITEM_NOT_IN_ORDER', 'DUPLICATE_RETURN_ITEM' => 'Mặt hàng trả không thuộc đơn hoặc bị lặp. Vui lòng kiểm tra danh sách hàng trả.',
            'RETURN_OPERATION_CONFLICT', 'PROCUREMENT_OPERATION_KEY_CONFLICT' => 'Thao tác đã được thực hiện với dữ liệu khác. Vui lòng tải lại và kiểm tra.',
            'DEALER_DUPLICATE_SKU' => 'Một SKU xuất hiện nhiều lần trong đơn. Vui lòng gộp số lượng vào một dòng.',
            'DEALER_ORDER_INVALID', 'DEALER_ORDER_CONTEXT_INVALID' => 'Thông tin đơn đại lý chưa hợp lệ. Vui lòng xem lại SKU, giá và hạng đại lý.',
            'UNSUPPORTED_CHANNEL', 'UNSUPPORTED_ORDER_SOURCE' => 'Kênh hoặc nguồn đơn hàng không được hỗ trợ. Vui lòng chọn thao tác đặt hàng phù hợp.',
            'IMPORT_FILE_ALREADY_COMPLETED', 'EXTERNAL_ORDER_REF_ALREADY_USED' => 'File hoặc đơn này đã được nhập trước đó. Vui lòng kiểm tra lịch sử đơn hàng.',
            'DEALER_IMPORT_CHANGED' => 'Dữ liệu file đã thay đổi sau khi xem trước. Vui lòng kiểm tra lại trước khi xác nhận.',
            'INVALID_XLSX', 'UNSAFE_XLSX' => 'File Excel không hợp lệ hoặc chứa nội dung không an toàn. Vui lòng tải lại file mẫu.',
            'ORDERS_SHEET_MISSING', 'MISSING_REQUIRED_COLUMN', 'DUPLICATE_COLUMN', 'UNKNOWN_COLUMN', 'FORBIDDEN_IMPORT_COLUMN' => 'Cấu trúc file Excel không khớp mẫu. Vui lòng dùng file mẫu mới nhất.',
            'FORMULA_NOT_ALLOWED', 'TEXT_CELL_REQUIRED' => 'File Excel có ô công thức hoặc định dạng sai. Vui lòng đổi sang giá trị văn bản tĩnh.',
            'IMPORT_EMPTY' => 'File Excel chưa có mặt hàng nào. Vui lòng thêm ít nhất một dòng.',
            'XLSX_TOO_LARGE', 'IMPORT_ROW_LIMIT', 'IMPORT_ORDER_LIMIT', 'IMPORT_LINE_LIMIT' => 'File Excel vượt giới hạn cho phép. Vui lòng chia thành các file nhỏ hơn.',
            'OPERATION_KEY_CONFLICT', 'CHECKOUT_OPERATION_CONFLICT' => 'Thao tác này đã được xác nhận với dữ liệu khác. Vui lòng tải lại và thử lại.',
            default => null,
        };
    }

    private function safeVietnameseMessage(mixed $message): ?string
    {
        return is_string($message) && preg_match('/[À-ỹ]/u', $message) === 1 ? $message : null;
    }

    private function statusMessage(int $status): string
    {
        return match ($status) {
            400, 422 => 'Thông tin chưa hợp lệ. Vui lòng kiểm tra và thử lại.',
            401 => 'Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.',
            403 => 'Bạn không có quyền thực hiện thao tác này.',
            404 => 'Không tìm thấy dữ liệu yêu cầu hoặc dữ liệu đã ngừng hoạt động.',
            409 => 'Dữ liệu vừa thay đổi hoặc không còn phù hợp. Vui lòng tải lại và thử lại.',
            419 => 'Phiên bảo mật đã hết hạn. Vui lòng thử lại.',
            429 => 'Bạn thao tác quá nhanh. Vui lòng thử lại sau.',
            default => 'Hệ thống đang gặp sự cố. Vui lòng thử lại sau.',
        };
    }
}
