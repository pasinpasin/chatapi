<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('booking_url')->nullable()->after('whatsapp_number');
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->string('booking_url')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('booking_url');
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('booking_url');
        });
    }
};
