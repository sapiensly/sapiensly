<?php

namespace App\Http\Requests\Debate;

use App\Models\Debate;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDebateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $debate = $this->route('debate');

        return $debate instanceof Debate && $debate->user_id === $this->user()?->id;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
