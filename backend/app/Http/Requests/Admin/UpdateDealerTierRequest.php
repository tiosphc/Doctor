<?php

namespace App\Http\Requests\Admin;

use App\Models\DealerTier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDealerTierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tier = $this->route('tier');
        $isPreset = $tier instanceof DealerTier && in_array($tier->code, ['SILVER', 'GOLD', 'DIAMOND'], true);

        return [
            'code' => ['prohibited'],
            'name' => [$isPreset ? 'prohibited' : 'sometimes', 'string', 'max:255'],
            'status' => [$isPreset ? 'prohibited' : 'sometimes', Rule::in(['active', 'inactive'])],
            'sort_order' => [$isPreset ? 'prohibited' : 'sometimes', 'integer', 'min:0', 'max:100000'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_default_initial' => [$isPreset ? 'prohibited' : 'sometimes', 'boolean'],
            'revenue_threshold' => $isPreset
                ? ['sometimes', 'required', 'regex:/^(?:0|[1-9][0-9]{0,17})$/']
                : ['sometimes', 'nullable', 'regex:/^(?:0|[1-9][0-9]{0,17})$/'],
        ];
    }
}
