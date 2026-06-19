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
        Schema::table('conversations', function (Blueprint $table) {
            $table->text('summary')->nullable();
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->boolean('is_summarized')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('summary');
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn('is_summarized');
        });
    }
};
