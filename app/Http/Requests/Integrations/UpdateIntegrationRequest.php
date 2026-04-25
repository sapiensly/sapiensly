<?php

namespace App\Http\Requests\Integrations;

use App\Enums\IntegrationAuthType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateIntegrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // auth_config stays loosely typed on purpose — see
        // StoreIntegrationRequest::rules for why listing nested paths here
        // silently strips other keys from `validated()`. OAuth endpoint
        // shape checks live in `withValidator()` instead.
        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'base_url' => ['sometimes', 'required', 'string', 'max:500', 'regex:/^https?:\/\//i'],
            'auth_type' => ['sometimes', 'required', 'string', Rule::in(array_column(IntegrationAuthType::cases(), 'value'))],
            'auth_config' => ['nullable', 'array'],
            'default_headers' => ['nullable', 'array'],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
            'color' => ['nullable', 'string', 'max:7'],
            'icon' => ['nullable', 'string', 'max:50'],
            'allow_insecure_tls' => ['nullable', 'boolean'],
            'active_environment_id' => ['nullable', 'string', 'exists:integration_environments,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        // On update, blank client_id / client_secret means "keep existing"
        // per IntegrationService::update merge logic — so we only validate
        // shape of the non-secret endpoints when they're supplied.
        $validator->after(function (Validator $v) {
            $cfg = (array) $this->input('auth_config', []);
            foreach (['authorize_url', 'token_url'] as $field) {
                $value = $cfg[$field] ?? null;
                if (is_string($value) && $value !== '' && ! preg_match('/^https?:\/\//i', $value)) {
                    $v->errors()->add(
                        "auth_config.{$field}",
                        __('The :attribute must start with http:// or https://.', [
                            'attribute' => str_replace('_', ' ', $field),
                        ]),
                    );
                }
            }
        });
    }
}
