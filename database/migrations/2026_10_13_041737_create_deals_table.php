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
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('product_url', 2048);
            $table->string('image_url', 2048)->nullable();
            $table->string('store', 100);
            $table->decimal('current_price', 13, 2)->nullable();
            $table->decimal('regular_price', 13, 2)->nullable();
            $table->char('currency', 3)->default('USD');
            $table->unsignedSmallInteger('discount_percent')->nullable();
            $table->enum('status', ['active', 'sold_out', 'expired'])->default('active');
            $table->unsignedInteger('votes_count')->default(0);
            $table->unsignedInteger('bookmarks_count')->default(0);
            $table->unsignedInteger('comments_count')->default(0);
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index(['store', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deals');
    }
};
