<?php

namespace Modules\Payment\Services;

/**
 * The {amount} placeholder in a firm's email subjects and bodies. US dollars read "$8.00";
 * any other currency "8.00 EUR". Firms often type "${amount}" in their templates — that
 * "$" is dropped so it never reads "$$8.00" (or, as before, "$USD 8.00").
 */
final class EmailAmount
{
    public static function format(int $cents, ?string $currency): string
    {
        $currency = strtoupper(trim((string) $currency)) ?: 'USD';
        $amount   = number_format($cents / 100, 2);

        return $currency === 'USD' ? '$'.$amount : $amount.' '.$currency;
    }

    /**
     * @param  array<string, string>  $data  placeholder name => value, without braces
     */
    public static function fill(string $template, array $data): string
    {
        if (array_key_exists('amount', $data)) {
            $template = preg_replace('/\$\s*\{amount\}/', '{amount}', $template) ?? $template;
        }

        $search  = array_map(fn ($key) => '{'.$key.'}', array_keys($data));

        return str_replace($search, array_values($data), $template);
    }
}
