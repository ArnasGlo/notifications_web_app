<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Free-text typing needs an agreement between the two numbers; templates are the
 * default. A number's owner can opt in to typing with everyone (allow_typing),
 * and a conversation can hold an explicit agreement, pending until accepted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('numbers', function (Blueprint $table) {
            $table->boolean('allow_typing')->default(false)->after('status');
        });

        Schema::create('typing_agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('requested_by_number_id')
                ->constrained('numbers')
                ->cascadeOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('typing_agreements');

        Schema::table('numbers', function (Blueprint $table) {
            $table->dropColumn('allow_typing');
        });
    }
};
