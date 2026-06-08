<?php

return [

    /*
     * Routes concernées par CORS.
     * sanctum/csrf-cookie doit être inclus pour que le SPA puisse récupérer le token.
     */
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /*
     * Origines autorisées : le SPA Vue (Vite dev) et l'APP_URL en production.
     * Doit être explicite (pas '*') car supports_credentials est activé.
     */
    'allowed_origins' => [
        env('FRONTEND_URL', 'http://localhost:5173'),
        env('APP_URL', 'http://localhost:8000'),
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    /*
     * Indispensable pour que le cookie de session soit transmis par le navigateur,
     * y compris pour les connexions SSE (EventSource avec withCredentials: true).
     */
    'supports_credentials' => true,

];
