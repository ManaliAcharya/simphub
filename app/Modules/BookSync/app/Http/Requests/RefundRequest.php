<?php

namespace Modules\BookSync\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'refunds'                      => ['required', 'array', 'min:1', 'max:500'],
            'refunds.*.reference'          => ['required', 'string', 'max:100'],
            'refunds.*.original_reference' => ['nullable', 'string', 'max:100'],
            'refunds.*.amount'             => ['required', 'numeric', 'min:0.01'],
            'refunds.*.payment_method'     => ['nullable', 'string', 'in:Cash,Credit Card,Debit Card,Check,Other'],
            'refunds.*.customer_name'      => ['nullable', 'string', 'max:255'],
            'refunds.*.customer_email'     => ['nullable', 'email', 'max:255'],
            'refunds.*.transaction_date'   => ['nullable', 'date_format:Y-m-d'],
            'refunds.*.memo'               => ['nullable', 'string', 'max:500'],
        ];
    }
}
