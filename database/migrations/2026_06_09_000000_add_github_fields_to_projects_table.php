<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->enum('clone_status', ['pending', 'cloning', 'cloned', 'error'])
                ->nullable()
                ->after('git_remote');
            $table->text('clone_error')->nullable()->after('clone_status');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['clone_status', 'clone_error']);
        });
    }
};
