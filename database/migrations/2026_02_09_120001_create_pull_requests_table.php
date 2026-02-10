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
        Schema::create('pull_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')->constrained()->onDelete('cascade');
            $table->string('bitbucket_id'); // PR ID from Bitbucket
            $table->string('title');
            $table->string('author_display_name')->nullable();
            $table->timestamp('created_on');
            $table->timestamp('updated_on');
            $table->enum('state', ['OPEN', 'MERGED', 'DECLINED', 'SUPERSEDED']);
            $table->string('ticket')->nullable(); // Extracted ticket reference (e.g., PROJ-123)
            $table->json('bitbucket_data')->nullable(); // Store additional Bitbucket metadata
            $table->timestamp('last_fetched_at')->nullable(); // When this PR was last fetched from API
            $table->timestamps();
            
            $table->unique(['repository_id', 'bitbucket_id']);
            $table->index(['repository_id', 'updated_on']);
            $table->index(['author_display_name', 'updated_on']);
            $table->index(['state', 'updated_on']);
            $table->index('ticket');
            $table->index('last_fetched_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pull_requests');
    }
};