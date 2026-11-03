<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('adobe-sign.oauth.table', 'adobe_sign_tokens'), function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('access_token');   // encrypted
            $table->text('refresh_token')->nullable(); // encrypted
            $table->timestamp('expires_at')->nullable();
            $table->string('api_access_point')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('adobe-sign.oauth.table', 'adobe_sign_tokens'));
    }
};
