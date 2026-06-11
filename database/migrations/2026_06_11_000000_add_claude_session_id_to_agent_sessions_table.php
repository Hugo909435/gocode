<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_sessions', function (Blueprint $table) {
            // ID de session Claude Code CLI — permet de reprendre la conversation
            // via --resume au lieu de repartir de zéro à chaque instruction.
            $table->string('claude_session_id')->nullable()->after('initial_instruction');
        });
    }

    public function down(): void
    {
        Schema::table('agent_sessions', function (Blueprint $table) {
            $table->dropColumn('claude_session_id');
        });
    }
};
