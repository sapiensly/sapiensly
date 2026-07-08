<?php

namespace App\Http\Requests\App;

use App\Enums\Visibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAppRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Both optional now: an app starts unnamed and the first builder
            // prompt names it; the slug is always auto-derived unique server-side.
            'name' => ['nullable', 'string', 'max:100'],
            'slug' => ['nullable', 'string', 'regex:/^[a-z][a-z0-9_]*$/', 'max:50'],
            'description' => ['nullable', 'string', 'max:500'],
            'icon' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'visibility' => ['nullable', Rule::enum(Visibility::class)],
        ];
    }
}
