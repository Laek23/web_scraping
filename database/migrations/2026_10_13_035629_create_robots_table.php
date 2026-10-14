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
        Schema::create('robots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('website_url', 2048);
            $table->enum('mode', ['Scrape', 'Extract', 'Crawl', 'Search', 'Document']);
            $table->json('output_formats');
            $table->text('smart_queries')->nullable();
            $table->boolean('monitoring')->default(false);
            $table->unsignedSmallInteger('monitoring_interval')->default(60);
            $table->timestamps();
            $table->index(['user_id', 'monitoring']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('robots');
    }
};
