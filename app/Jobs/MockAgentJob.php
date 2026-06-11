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
use Illuminate\Support\Str;

/**
 * Job asynchrone qui simule l'activité de l'agent mock.
 *
 * Pourquoi un job et non un process détaché :
 * - Portable Windows/Linux, pas de proc_open fragile
 * - Laravel gère les retries, le logging d'échecs, la sérialisation
 * - Avec QUEUE_CONNECTION=database (défaut) + `php artisan queue:work`, chaque
 *   usleep() crée une pause réelle entre les messages SSE → simulation temps réel.
 * - La commande artisan utilise dispatchSync() pour contourner le worker.
 */
class MockAgentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Timeout généreux : le job peut dormir ~20s en mode execute avec delayMs=1000. */
    public int $timeout = 300;

    public function __construct(
        public readonly string $sessionId,
        public readonly string $instruction,
        public readonly string $mode,
        public readonly string $scenario,
        public readonly bool $resumed,
        public readonly string $actionId = '',
        public readonly int $delayMs = 1000,
    ) {}

    public function handle(AgentEventDispatcher $dispatcher): void
    {
        if ($this->resumed) {
            $this->handleResume($dispatcher);

            return;
        }

        if ($this->scenario === 'error') {
            $this->handleErrorScenario($dispatcher);

            return;
        }

        match ($this->mode) {
            'read' => $this->handleReadMode($dispatcher),
            'plan' => $this->handlePlanMode($dispatcher),
            'execute' => $this->handleExecuteMode($dispatcher),
            default => $this->handleReadMode($dispatcher),
        };
    }

    // -------------------------------------------------------------------------
    // Modes
    // -------------------------------------------------------------------------

    private function handleReadMode(AgentEventDispatcher $d): void
    {
        $this->setStatus('reading');
        $this->emit($d, AgentEventType::Status, ['status' => 'reading']);
        $this->emit($d, AgentEventType::Message, ['text' => 'Lecture de la mémoire projet (AGENTS.md)…']);

        $this->pause(2.0);

        $this->emit($d, AgentEventType::Message, [
            'text' => $this->generateReadResponse(),
        ]);

        $this->finish($d);
    }

    private function handlePlanMode(AgentEventDispatcher $d): void
    {
        $this->setStatus('reading');
        $this->emit($d, AgentEventType::Status, ['status' => 'reading']);
        $this->emit($d, AgentEventType::Message, ['text' => 'Lecture de la mémoire projet (AGENTS.md)…']);

        $this->pause(1.5);

        $this->setStatus('planning');
        $this->emit($d, AgentEventType::Status, ['status' => 'planning']);
        $this->emit($d, AgentEventType::Plan, [
            'content' => $this->generatePlan(),
        ]);

        $this->pause(0.5);

        $actionId = (string) Str::uuid();
        $this->storePending($actionId, 'plan');
        $this->setStatus('awaiting_confirmation');
        $this->emit($d, AgentEventType::ConfirmationRequest, [
            'action_id' => $actionId,
            'message' => 'Valider ce plan ?',
            'type' => 'plan',
        ]);
        // S'arrête ici — reprend via confirmAction() → MockAgentJob(resumed=true)
    }

    private function handleExecuteMode(AgentEventDispatcher $d): void
    {
        $this->setStatus('reading');
        $this->emit($d, AgentEventType::Status, ['status' => 'reading']);
        $this->emit($d, AgentEventType::Message, ['text' => 'Lecture de la mémoire projet (AGENTS.md)…']);

        $this->pause(1.5);

        $this->setStatus('planning');
        $this->emit($d, AgentEventType::Status, ['status' => 'planning']);
        $this->emit($d, AgentEventType::Plan, [
            'content' => $this->generatePlan(),
        ]);

        $this->pause(1.0);

        $this->setStatus('building');
        $this->emit($d, AgentEventType::Status, ['status' => 'building']);

        $this->emit($d, AgentEventType::Log, [
            'message' => 'Analyse des fichiers concernés par la demande…',
            'level' => 'info',
        ]);
        $this->emit($d, AgentEventType::Log, [
            'message' => 'Résolution des dépendances et imports manquants…',
            'level' => 'info',
        ]);

        $this->emit($d, AgentEventType::Terminal, [
            'command' => 'npm run build',
            'output' => $this->fakeNpmBuildOutput(),
        ]);

        $this->pause(1.0);

        $this->emit($d, AgentEventType::ToolCall, [
            'tool' => 'edit_file',
            'params' => ['path' => 'app/Http/Controllers/Api/SessionController.php'],
        ]);

        $this->emit($d, AgentEventType::FileChange, [
            'file' => 'app/Http/Controllers/Api/SessionController.php',
            'additions' => 7,
            'deletions' => 2,
            'diff' => $this->fakePhpDiff(),
        ]);

        $this->pause(0.5);

        $this->emit($d, AgentEventType::ToolCall, [
            'tool' => 'edit_file',
            'params' => ['path' => 'resources/js/components/SessionChat.vue'],
        ]);

        $this->emit($d, AgentEventType::FileChange, [
            'file' => 'resources/js/components/SessionChat.vue',
            'additions' => 10,
            'deletions' => 3,
            'diff' => $this->fakeVueDiff(),
        ]);

        $this->pause(0.5);

        $this->emit($d, AgentEventType::Cost, [
            'input_tokens' => 4821,
            'output_tokens' => 1247,
            'cost_usd' => 0.019230,
            'model' => 'claude-sonnet-4-6',
        ]);

        $actionId = (string) Str::uuid();
        $this->storePending($actionId, 'commit');
        $this->setStatus('awaiting_confirmation');
        $this->emit($d, AgentEventType::ConfirmationRequest, [
            'action_id' => $actionId,
            'message' => 'Committer ces changements ?',
            'type' => 'commit',
            'files' => [
                'app/Http/Controllers/Api/SessionController.php',
                'resources/js/components/SessionChat.vue',
            ],
        ]);
        // S'arrête ici — reprend via confirmAction() → MockAgentJob(resumed=true)
    }

    private function handleResume(AgentEventDispatcher $d): void
    {
        $this->setStatus('running');
        $this->emit($d, AgentEventType::Status, ['status' => 'running']);

        $this->emit($d, AgentEventType::Terminal, [
            'command' => 'git add -A && git commit -m "feat: implement requested changes"',
            'output' => "[mock] [main 3a8f12c] feat: implement requested changes\n 2 files changed, 17 insertions(+), 5 deletions(-)",
        ]);

        $this->pause(0.5);

        $this->finish($d);
    }

    private function handleErrorScenario(AgentEventDispatcher $d): void
    {
        $this->setStatus('reading');
        $this->emit($d, AgentEventType::Status, ['status' => 'reading']);
        $this->emit($d, AgentEventType::Message, ['text' => 'Lecture de la mémoire projet (AGENTS.md)…']);

        $this->pause(1.0);

        $this->setStatus('error');
        Session::where('id', $this->sessionId)->update(['ended_at' => now()]);
        $this->emit($d, AgentEventType::Error, [
            'message' => 'Erreur irrécupérable : le modèle n\'a pas pu traiter l\'instruction. Vérifiez les logs pour plus de détails.',
            'code' => 'MODEL_ERROR',
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function emit(AgentEventDispatcher $d, AgentEventType $type, array $payload): void
    {
        $d->dispatch(AgentEvent::make($type, $this->sessionId, $payload));
        $this->pause();
    }

    /** Pause entre événements. $multiplier permet d'allonger certaines pauses. */
    private function pause(float $multiplier = 1.0): void
    {
        if ($this->delayMs > 0) {
            usleep((int) ($this->delayMs * $multiplier * 1000));
        }
    }

    private function setStatus(string $status): void
    {
        Session::where('id', $this->sessionId)->update(['status' => $status]);
    }

    private function finish(AgentEventDispatcher $d): void
    {
        $this->setStatus('done');
        Session::where('id', $this->sessionId)->update(['ended_at' => now()]);
        $this->emit($d, AgentEventType::Done, ['status' => 'done', 'message' => 'Session terminée avec succès.']);
    }

    private function storePending(string $actionId, string $type): void
    {
        Cache::put("mock.pending.{$this->sessionId}", [
            'action_id' => $actionId,
            'type' => $type,
        ], now()->addHour());
    }

    // -------------------------------------------------------------------------
    // Contenu généré
    // -------------------------------------------------------------------------

    private function generatePlan(): string
    {
        $short = Str::limit($this->instruction, 80);

        return <<<MARKDOWN
        ## Plan d'action

        **Objectif :** {$short}

        ### Étapes

        1. **Analyser le contexte** — Lire les fichiers existants pour comprendre l'architecture en place et les conventions du projet.
        2. **Identifier les fichiers cibles** — Repérer les contrôleurs, services, migrations et composants concernés par la demande.
        3. **Implémenter les modifications** — Appliquer les changements avec un impact minimal sur le code existant, en respectant les patterns Laravel et Vue 3.
        4. **Valider la solution** — Exécuter `php artisan test` et vérifier que rien n'est cassé avant de proposer le commit.
        MARKDOWN;
    }

    private function generateReadResponse(): string
    {
        $instruction = Str::limit($this->instruction, 120);

        return "J'ai analysé le projet. Concernant votre demande : « {$instruction} »\n\n"
            ."Le code actuel suit les conventions Laravel avec Sanctum pour l'authentification, "
            .'SSE pour le temps réel (polling DB avec curseur), et une couche `AgentDriverContract` '
            .'pour isoler le moteur IA. En mode `read` je ne modifie aucun fichier — '
            .'basculez en mode `plan` ou `execute` pour aller plus loin.';
    }

    private function fakeNpmBuildOutput(): string
    {
        return "> gocode@1.0.0 build\n"
            ."> vite build\n\n"
            ."vite v5.2.8 building for production...\n"
            ."✓ 142 modules transformed.\n"
            ."dist/index.html                  0.43 kB │ gzip:  0.28 kB\n"
            ."dist/assets/index-DiwrgTda.css  12.40 kB │ gzip:  3.21 kB\n"
            ."dist/assets/index-BxAZzMoa.js  184.92 kB │ gzip: 68.13 kB\n"
            .'✓ built in 3.24s';
    }

    private function fakePhpDiff(): string
    {
        return <<<'DIFF'
diff --git a/app/Http/Controllers/Api/SessionController.php b/app/Http/Controllers/Api/SessionController.php
index 3a8f12c..7d4e291 100644
--- a/app/Http/Controllers/Api/SessionController.php
+++ b/app/Http/Controllers/Api/SessionController.php
@@ -3,6 +3,7 @@
 namespace App\Http\Controllers\Api;

+use App\Contracts\AgentDriverContract;
 use App\Http\Controllers\Controller;
 use App\Http\Requests\StoreSessionRequest;
 use App\Http\Resources\SessionResource;
@@ -23,9 +24,13 @@ class SessionController extends Controller
     public function store(StoreSessionRequest $request): JsonResponse
     {
         $session = Session::create($request->validated());
-
-        return response()->json(['data' => new SessionResource($session)], 200);
+
+        app(AgentDriverContract::class)->startSession($session);
+
+        return response()->json([
+            'data' => new SessionResource($session),
+        ], 201);
     }
 }
DIFF;
    }

    private function fakeVueDiff(): string
    {
        return <<<'DIFF'
diff --git a/resources/js/components/SessionChat.vue b/resources/js/components/SessionChat.vue
index 1b2c3d4..8e9f012 100644
--- a/resources/js/components/SessionChat.vue
+++ b/resources/js/components/SessionChat.vue
@@ -1,12 +1,22 @@
 <template>
-  <div class="chat-container">
+  <div class="chat-container flex flex-col h-full">
+    <div class="messages-list flex-1 overflow-y-auto p-4 space-y-3">
+      <MessageBubble
+        v-for="message in messages"
+        :key="message.id"
+        :message="message"
+      />
+    </div>
+    <div class="input-bar border-t p-3">
       <input
         v-model="input"
         type="text"
         placeholder="Entrez votre instruction…"
+        class="w-full rounded-lg border px-4 py-2 focus:ring-2 focus:ring-indigo-500"
         @keydown.enter="sendInstruction"
       />
-  </div>
+    </div>
+  </div>
 </template>
DIFF;
    }
}
