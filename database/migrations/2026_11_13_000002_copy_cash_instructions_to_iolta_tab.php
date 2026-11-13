<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Lawcus/Clio set the cash/check "Additional instructions" per account (e.g. a separate
     * Zelle for IOLTA), stored as cash_discount_details.trust_instructions. Trust payment
     * pages keep today's instructions until someone changes the IOLTA field.
     */
    public function up(): void
    {
        DB::table('clients')
            ->whereRaw('UPPER(client_pms) IN (?, ?)', ['LAWCUS', 'CLIO'])
            ->whereNotNull('cash_discount_details')
            ->orderBy('id')
            ->each(function (object $client): void {
                $details = json_decode((string) $client->cash_discount_details, true);

                if (! is_array($details) || empty($details['instructions']) || array_key_exists('trust_instructions', $details)) {
                    return;
                }

                $details['trust_instructions'] = $details['instructions'];

                DB::table('clients')->where('id', $client->id)->update(['cash_discount_details' => json_encode($details)]);
            });
    }

    public function down(): void
    {
        DB::table('clients')
            ->whereNotNull('cash_discount_details')
            ->orderBy('id')
            ->each(function (object $client): void {
                $details = json_decode((string) $client->cash_discount_details, true);

                if (! is_array($details) || ! array_key_exists('trust_instructions', $details)) {
                    return;
                }

                unset($details['trust_instructions']);

                DB::table('clients')->where('id', $client->id)->update(['cash_discount_details' => json_encode($details)]);
            });
    }
};
