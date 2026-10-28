<?php

namespace App\Http\Requests\Admin;

use App\Models\Product;
use App\Support\BladeProductWizardPayload;

class BladeCompleteProductWizardRequest extends CompleteProductWizardRequest
{
    public function authorize(): bool
    {
        $product = $this->route('product');
        abort_unless($product instanceof Product && $product->wizard_key !== null
            && $product->wizard_owner_user_id === $this->user()?->id
            && in_array($product->status, ['draft', 'active'], true), 404);

        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_array($this->input('data'))) {
            $this->merge(['data' => BladeProductWizardPayload::normalize($this->input('data'))]);
        }
        parent::prepareForValidation();
    }
}
