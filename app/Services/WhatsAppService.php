<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{

private string $baseUrl;
private ?string $token;
private ?string $phoneNumberId;

public function __construct()
{
    $this->token         = config('services.whatsapp.token');
    $this->phoneNumberId = config('services.whatsapp.phone_number_id');
    $this->baseUrl       = "https://graph.facebook.com/v19.0/{$this->phoneNumberId}/messages";
}

    public function sendMessage(string $to, string $message): bool
    {

     
        $response = Http::withoutVerifying()
            ->withToken($this->token)
            ->post($this->baseUrl, [
                'messaging_product' => 'whatsapp',
                'to'                => $to,
                'type'              => 'text',
                'text'              => ['body' => $message],
            ]);

        if (!$response->successful()) {
            Log::error('WhatsApp send error: ' . $response->body());
            return false;
        }

        return true;
    }
}
