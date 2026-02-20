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
        // Add a repository column to commits table for debugging
        // This will help us identify if there's a mismatch between repository_id and repository names
        Schema::table('commits', function (Blueprint $table) {
            $table->string('repository')->nullable()->after('repository_id')->index();
        });
        
        // Update existing commits to populate the repository field
        DB::statement('
            UPDATE commits 
            SET repository = (
                SELECT full_name 
                FROM repositories 
                WHERE repositories.id = commits.repository_id
            )
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('commits', function (Blueprint $table) {
            $table->dropIndex(['repository']);
            $table->dropColumn('repository');
        });
    }
};