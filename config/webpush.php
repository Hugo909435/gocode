<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Clés VAPID (Web Push)
    |--------------------------------------------------------------------------
    |
    | Générer avec : php artisan webpush:vapid
    | puis copier les valeurs dans .env. Sans clés, l'envoi de notifications
    | est silencieusement désactivé (l'app fonctionne normalement sans).
    |
    */

    'vapid' => [
        'subject' => env('VAPID_SUBJECT', 'mailto:admin@gocode.local'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],

];
