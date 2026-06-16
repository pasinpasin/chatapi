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
    Schema::create('properties', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->string('name');
        $table->string('slug')->unique();
        $table->string('type')->default('hotel'); // hotel, vila, bnb
        $table->string('address')->nullable();
        $table->decimal('lat', 10, 7)->nullable();
        $table->decimal('lng', 10, 7)->nullable();
        $table->text('description')->nullable();
        $table->text('rules')->nullable();       // rregullat e hotelit
        $table->json('amenities')->nullable();   // wifi, parking, etc
        $table->boolean('webchat_enabled')->default(true);
        $table->boolean('whatsapp_enabled')->default(false);
        $table->string('whatsapp_number')->nullable();
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
