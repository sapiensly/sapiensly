<?php

namespace App\Http\Requests\Document;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;

class UpdateInlineDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Document|null $document */
        $document = $this->route('document');

        return $document !== null
            && $this->user()?->can('update', $document);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10485760'],
            'keywords' => ['nullable', 'array', 'max:20'],
            'keywords.*' => ['string', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'body.max' => __('Document body must not exceed 10 MB.'),
        ];
    }
}
