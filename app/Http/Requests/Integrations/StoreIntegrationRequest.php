<?php

namespace App\Http\Requests\Integrations;

use App\Support\Integrations\IntegrationRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreIntegrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return IntegrationRules::store($this->input('kind'));
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            IntegrationRules::validateOAuth2OnStore(
                $v,
                (array) $this->input('auth_config', []),
                $this->input('auth_type'),
            );
        });
    }
}
