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
            $table->string('type');                 // status | plan | message | log | terminal | …
            $table->json('payload');
            $table->timestamp('emitted_at')->useCurrent();
            $table->index(['session_id', 'emitted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
