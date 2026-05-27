<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_number_id')->constrained('numbers');
            $table->foreignId('receiver_number_id')->constrained('numbers');
            $table->foreignId('template_id')->constrained('message_templates');
            $table->foreignId('parent_id')->nullable()->constrained('messages'); // for replies
            $table->enum('status', ['sent', 'queued', 'blocked', 'read'])->default('sent');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }

};
