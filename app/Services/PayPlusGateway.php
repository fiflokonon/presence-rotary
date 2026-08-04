<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Payplus\Pay\PayPlus;

class PayPlusGateway
{
    /**
     * @param  array<string, mixed>  $customData
     * @return array{success: bool, token?: string, message?: string}
     */
    public function initiate(
        float $amount,
        string $description,
        string $phone,
        string $customerFirstName,
        string $customerLastName,
        string $customerEmail,
        array $customData,
    ): array {
        if (blank(config('payplus.api_key')) || blank(config('payplus.token'))) {
            return ['success' => false, 'message' => 'Configuration PayPlus manquante'];
        }

        $checkout = (new PayPlus)->init();

        $checkout->addItem($description, 1, $amount, $amount);
        $checkout->setTotalAmount($amount);
        $checkout->setDescription($description);

        foreach ($customData as $key => $value) {
            $checkout->addCustomData($key, $value);
        }

        $checkout->setCustomerNumber($phone);
        $checkout->setCustomerFirstName($customerFirstName);
        $checkout->setCustomerLastName($customerLastName);
        $checkout->setCustomerEmail($customerEmail);
        $checkout->setDevise('xof');
        $checkout->setOtp('');

        $result = $checkout->launchPaiement();

        if (isset($result->token)) {
            return ['success' => true, 'token' => $result->token];
        }

        return ['success' => false, 'message' => $result->message ?? "Erreur lors de l'initialisation du paiement"];
    }

    /**
     * @return array{success: bool, status?: ?string, amount?: ?float, custom_data?: array<string, mixed>, message?: string}
     */
    public function fetchStatus(string $token): array
    {
        if (blank(config('payplus.api_key')) || blank(config('payplus.token'))) {
            return ['success' => false, 'message' => 'Configuration PayPlus manquante'];
        }

        $baseUrl = rtrim((string) config('payplus.base_url', 'https://app.payplus.africa'), '/');
        $url = "{$baseUrl}/pay/v01/straight/checkout-invoice/confirm?invoiceToken={$token}";

        $response = Http::withHeaders([
            'Apikey' => config('payplus.api_key'),
            'Authorization' => 'Bearer '.config('payplus.token'),
        ])->get($url);

        if (! $response->successful()) {
            return ['success' => false, 'message' => 'La requête a échoué. Veuillez réessayer ultérieurement.'];
        }

        $data = $response->json();

        if (($data['response_code'] ?? null) !== '00') {
            return ['success' => false, 'message' => $data['response_text'] ?? 'Erreur de traitement'];
        }

        $customData = [];
        foreach ($data['custom_data'] ?? [] as $item) {
            $customData[$item['keyof_customdata']] = $item['valueof_customdata'];
        }

        return [
            'success' => true,
            'status' => $data['status'] ?? null,
            'amount' => $data['montant'] ?? null,
            'custom_data' => $customData,
        ];
    }
}
