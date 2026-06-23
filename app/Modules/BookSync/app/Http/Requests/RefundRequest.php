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
            'reference'          => ['required', 'string', 'max:100'],
            'original_reference' => ['nullable', 'string', 'max:100'],
            'amount'             => ['required', 'numeric', 'min:0.01'],
            'payment_method'     => ['nullable', 'string', 'in:Cash,Credit Card,Debit Card,Check,Other'],
            'customer_name'      => ['nullable', 'string', 'max:255'],
            'customer_email'     => ['nullable', 'email', 'max:255'],
            'transaction_date'   => ['nullable', 'date_format:Y-m-d'],
            'memo'               => ['nullable', 'string', 'max:500'],
        ];
    }
}
