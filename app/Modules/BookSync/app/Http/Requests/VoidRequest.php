<?php

namespace Modules\BookSync\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VoidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'original_reference' => ['required', 'string', 'max:100'],
        ];
    }
}
