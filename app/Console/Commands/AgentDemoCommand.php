<?php

namespace App\Console\Commands;

use App\Jobs\MockAgentJob;
use App\Models\Session;
use Illuminate\Console\Command;

class AgentDemoCommand extends Command
{
    protected $signature = 'agent:demo
        {session : UUID de la session agent_sessions}
        {--scenario=success : Scénario à jouer (success|error)}
        {--mode=execute : Mode de session (read|plan|execute)}
        {--no-delay : Désactiver les délais entre événements (utile pour les tests)}';

    protected $description = 'Déclenche un scénario MockAgentDriver sur une session existante (test CLI, sans queue worker).';

    public function handle(): int
    {
        $sessionId = $this->argument('session');
        $scenario = $this->option('scenario');
        $mode = $this->option('mode');
        $delayMs = $this->option('no-delay') ? 0 : 600;

        if (! in_array($scenario, ['success', 'error'], true)) {
            $this->error("Scénario invalide : « {$scenario} ». Valeurs acceptées : success, error.");

            return Command::FAILURE;
        }

        if (! in_array($mode, ['read', 'plan', 'execute'], true)) {
            $this->error("Mode invalide : « {$mode} ». Valeurs acceptées : read, plan, execute.");

            return Command::FAILURE;
        }

        $session = Session::find($sessionId);

        if (! $session) {
            $this->error("Session introuvable : {$sessionId}");
            $this->line('Listez les sessions disponibles avec :');
            $this->line('  php artisan tinker --execute="App\\Models\\Session::all([\'id\',\'title\',\'mode\',\'status\'])->each(fn(\$s)=>dump(\$s->toArray()));"');

            return Command::FAILURE;
        }

        $instruction = $session->initial_instruction
            ?? 'Démonstration depuis la CLI — instruction factice.';

        $this->info("Session  : {$sessionId}");
        $this->info("Scénario : {$scenario}");
        $this->info("Mode     : {$mode}");
        $this->info("Délai    : {$delayMs} ms entre événements");
        $this->newLine();
        $this->info('Exécution synchrone (dispatchSync)…');

        // dispatchSync() contourne la queue et exécute le job directement dans ce process.
        // Cela bloque la CLI le temps du scénario, mais aucun queue worker n'est requis.
        MockAgentJob::dispatchSync(
            sessionId: $sessionId,
            instruction: $instruction,
            mode: $mode,
            scenario: $scenario,
            resumed: false,
            actionId: '',
            delayMs: $delayMs,
        );

        $this->newLine();
        $this->info('Scénario terminé.');
        $this->line('Vérifiez les messages insérés :');
        $this->line("  SELECT id, role, type, LEFT(content, 80) FROM messages WHERE session_id = '{$sessionId}' ORDER BY id;");

        return Command::SUCCESS;
    }
}
