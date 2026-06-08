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

    ],

];
