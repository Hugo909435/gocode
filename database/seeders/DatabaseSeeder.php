<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Compte unique du propriétaire du Cockpit
        User::firstOrCreate(
            ['email' => 'admin@cockpit.local'],
            [
                'name'     => 'Admin',
                'password' => Hash::make('password'), // à changer après le premier login
            ]
        );
    }
}
