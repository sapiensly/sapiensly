<?php

namespace App\Http\Requests\Integrations;

use App\Support\Integrations\IntegrationRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateIntegrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return IntegrationRules::update($this->input('kind'));
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            IntegrationRules::validateOAuth2OnUpdate($v, (array) $this->input('auth_config', []));
        });
    }
}
