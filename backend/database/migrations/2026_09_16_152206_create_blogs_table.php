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
        Schema::create('blogs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('category', 100);
            $table->string('excerpt', 500);
            $table->longText('content');
            $table->string('image', 2048)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['published_at', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blogs');
    }
};
