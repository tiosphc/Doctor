<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->away(rtrim((string) config('app.frontend_url'), '/').'/'));

Route::get('/login', function (Request $request) {
    $returnTo = $request->query('return_to');
    $reactPath = is_string($returnTo) && preg_match('~^/(?:cart|checkout|my-orders(?:/[0-9]+)?|products(?:/[a-zA-Z0-9_-]+)?)$~D', $returnTo) === 1
        ? $returnTo : null;
    if ($returnTo === '/shop') {
        $reactPath = '/products';
    } elseif (is_string($returnTo) && preg_match('~^/shop/([a-zA-Z0-9_-]+)$~D', $returnTo, $matches) === 1) {
        $reactPath = '/products/'.$matches[1];
    }

    $loginUrl = rtrim((string) config('app.frontend_url'), '/').'/login';

    return redirect()->away($reactPath === null ? $loginUrl : $loginUrl.'?returnTo='.rawurlencode($reactPath));
})->name('login');

Route::get('/register', fn () => redirect()->away(rtrim((string) config('app.frontend_url'), '/').'/register'))->name('register');

Route::get('/shop', fn () => redirect()->away(rtrim((string) config('app.frontend_url'), '/').'/products'));
Route::get('/shop/{product}', fn (string $product) => redirect()->away(rtrim((string) config('app.frontend_url'), '/').'/products/'.rawurlencode($product)));
Route::get('/admin/{path?}', fn () => redirect()->away(rtrim((string) config('app.frontend_url'), '/').'/admin'))
    ->where('path', '.*');
Route::get('/dealer/{path?}', fn () => redirect()->away(rtrim((string) config('app.frontend_url'), '/').'/dealer'))
    ->where('path', '.*');
Route::get('/account/{path?}', fn () => redirect()->away(rtrim((string) config('app.frontend_url'), '/').'/account'))
    ->where('path', '.*');
Route::get('/cart', fn () => redirect()->away(rtrim((string) config('app.frontend_url'), '/').'/cart'));
Route::get('/checkout', fn () => redirect()->away(rtrim((string) config('app.frontend_url'), '/').'/checkout'));
Route::get('/my-orders/{path?}', fn () => redirect()->away(rtrim((string) config('app.frontend_url'), '/').'/my-orders'))
    ->where('path', '.*');
