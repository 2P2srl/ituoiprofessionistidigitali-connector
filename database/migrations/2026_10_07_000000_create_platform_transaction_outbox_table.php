<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('platform_transaction_outbox', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 191)->unique();
            $table->uuid('transaction_id')->unique();
            $table->unsignedInteger('revision');
            $table->unsignedInteger('sent_revision')->nullable();
            $table->json('payload');
            $table->string('status', 16)->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }
};
