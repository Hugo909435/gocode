<?php

namespace App\Jobs;

use App\Agent\AgentEvent;
use App\Agent\AgentEventDispatcher;
use App\Agent\AgentEventType;
use App\Models\Session;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

/**
 * Exécute Claude Code CLI en mode non-interactif et traduit son flux
 * stream-json en AgentEvents persistés dans la table messages.
 *
 * Format stream-json de Claude Code (une ligne JSON par événement) :
 *   {"type":"system","subtype":"init", ...}
 *   {"type":"assistant","message":{"content":[{"type":"text","text":"..."},...], ...}}
 *   {"type":"user","message":{"content":[{"type":"tool_result","tool_use_id":"...","content":"..."}]}}
 *   {"type":"result","subtype":"success|error","cost_usd":0.05,"usage":{...},...}
 *
 * Sur Windows : si 'claude' n'est pas trouvé, configurer CLAUDE_BINARY=claude.cmd
 * (les binaires npm globaux sur Windows sont des fichiers .cmd).
 */
class ClaudeCodeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(
        public readonly string $sessionId,
        public readonly string $instruction,
        public readonly string $mode,
        public readonly array $skills = [],
    ) {}

    public function handle(AgentEventDispatcher $dispatcher): void
    {
        $session = Session::with('project')->find($this->sessionId);

        if (! $session || ! $session->project) {
            return;
        }

        $projectPath = $session->project->path;

        if (! $projectPath || ! is_dir($projectPath)) {
            $session->update(['status' => 'error', 'ended_at' => now()]);
            $dispatcher->dispatch(AgentEvent::make(
                AgentEventType::Error,
                $this->sessionId,
                ['message' => "Le chemin du projet est invalide ou inaccessible : {$projectPath}"],
            ));

            return;
        }

        $process = new Process(
            command: $this->buildCommand($session->project),
            cwd: $projectPath,
            env: $this->buildEnv(),
            timeout: config('agent.drivers.claude-code.timeout', 300),
        );

        $session->update(['status' => 'reading', 'started_at' => now()]);
        $dispatcher->dispatch(AgentEvent::make(
            AgentEventType::Status,
            $this->sessionId,
            ['status' => 'reading'],
        ));

        $buffer          = '';
        $pendingToolUses = [];
        $currentStatus   = 'reading';

        $process->start(function (string $type, string $data) use (
            &$buffer,
            &$pendingToolUses,
            &$currentStatus,
            $dispatcher,
            $session,
        ) {
            if ($type !== Process::OUT) {
                return;
            }

            $buffer .= $data;

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line   = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);

                if ($line !== '') {
                    $this->processLine($line, $dispatcher, $session, $pendingToolUses, $currentStatus);
                }
            }
        });

        while ($process->isRunning()) {
            if (Cache::pull("claude-code.stop.{$this->sessionId}")) {
                $process->stop(3);

                return;
            }

            usleep(100_000);
        }

        // Traite les dernières données dans le buffer
        if (trim($buffer) !== '') {
            $this->processLine(trim($buffer), $dispatcher, $session, $pendingToolUses, $currentStatus);
        }

        // Si le processus se termine sans événement done/error, émettre une erreur
        $freshSession = Session::find($this->sessionId);
        if ($freshSession && ! in_array($freshSession->status, ['done', 'error', 'idle'])) {
            $exitCode = $process->getExitCode();
            $stderr   = trim($process->getErrorOutput());
            $message  = $exitCode !== 0
                ? "Claude Code s'est terminé avec le code {$exitCode}."
                : "Claude Code s'est terminé de manière inattendue.";

            if ($stderr) {
                $message .= "\n{$stderr}";
            }

            $session->update(['status' => 'error', 'ended_at' => now()]);
            $dispatcher->dispatch(AgentEvent::make(
                AgentEventType::Error,
                $this->sessionId,
                ['message' => $message],
            ));
        }
    }

    // -------------------------------------------------------------------------
    // Parsing du flux stream-json
    // -------------------------------------------------------------------------

    private function processLine(
        string $line,
        AgentEventDispatcher $dispatcher,
        Session $session,
        array &$pendingToolUses,
        string &$currentStatus,
    ): void {
        $data = json_decode($line, true);

        if (! is_array($data)) {
            return;
        }

        match ($data['type'] ?? '') {
            'assistant' => $this->handleAssistantMessage(
                $data, $dispatcher, $session, $pendingToolUses, $currentStatus
            ),
            'user' => $this->handleUserMessage(
                $data, $dispatcher, $session, $pendingToolUses
            ),
            'result' => $this->handleResult($data, $dispatcher, $session),
            default   => null,
        };
    }

    private function handleAssistantMessage(
        array $data,
        AgentEventDispatcher $dispatcher,
        Session $session,
        array &$pendingToolUses,
        string &$currentStatus,
    ): void {
        $content = $data['message']['content'] ?? [];

        foreach ($content as $item) {
            $itemType = $item['type'] ?? '';

            if ($itemType === 'text') {
                $text = trim($item['text'] ?? '');

                if ($text !== '') {
                    $dispatcher->dispatch(AgentEvent::make(
                        AgentEventType::Message,
                        $this->sessionId,
                        ['text' => $text],
                    ));
                }

                continue;
            }

            if ($itemType === 'tool_use') {
                $toolId   = $item['id'] ?? '';
                $toolName = $item['name'] ?? '';
                $input    = $item['input'] ?? [];

                $pendingToolUses[$toolId] = ['name' => $toolName, 'input' => $input];

                $this->emitToolCallEvent($toolId, $toolName, $input, $dispatcher, $session, $currentStatus);
            }
        }
    }

    private function emitToolCallEvent(
        string $toolId,
        string $toolName,
        array $input,
        AgentEventDispatcher $dispatcher,
        Session $session,
        string &$currentStatus,
    ): void {
        $writeTools = ['Edit', 'Write', 'MultiEdit', 'NotebookEdit'];

        if ($toolName === 'Bash') {
            $newStatus = 'running';

            if ($currentStatus !== $newStatus) {
                $currentStatus = $newStatus;
                $session->update(['status' => $newStatus]);
                $dispatcher->dispatch(AgentEvent::make(
                    AgentEventType::Status,
                    $this->sessionId,
                    ['status' => $newStatus],
                ));
            }

            $dispatcher->dispatch(AgentEvent::make(
                AgentEventType::ToolCall,
                $this->sessionId,
                [
                    'tool'    => 'Bash',
                    'command' => $input['command'] ?? '',
                    'id'      => $toolId,
                ],
            ));

            return;
        }

        if (in_array($toolName, $writeTools)) {
            $newStatus = 'building';

            if ($currentStatus !== $newStatus) {
                $currentStatus = $newStatus;
                $session->update(['status' => $newStatus]);
                $dispatcher->dispatch(AgentEvent::make(
                    AgentEventType::Status,
                    $this->sessionId,
                    ['status' => $newStatus],
                ));
            }

            $dispatcher->dispatch(AgentEvent::make(
                AgentEventType::ToolCall,
                $this->sessionId,
                [
                    'tool' => $toolName,
                    'file' => $input['file_path'] ?? '',
                    'id'   => $toolId,
                ],
            ));

            return;
        }

        // Outils de lecture : Read, Grep, Glob, LS, TodoRead, WebFetch…
        $dispatcher->dispatch(AgentEvent::make(
            AgentEventType::ToolCall,
            $this->sessionId,
            [
                'tool'   => $toolName,
                'params' => $input,
                'id'     => $toolId,
            ],
        ));
    }

    private function handleUserMessage(
        array $data,
        AgentEventDispatcher $dispatcher,
        Session $session,
        array &$pendingToolUses,
    ): void {
        $content = $data['message']['content'] ?? [];

        foreach ($content as $item) {
            if (($item['type'] ?? '') !== 'tool_result') {
                continue;
            }

            $toolUseId = $item['tool_use_id'] ?? '';
            $isError   = $item['is_error'] ?? false;
            $rawResult = $item['content'] ?? '';

            if (! isset($pendingToolUses[$toolUseId])) {
                continue;
            }

            $pending  = $pendingToolUses[$toolUseId];
            $toolName = $pending['name'];
            $input    = $pending['input'];

            unset($pendingToolUses[$toolUseId]);

            $resultText = $this->extractResultText($rawResult);

            if ($toolName === 'Bash') {
                $dispatcher->dispatch(AgentEvent::make(
                    AgentEventType::Terminal,
                    $this->sessionId,
                    [
                        'command' => $input['command'] ?? '',
                        'output'  => $resultText,
                        'error'   => $isError,
                    ],
                ));

                continue;
            }

            if (in_array($toolName, ['Edit', 'Write', 'MultiEdit', 'NotebookEdit'])) {
                $dispatcher->dispatch(AgentEvent::make(
                    AgentEventType::FileChange,
                    $this->sessionId,
                    $this->buildFileChangePayload($toolName, $input, $isError),
                ));
            }
        }
    }

    private function handleResult(
        array $data,
        AgentEventDispatcher $dispatcher,
        Session $session,
    ): void {
        $subtype  = $data['subtype'] ?? 'success';
        $usage    = $data['usage'] ?? [];
        $costUsd  = (float) ($data['cost_usd'] ?? 0);

        // Mise à jour des compteurs de tokens et du coût cumulé
        $session->update([
            'input_tokens'  => ($session->input_tokens ?? 0) + ($usage['input_tokens'] ?? 0),
            'output_tokens' => ($session->output_tokens ?? 0) + ($usage['output_tokens'] ?? 0),
            'cost_usd'      => ($session->cost_usd ?? 0) + $costUsd,
        ]);

        if ($costUsd > 0) {
            $dispatcher->dispatch(AgentEvent::make(
                AgentEventType::Cost,
                $this->sessionId,
                [
                    'input_tokens'  => $usage['input_tokens'] ?? 0,
                    'output_tokens' => $usage['output_tokens'] ?? 0,
                    'cost_usd'      => $costUsd,
                ],
            ));
        }

        if ($subtype === 'success') {
            $session->update(['status' => 'done', 'ended_at' => now()]);
            $dispatcher->dispatch(AgentEvent::make(
                AgentEventType::Done,
                $this->sessionId,
                ['status' => 'done', 'message' => 'Tâche terminée avec succès.'],
            ));
        } else {
            $errorMsg = $data['error'] ?? ($data['result'] ?? 'Erreur inconnue.');
            $session->update(['status' => 'error', 'ended_at' => now()]);
            $dispatcher->dispatch(AgentEvent::make(
                AgentEventType::Error,
                $this->sessionId,
                ['message' => $errorMsg],
            ));
        }
    }

    // -------------------------------------------------------------------------
    // Construction du diff
    // -------------------------------------------------------------------------

    private function buildFileChangePayload(string $toolName, array $input, bool $isError): array
    {
        return match ($toolName) {
            'Edit' => [
                'file'      => $input['file_path'] ?? '',
                'additions' => $this->countLines($input['new_string'] ?? ''),
                'deletions' => $this->countLines($input['old_string'] ?? ''),
                'diff'      => $this->buildEditDiff($input),
                'error'     => $isError,
            ],
            'Write' => [
                'file'      => $input['file_path'] ?? '',
                'additions' => $this->countLines($input['content'] ?? ''),
                'deletions' => 0,
                'diff'      => $this->buildWriteDiff($input),
                'error'     => $isError,
            ],
            'MultiEdit' => [
                'file'      => $input['file_path'] ?? '',
                'additions' => 0,
                'deletions' => 0,
                'diff'      => $this->buildMultiEditDiff($input),
                'error'     => $isError,
            ],
            default => ['file' => $input['file_path'] ?? '', 'error' => $isError],
        };
    }

    private function buildEditDiff(array $input): string
    {
        $file = $input['file_path'] ?? 'unknown';
        $old  = $input['old_string'] ?? '';
        $new  = $input['new_string'] ?? '';

        $diff  = "--- a/{$file}\n";
        $diff .= "+++ b/{$file}\n";
        $diff .= "@@ ... @@\n";

        foreach (explode("\n", $old) as $line) {
            $diff .= '-'.$line."\n";
        }

        foreach (explode("\n", $new) as $line) {
            $diff .= '+'.$line."\n";
        }

        return rtrim($diff);
    }

    private function buildWriteDiff(array $input): string
    {
        $file    = $input['file_path'] ?? 'unknown';
        $content = $input['content'] ?? '';
        $lines   = $this->countLines($content);

        $diff  = "--- /dev/null\n";
        $diff .= "+++ b/{$file}\n";
        $diff .= "@@ -0,0 +1,{$lines} @@\n";

        foreach (explode("\n", $content) as $line) {
            $diff .= '+'.$line."\n";
        }

        return rtrim($diff);
    }

    private function buildMultiEditDiff(array $input): string
    {
        $file  = $input['file_path'] ?? 'unknown';
        $edits = $input['edits'] ?? [];
        $diff  = "--- a/{$file}\n+++ b/{$file}\n";

        foreach ($edits as $edit) {
            $diff .= "@@ ... @@\n";

            foreach (explode("\n", $edit['old_string'] ?? '') as $line) {
                $diff .= '-'.$line."\n";
            }

            foreach (explode("\n", $edit['new_string'] ?? '') as $line) {
                $diff .= '+'.$line."\n";
            }
        }

        return rtrim($diff);
    }

    // -------------------------------------------------------------------------
    // Construction de la commande
    // -------------------------------------------------------------------------

    private function buildCommand(\App\Models\Project $project): array
    {
        $config = config('agent.drivers.claude-code', []);
        $binary = $config['binary'] ?? 'claude';
        $model  = $config['model'] ?? null;

        $cmd = [$binary, '--print', '--verbose', '--output-format', 'stream-json'];

        if ($model) {
            $cmd[] = '--model';
            $cmd[] = $model;
        }

        switch ($this->mode) {
            case 'read':
                $cmd[] = '--allowedTools';
                $cmd[] = 'Read,Glob,Grep,LS,TodoRead,WebFetch';
                break;

            case 'plan':
                $cmd[] = '--allowedTools';
                $cmd[] = 'Read,Glob,Grep,LS,TodoRead';
                $cmd[] = '--appendSystemPrompt';
                $cmd[] = 'Analyze the codebase and produce a detailed, structured action plan in markdown. Do NOT modify any files, do NOT execute any commands. Only read and plan.';
                break;

            case 'execute':
                $cmd[] = '--dangerously-skip-permissions';
                break;
        }

        // Contexte projet injecté systématiquement pour que Claude Code sache
        // sur quel projet il travaille, indépendamment du CLAUDE.md global.
        $cmd[] = '--appendSystemPrompt';
        $cmd[] = $this->buildProjectContextPrompt($project);

        $skillPrompt = $this->buildSkillPrompt();
        if ($skillPrompt !== '') {
            $cmd[] = '--appendSystemPrompt';
            $cmd[] = $skillPrompt;
        }

        $cmd[] = $this->instruction;

        return $cmd;
    }

    private function buildProjectContextPrompt(\App\Models\Project $project): string
    {
        $lines = ["You are working on project: **{$project->name}**."];
        $lines[] = "Project path: {$project->path}";

        if ($project->stack) {
            $lines[] = "Stack: {$project->stack}";
        }

        if ($project->description) {
            $lines[] = "Description: {$project->description}";
        }

        if ($project->git_remote) {
            $lines[] = "Git remote: {$project->git_remote}";
        }

        $lines[] = 'Focus exclusively on this project\'s codebase. Do not reference or describe any other application.';

        return implode("\n", $lines);
    }

    private function buildSkillPrompt(): string
    {
        if (empty($this->skills)) {
            return '';
        }

        $parts = [];

        foreach ($this->skills as $skill) {
            $content = $this->readSkillContent($skill);
            if ($content !== '') {
                $parts[] = $content;
            }
        }

        return implode("\n\n---\n\n", $parts);
    }

    private function readSkillContent(string $skill): string
    {
        $searchPaths = [
            // Skills projet courant
            base_path(".claude/skills/{$skill}/SKILL.md"),
            // Skills globaux utilisateur (~/.claude/skills/)
            rtrim($_SERVER['HOME'] ?? $_SERVER['USERPROFILE'] ?? '', '/\\') . DIRECTORY_SEPARATOR . ".claude/skills/{$skill}/SKILL.md",
        ];

        foreach ($searchPaths as $path) {
            if (! file_exists($path)) {
                continue;
            }

            $raw = file_get_contents($path);

            // Retire le frontmatter YAML (--- ... ---)
            if (str_starts_with($raw, '---')) {
                $end = strpos($raw, '---', 3);
                if ($end !== false) {
                    $raw = ltrim(substr($raw, $end + 3));
                }
            }

            return trim($raw);
        }

        return '';
    }

    private function buildEnv(): array
    {
        $apiKey = config('agent.drivers.claude-code.api_key');

        if ($apiKey) {
            return ['ANTHROPIC_API_KEY' => $apiKey];
        }

        return [];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function extractResultText(mixed $raw): string
    {
        if (is_string($raw)) {
            return $raw;
        }

        if (is_array($raw)) {
            return collect($raw)
                ->filter(fn ($item) => ($item['type'] ?? '') === 'text')
                ->pluck('text')
                ->implode("\n");
        }

        return '';
    }

    private function countLines(string $text): int
    {
        return max(1, substr_count($text, "\n") + 1);
    }
}
