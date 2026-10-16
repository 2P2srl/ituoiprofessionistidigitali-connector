<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('platform_event_outbox_files', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id')->index();
            $table->uuid('ref');
            $table->string('disk');
            $table->string('path', 1024);
            $table->string('name');
            $table->string('media_type');
            $table->char('sha256', 64);
            $table->uuid('upload_id')->nullable();
            $table->uuid('file_id')->nullable();
            $table->timestamp('citable_until')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'ref']);
        });
    }
};
