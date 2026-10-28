<?php

namespace App\Http\Controllers\Web\Retail;

use App\Http\Controllers\Controller;
use App\Http\Requests\RetailCheckoutRequest;
use App\Services\RetailCheckoutService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CheckoutController extends Controller
{
    public function create(Request $request, RetailCheckoutService $checkout): View|RedirectResponse
    {
        try {
            $review = $checkout->review($request->user()->id);
        } catch (HttpResponseException $exception) {
            if ($exception->getResponse()->getStatusCode() !== 409) {
                throw $exception;
            }
            $code = $exception->getResponse()->getData(true)['code'] ?? 'CHECKOUT_UNAVAILABLE';

            return redirect()->route('retail.web.cart.index')
                ->with('error', 'Chưa thể thanh toán ('.$code.'). Vui lòng kiểm tra giỏ hàng.');
        }

        return view('retail.checkout.create', [
            'review' => $review,
            'operationKey' => old('checkout_operation_key', (string) Str::uuid()),
        ]);
    }

    public function store(RetailCheckoutRequest $request, RetailCheckoutService $checkout): RedirectResponse
    {
        try {
            $order = $checkout->checkout($request->user()->id, $request->validated());
        } catch (HttpResponseException $exception) {
            if ($exception->getResponse()->getStatusCode() !== 409) {
                throw $exception;
            }
            $code = $exception->getResponse()->getData(true)['code'] ?? 'CHECKOUT_UNAVAILABLE';
            throw ValidationException::withMessages(['checkout' => 'Đơn hàng đã thay đổi hoặc chưa hợp lệ ('.$code.'). Vui lòng xem lại trước khi đặt.']);
        }

        return redirect()->route('retail.web.orders.show', $order->id)
            ->with('success', 'Đã tạo đơn '.$order->order_code.'. Đơn đang chờ xử lý; phương thức thanh toán chưa được xác nhận là đã trả.');
    }
}
