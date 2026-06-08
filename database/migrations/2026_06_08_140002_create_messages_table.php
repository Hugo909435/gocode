<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('session_id');
            $table->foreign('session_id')->references('id')->on('agent_sessions')->cascadeOnDelete();
            $table->enum('role', ['user', 'agent', 'system', 'tool']);
            $table->enum('type', ['text', 'plan', 'log', 'terminal', 'tool_call', 'file_change', 'confirmation_request', 'cost', 'status', 'error']);
            $table->longText('content');
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['session_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
