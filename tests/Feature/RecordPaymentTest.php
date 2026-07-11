<?php

namespace Tests\Feature;

use Tests\TestCase;
use Modules\Inbound\Services\AdvancedMdApiClient;
use App\Models\AdvancedMdPractice;

class RecordPaymentTest extends TestCase
{
    public function test_record_payment()
    {
        $practice = AdvancedMdPractice::find(
            '019f4acd-fc7d-73d3-b080-3e53040d2c38'
        );

        $payload = [
                "allowTransactionDuplicates" => false,
                "appointmentId" =>  "",
                "carrierId" => null,
                "charges" => [],
                "checkId" => null,
                "checkNumber" => "",
                "creditCardAuthorizationResponse" => null,
                "creditCardExpirationMonth" => null,
                "creditCardExpirationYear" => null,
                "creditCardLastFourDigits" => null,
                "creditCardName" => null,
                "creditCardOnFileId" => null,
                "creditCardToken" => null,
                "cvnFilled" => false,
                "depositDate" => now()->format('Y-m-d'),
                "patientId" =>6114039,
                "paySource" => 2,
                "paymentAmount" => 400,
                "paymentCode" => "PP",
                "paymentMethodId" => 1,
                "postingMethod" => "Trans Entry",
                "profileId" => "prof5",
                "respPartyId" => "resp6984505",
                "transactionId" => null,
                "unappliedPaymentAmount" => 400,
                "unappliedVisitId" => "",
                "zipCode" => "15136",
            ];

        $client = app(AdvancedMdApiClient::class);

        $response = $client->recordPayment($practice, $payload);

        dump($response);

        $this->assertIsArray($response);
    }
}
