<?php

namespace App\Http\Controllers\Web\Retail;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Services\UserProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function show(Request $request): View
    {
        return view('retail.profile.show', ['user' => $request->user()]);
    }

    public function update(UpdateProfileRequest $request, UserProfileService $profileService): RedirectResponse
    {
        $profileService->update($request->user(), $request->safe()->only(['name', 'phone']));

        return redirect()->route('retail.web.profile.show')->with('success', 'Thông tin cá nhân đã được cập nhật.');
    }
}
