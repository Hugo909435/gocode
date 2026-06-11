<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GenerateVapidKeys extends Command
{
    protected $signature = 'webpush:vapid';

    protected $description = 'Génère une paire de clés VAPID pour les notifications Web Push';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $this->info('Clés VAPID générées — à copier dans votre .env :');
        $this->newLine();
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->line('VAPID_SUBJECT=mailto:'.(config('mail.from.address') ?: 'admin@gocode.local'));
        $this->newLine();
        $this->comment('Puis : php artisan config:clear');

        return self::SUCCESS;
    }
}
