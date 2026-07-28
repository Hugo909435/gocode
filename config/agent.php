<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Driver agent par défaut
    |--------------------------------------------------------------------------
    |
    | Valeurs disponibles : "mock", "nvidia".
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

        'nvidia' => [
            /*
             | Base URL de l'API NVIDIA (compatible OpenAI chat/completions).
             */
            'base_url' => env('NVIDIA_BASE_URL', 'https://integrate.api.nvidia.com/v1'),

            /*
             | Modèles disponibles, sélectionnables par session (agent_sessions.model).
             | Chaque modèle a sa propre clé API NVIDIA.
             */
            'models' => [
                'kimi' => [
                    'model' => env('NVIDIA_KIMI_MODEL', 'moonshotai/kimi-k2.6'),
                    'api_key' => env('NVIDIA_KIMI_API_KEY'),
                ],
                'deepseek' => [
                    'model' => env('NVIDIA_DEEPSEEK_MODEL', 'deepseek-ai/deepseek-v4-flash'),
                    'api_key' => env('NVIDIA_DEEPSEEK_API_KEY'),
                ],
            ],

            /*
             | Modèle utilisé par défaut pour une session qui n'en a pas choisi
             | explicitement (mode execute) et pour la collaboration read/plan.
             */
            'default_model' => env('NVIDIA_DEFAULT_MODEL', 'kimi'),

            /*
             | Timeout d'un appel HTTP chat/completions, en secondes.
             */
            'timeout' => (int) env('NVIDIA_TIMEOUT', 300),

            /*
             | Nombre maximum de tours agentiques (appels modèle + exécution
             | d'outils) par instruction. Plafond de sécurité contre les
             | boucles qui s'emballent. null = pas de limite.
             */
            'max_turns' => env('NVIDIA_MAX_TURNS') ? (int) env('NVIDIA_MAX_TURNS') : 25,

            /*
             | Mode execute durci : seules les commandes bash de la table
             | command_whitelist (règles par projet + règles globales) sont
             | autorisées. false = toute commande bash est exécutable (V1).
             */
            'enforce_whitelist' => (bool) env('NVIDIA_ENFORCE_WHITELIST', false),
        ],

    ],

];
