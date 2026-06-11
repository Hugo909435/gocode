<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Driver agent par défaut
    |--------------------------------------------------------------------------
    |
    | Valeurs disponibles : "mock"
    | Ajouter "opencode" quand le vrai pilote sera implémenté.
    |
    */
    'default' => env('AGENT_DRIVER', 'mock'),

    'drivers' => [

        'mock' => [
            /*
             | Scénario joué par MockAgentDriver / MockAgentJob.
             |   'success' → parcours complet (read / plan / execute) avec confirmations
             |   'error'   → émet un événement error après la phase reading
             */
            'scenario' => env('MOCK_SCENARIO', 'success'),

            /*
             | Délai en millisecondes entre chaque événement simulé.
             | Mettre à 0 pour des tests instantanés (ou utiliser --no-delay dans agent:demo).
             | Nécessite QUEUE_CONNECTION=database + `php artisan queue:work` pour l'effet temps réel.
             */
            'delay_ms' => (int) env('MOCK_DELAY_MS', 1000),
        ],

        'claude-code' => [
            /*
             | Chemin vers le binaire Claude Code.
             | Sur Windows, les binaires npm globaux sont des fichiers .cmd :
             |   CLAUDE_BINARY=claude.cmd  ou chemin complet absolu
             | Sur Linux/macOS, 'claude' suffit si le binaire est dans le PATH.
             */
            'binary' => env('CLAUDE_BINARY', 'claude'),

            /*
             | Modèle Claude à utiliser (null = laisser Claude Code choisir son défaut).
             | Exemples : claude-opus-4-5, claude-sonnet-4-6, claude-haiku-4-5
             */
            'model' => env('CLAUDE_MODEL', null),

            /*
             | Clé API Anthropic transmise en variable d'environnement au sous-processus.
             | Laisser vide si Claude Code utilise déjà sa propre clé stockée (~/.claude).
             */
            'api_key' => env('ANTHROPIC_API_KEY', null),

            /*
             | Timeout du processus en secondes avant kill forcé.
             */
            'timeout' => (int) env('CLAUDE_TIMEOUT', 300),

            /*
             | Nombre maximum de tours agentiques par instruction (--max-turns).
             | Plafond de sécurité contre les boucles d'exploration qui s'emballent
             | (et donc contre la consommation de tokens incontrôlée).
             | null = pas de limite. Une valeur trop basse peut tronquer une tâche
             | execute légitime ; 25-50 est un bon point de départ.
             */
            'max_turns' => env('CLAUDE_MAX_TURNS') ? (int) env('CLAUDE_MAX_TURNS') : null,

            /*
             | Mode execute durci : remplace --dangerously-skip-permissions par une
             | liste --allowedTools construite depuis la table command_whitelist
             | (règles par projet + règles globales). Les commandes Bash hors
             | whitelist échouent au lieu de s'exécuter. false = comportement V1.
             */
            'enforce_whitelist' => (bool) env('CLAUDE_ENFORCE_WHITELIST', false),
        ],

    ],

];
