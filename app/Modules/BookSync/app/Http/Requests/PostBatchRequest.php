<?php

namespace Modules\BookSync\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PostBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'batch_date'                    => ['nullable', 'date_format:Y-m-d'],
            'transactions'                  => ['required', 'array', 'min:1', 'max:500'],
            'transactions.*.reference'      => ['required', 'string', 'max:100'],
            'transactions.*.customer_name'  => ['required', 'string', 'max:255'],
            'transactions.*.customer_email' => ['nullable', 'email', 'max:255'],
            'transactions.*.amount'         => ['required', 'numeric', 'min:0.01'],
            'transactions.*.payment_method' => ['nullable', 'string', 'in:Cash,Credit Card,Debit Card,Check,Other'],
            'transactions.*.date'           => ['nullable', 'date_format:Y-m-d'],
            'transactions.*.memo'           => ['nullable', 'string', 'max:500'],
            'transactions.*.surcharge'      => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
