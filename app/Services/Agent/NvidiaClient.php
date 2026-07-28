<?php

namespace App\Services\Agent;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client HTTP vers l'API NVIDIA integrate.api.nvidia.com, compatible
 * OpenAI chat/completions (function-calling inclus). Utilisé par les deux
 * modèles disponibles (Kimi K2.6 via Moonshot, DeepSeek) — un seul endpoint,
 * seul le modèle et la clé API changent.
 */
class NvidiaClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeout = 300,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $messages  Format OpenAI chat (role/content/tool_calls/tool_call_id)
     * @param  array<int, array<string, mixed>>  $tools  Définitions de fonctions au format OpenAI function-calling
     * @return array{message: array<string, mixed>, finish_reason: string, usage: array<string, mixed>}
     */
    public function chat(string $model, string $apiKey, array $messages, array $tools = []): array
    {
        if ($apiKey === '') {
            throw new RuntimeException("Aucune clé API NVIDIA configurée pour le modèle [{$model}].");
        }

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.3,
            'top_p' => 1,
            'max_tokens' => 8192,
            'stream' => false,
        ];

        if ($tools !== []) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout($this->timeout)
            ->post(rtrim($this->baseUrl, '/').'/chat/completions', $payload);

        if ($response->failed()) {
            throw new RuntimeException(
                "Appel API NVIDIA échoué pour [{$model}] (HTTP {$response->status()}) : ".$response->body()
            );
        }

        $data = $response->json();
        $choice = $data['choices'][0] ?? null;

        if (! $choice) {
            throw new RuntimeException("Réponse NVIDIA invalide pour [{$model}] : ".$response->body());
        }

        return [
            'message' => $choice['message'] ?? [],
            'finish_reason' => $choice['finish_reason'] ?? 'stop',
            'usage' => $data['usage'] ?? [],
        ];
    }
}
