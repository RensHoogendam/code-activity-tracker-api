<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('commits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')->constrained()->onDelete('cascade');
            $table->string('hash', 40)->unique(); // Git commit hash
            $table->timestamp('commit_date');
            $table->text('message');
            $table->string('author_raw')->nullable(); // Raw author string from git
            $table->string('author_username')->nullable(); // Bitbucket username
            $table->string('ticket')->nullable(); // Extracted ticket reference (e.g., PROJ-123)
            $table->json('bitbucket_data')->nullable(); // Store additional Bitbucket metadata
            $table->timestamp('last_fetched_at')->nullable(); // When this commit was last fetched from API
            $table->timestamps();
            
            $table->index(['repository_id', 'commit_date']);
            $table->index(['author_username', 'commit_date']);
            $table->index('ticket');
            $table->index('last_fetched_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commits');
    }
};