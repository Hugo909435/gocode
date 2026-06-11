<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Purge quotidienne des sessions terminées depuis plus de 30 jours
// (nécessite php artisan schedule:work ou une entrée cron en production).
Schedule::command('sessions:prune --days=30')->daily();
