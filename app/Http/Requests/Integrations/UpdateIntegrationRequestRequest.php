<?php

namespace App\Http\Requests\Integrations;

use Illuminate\Validation\Rule;

class UpdateIntegrationRequestRequest extends StoreIntegrationRequestRequest
{
    public function rules(): array
    {
        return array_merge(
            $this->sharedRules(),
            [
                'name' => ['sometimes', 'required', 'string', 'max:150'],
                'method' => ['sometimes', 'required', 'string', Rule::in(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'])],
                'path' => ['sometimes', 'required', 'string', 'max:1000'],
            ],
        );
    }
}
