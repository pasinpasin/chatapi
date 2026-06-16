<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GroqService
{
    private string $apiKey;
    private string $model;
    private string $baseUrl = 'https://api.groq.com/openai/v1/chat/completions';

    public function __construct()
    {
        $this->apiKey = config('services.groq.key');
        $this->model  = config('services.groq.model', 'llama-3.1-8b-instant');
    }

    public function chat(string $systemPrompt, array $history, string $userMessage): string
    {
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        foreach ($history as $msg) {
            $messages[] = [
                'role'    => $msg['role'],
                'content' => $msg['content'],
            ];
        }

        $messages[] = [
            'role'    => 'user',
            'content' => $userMessage,
        ];

        $response = Http::timeout(30)
            ->withoutVerifying()
            ->withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
            ])
            ->post($this->baseUrl, [
                'model'       => $this->model,
                'messages'    => $messages,
                'temperature' => 0.7,
                'max_tokens'  => 800,
            ]);

        if ($response->status() === 429) {
            throw new \Exception('Shërbimi është i ngarkuar. Provoni përsëri pas pak.');
        }

        if (!$response->successful()) {
            Log::error('Groq API error: ' . $response->body());
            throw new \Exception('AI service error: ' . $response->status());
        }

        $data = $response->json();

        return $data['choices'][0]['message']['content']
            ?? 'Na vjen keq, nuk mund të përgjigjem tani.';
    }
}