<?php

namespace App\Http\Requests\Admin;

use App\Models\Product;
use App\Support\BladeProductWizardPayload;

class BladeSaveProductWizardDraftRequest extends SaveProductWizardDraftRequest
{
    public function authorize(): bool
    {
        $product = $this->route('product');
        abort_unless($product instanceof Product && $product->status === 'draft'
            && $product->wizard_key !== null && $product->wizard_owner_user_id === $this->user()?->id, 404);

        return true;
    }

    protected function prepareForValidation(): void
    {
        $product = $this->route('product');
        if ($product instanceof Product) {
            $this->merge(['wizard_key' => $product->wizard_key]);
        }
        if (is_array($this->input('data'))) {
            $this->merge(['data' => BladeProductWizardPayload::normalize($this->input('data'))]);
        }
        parent::prepareForValidation();
    }
}
