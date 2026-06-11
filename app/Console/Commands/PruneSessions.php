<?php

namespace App\Console\Commands;

use App\Models\Session;
use Illuminate\Console\Command;

class PruneSessions extends Command
{
    protected $signature = 'sessions:prune {--days=30 : Supprimer les sessions terminées depuis plus de N jours}';

    protected $description = 'Supprime les sessions terminées anciennes (et leurs messages, en cascade) pour contenir la croissance de la table messages';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        // Uniquement les sessions terminales — jamais une session en cours.
        $deleted = Session::query()
            ->whereIn('status', ['done', 'error', 'idle'])
            ->whereNotNull('ended_at')
            ->where('ended_at', '<', $cutoff)
            ->delete();

        $this->info("{$deleted} session(s) terminée(s) avant {$cutoff->toDateString()} supprimée(s).");

        return self::SUCCESS;
    }
}
