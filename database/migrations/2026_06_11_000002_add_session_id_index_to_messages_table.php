<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Le polling (HTTP et SSE) fait WHERE session_id = ? AND id > ? ORDER BY id
            // toutes les ~500 ms — cet index composé le rend indolore quand la table grossit.
            $table->index(['session_id', 'id'], 'messages_session_id_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('messages_session_id_id_index');
        });
    }
};
