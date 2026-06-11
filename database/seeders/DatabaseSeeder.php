<?php

namespace Database\Seeders;

use App\Models\CommandWhitelist;
use App\Models\Message;
use App\Models\Project;
use App\Models\Session;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Compte unique du propriétaire du gocode.
        // Configurer ADMIN_EMAIL et ADMIN_PASSWORD dans .env avant le premier lancement.
        User::firstOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@gocode.local')],
            [
                'name' => 'Admin',
                'password' => Hash::make(env('ADMIN_PASSWORD', 'password')),
            ]
        );

        // Données de développement — utiles pour tester l'UI sans agent réel.
        if (app()->isLocal()) {
            $this->seedDevData();
        }
    }

    private function seedDevData(): void
    {
        // Projet principal de démo avec whitelist de commandes
        $mainProject = Project::factory()->create([
            'name' => 'gocode',
            'path' => base_path(),
            'default_branch' => 'main',
            'stack' => 'Laravel/Vue',
            'description' => 'Interface de pilotage d\'un agent IA local.',
            'git_remote' => 'https://github.com/hbeignon/gocode.git',
        ]);

        CommandWhitelist::insert([
            ['project_id' => $mainProject->id, 'pattern' => 'php artisan *', 'description' => 'Commandes Artisan Laravel', 'created_at' => now(), 'updated_at' => now()],
            ['project_id' => $mainProject->id, 'pattern' => 'npm run *',     'description' => 'Scripts NPM',               'created_at' => now(), 'updated_at' => now()],
            ['project_id' => $mainProject->id, 'pattern' => 'git status',    'description' => 'Lecture seule Git',          'created_at' => now(), 'updated_at' => now()],
            ['project_id' => $mainProject->id, 'pattern' => 'git diff *',    'description' => 'Diff Git',                   'created_at' => now(), 'updated_at' => now()],
            // Règle globale (sans projet)
            ['project_id' => null,             'pattern' => 'ls *',          'description' => 'Listage répertoire',         'created_at' => now(), 'updated_at' => now()],
        ]);

        // Deux autres projets
        Project::factory(2)->create();

        // Session terminée avec historique complet
        $doneSession = Session::factory()->done()->create([
            'project_id' => $mainProject->id,
            'title' => 'Initialisation du modèle de données',
            'mode' => 'execute',
        ]);

        Message::factory()->userMessage()->create([
            'session_id' => $doneSession->id,
            'content' => 'Crée les migrations et les modèles Eloquent pour le projet gocode.',
        ]);
        Message::factory()->agentMessage()->create([
            'session_id' => $doneSession->id,
            'content' => 'Je vais créer les migrations pour les tables `projects`, `agent_sessions`, `messages`, `command_whitelist` et `settings`.',
        ]);
        Message::factory()->fileChange()->create(['session_id' => $doneSession->id]);
        Message::factory()->create([
            'session_id' => $doneSession->id,
            'role' => 'system',
            'type' => 'terminal',
            'content' => "$ php artisan migrate\nMigration table created successfully.\nMigrating: 2026_06_08_140000_create_projects_table\nMigrated:  2026_06_08_140000_create_projects_table\n...",
        ]);
        Message::factory()->create([
            'session_id' => $doneSession->id,
            'role' => 'system',
            'type' => 'status',
            'content' => 'done',
        ]);

        // Session active (idle) prête à recevoir des instructions
        Session::factory()->idle()->create([
            'project_id' => $mainProject->id,
            'title' => 'Nouvelle session',
            'mode' => 'read',
        ]);

        // Paramètres globaux par défaut
        Setting::upsert([
            ['key' => 'agent_driver',       'value' => json_encode('mock'),  'created_at' => now(), 'updated_at' => now()],
            ['key' => 'max_cost_per_session', 'value' => json_encode(5.0),   'created_at' => now(), 'updated_at' => now()],
        ], ['key'], ['value', 'updated_at']);
    }
}
