<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_sessions', function (Blueprint $table) {
            // Remplace claude_session_id (résumé de session CLI Claude Code, driver
            // retiré) par le modèle NVIDIA choisi pour la session ('kimi' ou 'deepseek').
            $table->dropColumn('claude_session_id');
            $table->string('model')->nullable()->after('mode');
        });
    }

    public function down(): void
    {
        Schema::table('agent_sessions', function (Blueprint $table) {
            $table->dropColumn('model');
            $table->string('claude_session_id')->nullable()->after('initial_instruction');
        });
    }
};
