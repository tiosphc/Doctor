<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\Doctor;
use App\Models\User;
use App\Services\DealerContextService;
use App\Services\RegisteredCustomerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BladeAuthController extends Controller
{
    public function showLogin(Request $request, DealerContextService $dealerContext): View|RedirectResponse
    {
        $user = Auth::guard('web')->user();
        if ($user instanceof User) {
            return $this->redirectFor($user, $dealerContext);
        }

        return view('auth.login', ['returnTo' => $this->bladeReturnPath($request, $request->query('return_to'))]);
    }

    public function login(LoginRequest $request, DealerContextService $dealerContext): RedirectResponse
    {
        if (! Auth::guard('web')->attempt($request->safe()->only(['email', 'password']))) {
            throw ValidationException::withMessages(['email' => 'Email hoặc mật khẩu không đúng.']);
        }

        $user = Auth::guard('web')->user();

        if ($user instanceof User && $user->isDoctor()) {
            if ($user->must_change_password) {
                $this->rejectLogin($request);

                throw ValidationException::withMessages(['email' => 'Tài khoản bác sĩ chưa hoàn tất thiết lập mật khẩu. Vui lòng sử dụng email mời hoặc liên hệ quản trị viên.']);
            }

            if ($user->doctorProfile?->status !== Doctor::STATUS_ACTIVE) {
                $this->rejectLogin($request);

                throw ValidationException::withMessages(['email' => 'Tài khoản bác sĩ hiện không hoạt động. Vui lòng liên hệ quản trị viên.']);
            }
        }

        $request->session()->regenerate();

        if ($user instanceof User && $user->isCustomer()) {
            $returnTo = $this->bladeReturnPath($request, $request->input('return_to'))
                ?? $this->bladeReturnPath($request, $request->session()->pull('url.intended'));
            if ($returnTo !== null && (! str_starts_with($returnTo, '/dealer/')
                || $this->isDealerApplicationPath($returnTo)
                || $dealerContext->primaryAccount($user) !== null)) {
                return redirect()->to($returnTo);
            }
        }

        return $this->redirectFor($user, $dealerContext);
    }

    public function showRegister(DealerContextService $dealerContext): View|RedirectResponse
    {
        $user = Auth::guard('web')->user();
        if ($user instanceof User) {
            return $this->redirectFor($user, $dealerContext);
        }

        return view('auth.register');
    }

    public function register(RegisterRequest $request, RegisteredCustomerService $customers): RedirectResponse
    {
        $validated = $request->validated();

        $user = DB::transaction(function () use ($validated, $customers): User {
            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'password' => Hash::make($validated['password']),
                'role' => User::ROLE_CUSTOMER,
            ]);
            $customers->ensureForUser($user);

            return $user;
        }, 3);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->route('retail.web.shop.index');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function redirectFor(User $user, DealerContextService $dealerContext): RedirectResponse
    {
        if ($user->isAdmin()) {
            return redirect()->route('admin.web.dashboard');
        }

        if ($user->isCustomer() && $dealerContext->primaryAccount($user) !== null) {
            return redirect()->route('dealer.web.orders.index');
        }

        if ($user->isCustomer()) {
            return redirect()->route('retail.web.shop.index');
        }

        $path = match ($user->role) {
            User::ROLE_DOCTOR => '/doctor',
            User::ROLE_RECEPTIONIST => '/receptionist',
            default => '/account',
        };

        return redirect()->to($this->frontendPath($path));
    }

    private function frontendPath(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/').$path;
    }

    private function rejectLogin(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private function bladeReturnPath(Request $request, mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 2048
            || preg_match('/[\x00-\x1F\x7F\\\\]/', $value) === 1) {
            return null;
        }
        $parts = parse_url($value);
        if ($parts === false || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        if (str_starts_with($value, '/')) {
            if (str_starts_with($value, '//') || isset($parts['host'], $parts['scheme'])) {
                return null;
            }
        } elseif (! isset($parts['scheme'], $parts['host'])
            || strtolower($parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : ''))
                !== strtolower($request->getSchemeAndHttpHost())) {
            return null;
        }
        $path = $parts['path'] ?? null;
        if (! is_string($path)) {
            return null;
        }

        if (preg_match('~^/(?:shop(?:/[a-zA-Z0-9_-]+)?|cart|checkout|account/profile|my-orders(?:/[0-9]+(?:/returns(?:/create|/[0-9]+)?)?)?|dealer/(?:apply|application(?:/[0-9]+)?|products(?:/[a-zA-Z0-9_-]+)?|profile|promotions|addresses(?:/create|/[0-9]+/edit)?|wallet(?:/deposit-requests/[0-9]+)?|orders(?:/import(?:/[0-9]+)?|/[0-9]+(?:/returns(?:/create|/[0-9]+)?)?)?|quick-order))$~', $path) !== 1) {
            return null;
        }

        $query = $parts['query'] ?? '';
        if ($query !== '' && $path === '/dealer/quick-order') {
            return preg_match('/^variant_id=[1-9][0-9]*$/D', $query) === 1
                ? $path.'?'.$query : null;
        }

        return $path;
    }

    private function isDealerApplicationPath(string $path): bool
    {
        return $path === '/dealer/apply' || $path === '/dealer/application'
            || preg_match('~^/dealer/application/[0-9]+$~', $path) === 1;
    }
}
