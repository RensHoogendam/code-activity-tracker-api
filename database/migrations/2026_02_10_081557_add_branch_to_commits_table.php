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
            $table->string('branch')->nullable()->after('ticket');
            $table->index('branch');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('commits', function (Blueprint $table) {
            $table->dropIndex(['branch']);
            $table->dropColumn('branch');
        });
    }
};
