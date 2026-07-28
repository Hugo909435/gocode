<?php

namespace App\Jobs;

use App\Agent\AgentEvent;
use App\Agent\AgentEventDispatcher;
use App\Agent\AgentEventType;
use App\Models\Session;
use App\Services\Agent\NvidiaClient;
use App\Services\Agent\ToolExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

/**
 * En mode read/plan, fait réfléchir Kimi K2.6 ET DeepSeek sur la même
 * instruction (chacun explore le code avec ses outils en lecture seule),
 * puis fusionne les deux réponses en une synthèse unique via un appel
 * supplémentaire. Le mode execute reste piloté par un seul modèle
 * (NvidiaAgentJob) — deux agents qui éditeraient les mêmes fichiers en
 * parallèle produiraient des conflits, donc pas de collaboration à l'écriture.
 */
class NvidiaCollabJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout;

    public function __construct(
        public readonly string $sessionId,
        public readonly string $instruction,
        public readonly string $mode,
    ) {
        // Deux boucles d'outils séquentielles + un appel de synthèse.
        $this->timeout = (int) config('agent.drivers.nvidia.timeout', 300) * 2 + 180;
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->sessionId))
                ->dontRelease()
                ->expireAfter($this->timeout + 120),
        ];
    }

    public function handle(AgentEventDispatcher $dispatcher, ToolExecutor $tools): void
    {
        $session = Session::with('project')->find($this->sessionId);

        if (! $session || ! $session->project) {
            return;
        }

        $project = $session->project;

        if (! $project->path || ! is_dir($project->path)) {
            $session->update(['status' => 'error', 'ended_at' => now()]);
            $dispatcher->dispatch(AgentEvent::make(
                AgentEventType::Error,
                $this->sessionId,
                ['message' => "Le chemin du projet est invalide ou inaccessible : {$project->path}"],
            ));

            return;
        }

        $config = config('agent.drivers.nvidia');
        $client = new NvidiaClient($config['base_url'], (int) $config['timeout']);
        $toolDefs = $tools->definitions(canWrite: false);
        $maxTurns = (int) ($config['max_turns'] ?? 25);

        $session->update([
            'status' => $this->mode === 'plan' ? 'planning' : 'reading',
            'started_at' => $session->started_at ?? now(),
        ]);
        $dispatcher->dispatch(AgentEvent::make(
            AgentEventType::Status,
            $this->sessionId,
            ['status' => $session->status],
        ));

        $baseMessages = $this->buildInitialMessages($session);
        $responses = [];

        foreach (['kimi', 'deepseek'] as $modelKey) {
            $modelConfig = $config['models'][$modelKey] ?? null;

            if (! $modelConfig || ! $modelConfig['api_key']) {
                continue;
            }

            $dispatcher->dispatch(AgentEvent::make(
                AgentEventType::Log,
                $this->sessionId,
                ['message' => "{$this->label($modelKey)} réfléchit à la demande…", 'level' => 'info'],
            ));

            try {
                $text = $this->runReadOnlyLoop(
                    $dispatcher, $tools, $client, $session,
                    $modelConfig['model'], $modelConfig['api_key'],
                    $baseMessages, $toolDefs, $maxTurns,
                );
            } catch (Throwable $e) {
                $dispatcher->dispatch(AgentEvent::make(
                    AgentEventType::Log,
                    $this->sessionId,
                    ['message' => "{$this->label($modelKey)} a échoué : {$e->getMessage()}", 'level' => 'error'],
                ));

                continue;
            }

            if (trim($text) !== '') {
                $responses[$modelKey] = $text;
                $dispatcher->dispatch(AgentEvent::make(
                    AgentEventType::Log,
                    $this->sessionId,
                    ['message' => "Réponse de {$this->label($modelKey)} reçue.", 'level' => 'info'],
                ));
            }
        }

        if ($responses === []) {
            $session->update(['status' => 'error', 'ended_at' => now()]);
            $dispatcher->dispatch(AgentEvent::make(
                AgentEventType::Error,
                $this->sessionId,
                ['message' => 'Aucun modèle NVIDIA n\'a pu répondre (clés API manquantes ou appels en échec).'],
            ));

            return;
        }

        $final = count($responses) === 1
            ? array_values($responses)[0]
            : $this->synthesize($client, $config, $responses) ?? $this->concatenate($responses);

        $eventType = $this->mode === 'plan' ? AgentEventType::Plan : AgentEventType::Message;
        $payloadKey = $this->mode === 'plan' ? 'content' : 'text';

        $dispatcher->dispatch(AgentEvent::make($eventType, $this->sessionId, [$payloadKey => $final]));

        $session->update(['status' => 'done', 'ended_at' => now()]);
        $dispatcher->dispatch(AgentEvent::make(
            AgentEventType::Done,
            $this->sessionId,
            ['status' => 'done', 'message' => 'Tâche terminée avec succès.'],
        ));
    }

    public function failed(?Throwable $exception): void
    {
        $session = Session::find($this->sessionId);

        if (! $session || in_array($session->status, ['done', 'error', 'idle'])) {
            return;
        }

        $session->update(['status' => 'error', 'ended_at' => now()]);

        app(AgentEventDispatcher::class)->dispatch(AgentEvent::make(
            AgentEventType::Error,
            $this->sessionId,
            ['message' => 'Le job de collaboration a échoué : '.($exception?->getMessage() ?? 'cause inconnue.')],
        ));
    }

    // -------------------------------------------------------------------------
    // Boucle d'outils en lecture seule, par modèle
    // -------------------------------------------------------------------------

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $toolDefs
     */
    private function runReadOnlyLoop(
        AgentEventDispatcher $dispatcher,
        ToolExecutor $tools,
        NvidiaClient $client,
        Session $session,
        string $model,
        string $apiKey,
        array $messages,
        array $toolDefs,
        int $maxTurns,
    ): string {
        for ($turn = 0; $turn < $maxTurns; $turn++) {
            $result = $client->chat($model, $apiKey, $messages, $toolDefs);
            $this->recordUsage($dispatcher, $session, $result['usage'] ?? []);

            $assistantMessage = $result['message'];
            $toolCalls = $assistantMessage['tool_calls'] ?? [];

            if ($toolCalls === []) {
                return trim((string) ($assistantMessage['content'] ?? ''));
            }

            $messages[] = [
                'role' => 'assistant',
                'content' => $assistantMessage['content'] ?? null,
                'tool_calls' => $toolCalls,
            ];

            foreach ($toolCalls as $toolCall) {
                $name = $toolCall['function']['name'] ?? '';
                $args = json_decode((string) ($toolCall['function']['arguments'] ?? '{}'), true) ?: [];
                $output = $tools->run($session, $name, $args, canWrite: false, enforceWhitelist: false);

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall['id'] ?? '',
                    'content' => $output,
                ];
            }
        }

        throw new RuntimeException("Nombre maximum de tours atteint ({$maxTurns}) pour [{$model}].");
    }

    /**
     * @param  array<string, string>  $responses  modelKey => texte final
     */
    private function synthesize(NvidiaClient $client, array $config, array $responses): ?string
    {
        $synthesizerKey = null;

        foreach (['kimi', 'deepseek'] as $candidate) {
            if (! empty($config['models'][$candidate]['api_key'])) {
                $synthesizerKey = $candidate;
                break;
            }
        }

        if ($synthesizerKey === null) {
            return null;
        }

        $modelConfig = $config['models'][$synthesizerKey];
        $parts = [];

        foreach ($responses as $modelKey => $text) {
            $parts[] = "{$this->label($modelKey)} :\n{$text}";
        }

        $prompt = "Voici les réponses de deux agents IA différents à la même demande utilisateur :\n\n"
            .implode("\n\n---\n\n", $parts)
            ."\n\nFusionne-les en une seule réponse finale, cohérente et complète : garde le meilleur des deux, "
            .'résous les éventuelles contradictions, évite les répétitions. Réponds directement avec la réponse finale, en français.';

        try {
            $result = $client->chat($modelConfig['model'], $modelConfig['api_key'], [
                ['role' => 'system', 'content' => 'Tu fusionnes les réponses de plusieurs agents IA en une seule synthèse cohérente et utile.'],
                ['role' => 'user', 'content' => $prompt],
            ], []);

            $text = trim((string) ($result['message']['content'] ?? ''));

            return $text !== '' ? $text : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, string>  $responses
     */
    private function concatenate(array $responses): string
    {
        $parts = [];

        foreach ($responses as $modelKey => $text) {
            $parts[] = "**{$this->label($modelKey)}**\n\n{$text}";
        }

        return implode("\n\n---\n\n", $parts);
    }

    private function label(string $modelKey): string
    {
        return match ($modelKey) {
            'kimi' => 'Kimi K2.6',
            'deepseek' => 'DeepSeek',
            default => $modelKey,
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildInitialMessages(Session $session): array
    {
        $messages = [['role' => 'system', 'content' => $this->buildSystemPrompt($session)]];

        foreach ($session->messages()->orderBy('id')->get() as $message) {
            if ($message->role === 'user' && $message->type === 'text') {
                $messages[] = ['role' => 'user', 'content' => $message->content];

                continue;
            }

            if ($message->role === 'agent' && in_array($message->type, ['text', 'plan'], true)) {
                $decoded = json_decode($message->content, true) ?: [];
                $text = trim((string) ($decoded['text'] ?? $decoded['content'] ?? ''));

                if ($text !== '') {
                    $messages[] = ['role' => 'assistant', 'content' => $text];
                }
            }
        }

        return $messages;
    }

    private function buildSystemPrompt(Session $session): string
    {
        $project = $session->project;

        $lines = ["Tu es un agent de code qui analyse le projet **{$project->name}**."];

        if ($project->stack) {
            $lines[] = "Stack : {$project->stack}";
        }

        if ($project->description) {
            $lines[] = "Description : {$project->description}";
        }

        $lines[] = 'Utilise exclusivement les outils fournis (read_file, list_dir, glob_search, grep_search) pour explorer '
            ."le code — ne suppose jamais le contenu d'un fichier sans l'avoir lu. Tu es en lecture seule : ne propose "
            .'aucune commande à exécuter toi-même.';

        $lines[] = $this->mode === 'plan'
            ? "Produis un plan d'action structuré en markdown répondant à la demande."
            : 'Réponds directement et de façon concise à la demande.';

        $lines[] = "Réponds en français. Une fois l'analyse terminée, produis un dernier message sans appel d'outil.";

        return implode("\n", $lines);
    }

    private function recordUsage(AgentEventDispatcher $dispatcher, Session $session, array $usage): void
    {
        $inputTokens = (int) ($usage['prompt_tokens'] ?? 0);
        $outputTokens = (int) ($usage['completion_tokens'] ?? 0);

        if ($inputTokens === 0 && $outputTokens === 0) {
            return;
        }

        $session->update([
            'input_tokens' => ($session->input_tokens ?? 0) + $inputTokens,
            'output_tokens' => ($session->output_tokens ?? 0) + $outputTokens,
        ]);

        $dispatcher->dispatch(AgentEvent::make(
            AgentEventType::Cost,
            $this->sessionId,
            ['input_tokens' => $inputTokens, 'output_tokens' => $outputTokens, 'cost_usd' => 0],
        ));
    }
}
