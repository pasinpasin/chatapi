<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    private string $apiKey;
    private string $model;
    private string $baseUrl;

   public function __construct()
{
    $this->apiKey  = config('services.gemini.key');
    $this->model   = config('services.gemini.model', 'gemini-1.5-flash');
    $this->baseUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent";
}

    public function chat(string $systemPrompt, array $history, string $userMessage): string
{
    $contents = [];

    foreach ($history as $msg) {
        $contents[] = [
            'role'  => $msg['role'] === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $msg['content']]],
        ];
    }

    $contents[] = [
        'role'  => 'user',
        'parts' => [['text' => $userMessage]],
    ];

    // Retry 3 here ne rast 429
    $attempts = 0;
    $maxAttempts = 3;

    while ($attempts < $maxAttempts) {
        $response = Http::timeout(30)
            ->withoutVerifying()
            ->post("{$this->baseUrl}?key={$this->apiKey}", [
                'system_instruction' => [
                    'parts' => [['text' => $systemPrompt]],
                ],
                'contents'         => $contents,
                'generationConfig' => [
                    'temperature'     => 0.7,
                    'maxOutputTokens' => 800,
                ],
            ]);

        if ($response->status() === 429) {
            $attempts++;
            if ($attempts < $maxAttempts) {
                sleep(2); // prit 2 sekonda dhe provo perseri
                continue;
            }
            throw new \Exception('Shërbimi është i ngarkuar. Provoni përsëri pas pak.');
        }

        if (!$response->successful()) {
            throw new \Exception('AI service error: ' . $response->status());
        }

        $data = $response->json();

        return $data['candidates'][0]['content']['parts'][0]['text']
            ?? 'Na vjen keq, nuk mund të përgjigjem tani.';
    }

    throw new \Exception('Shërbimi është i ngarkuar. Provoni përsëri pas pak.');
}
}