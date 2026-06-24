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
            'voids'                      => ['required', 'array', 'min:1', 'max:500'],
            'voids.*.original_reference' => ['required', 'string', 'max:100'],
        ];
    }
}
