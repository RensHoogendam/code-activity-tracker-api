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
        Schema::table('commits', function (Blueprint $table) {
            $table->string('pull_request_id')->nullable()->after('branch');
            $table->index('pull_request_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('commits', function (Blueprint $table) {
            $table->dropIndex(['pull_request_id']);
            $table->dropColumn('pull_request_id');
        });
    }
};
