<?php

namespace App\Http\Controllers\Web\Retail;

use App\Http\Controllers\Controller;
use App\Services\RetailCartService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CartController extends Controller
{
    public function index(Request $request, RetailCartService $carts): View
    {
        return view('retail.cart.index', ['cart' => $carts->show($request->user()->id)]);
    }

    public function add(Request $request, RetailCartService $carts): RedirectResponse
    {
        $data = $request->validate([
            'product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'unit_price' => ['prohibited'], 'grand_total' => ['prohibited'],
        ]);
        $this->runDomain(fn () => $carts->add($request->user()->id,
            (int) $data['product_variant_id'], (string) $data['quantity']));

        return redirect()->route('retail.web.cart.index')->with('success', 'Đã thêm sản phẩm vào giỏ hàng.');
    }

    public function update(Request $request, int $cartItem, RetailCartService $carts): RedirectResponse
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1'],
            'unit_price' => ['prohibited'], 'grand_total' => ['prohibited']]);
        $this->runDomain(fn () => $carts->update($request->user()->id, $cartItem, (string) $data['quantity']));

        return redirect()->route('retail.web.cart.index')->with('success', 'Đã cập nhật số lượng.');
    }

    public function remove(Request $request, int $cartItem, RetailCartService $carts): RedirectResponse
    {
        $this->runDomain(fn () => $carts->remove($request->user()->id, $cartItem));

        return redirect()->route('retail.web.cart.index')->with('success', 'Đã xóa sản phẩm khỏi giỏ.');
    }

    public function clear(Request $request, RetailCartService $carts): RedirectResponse
    {
        $this->runDomain(fn () => $carts->clear($request->user()->id));

        return redirect()->route('retail.web.cart.index')->with('success', 'Đã xóa giỏ hàng.');
    }

    public function voucher(Request $request, RetailCartService $carts): RedirectResponse
    {
        $data = $request->validate(['code' => ['nullable', 'string', 'max:32']]);
        $this->runDomain(fn () => $carts->applyVoucher($request->user()->id, $data['code'] ?? null));

        return redirect()->route('retail.web.cart.index')->with('success', 'Đã cập nhật voucher.');
    }

    private function runDomain(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (HttpResponseException $exception) {
            if ($exception->getResponse()->getStatusCode() !== 409) {
                throw $exception;
            }
            $code = $exception->getResponse()->getData(true)['code'] ?? 'CART_CHANGED';
            throw ValidationException::withMessages(['cart' => 'Không thể cập nhật giỏ hàng ('.$code.'). Vui lòng kiểm tra lại sản phẩm.']);
        }
    }
}
