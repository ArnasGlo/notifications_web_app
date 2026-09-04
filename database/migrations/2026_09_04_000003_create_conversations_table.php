<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One conversation per unordered pair of numbers.
 *
 * The pair is stored normalised — number_one_id always holds the lower id — and
 * that normalisation happens in PHP (Conversation::between), never in SQL. Doing
 * it in a query would need LEAST/GREATEST on MySQL and min()/max() on sqlite, and
 * skipping it entirely splits a thread in two whenever the viewer owns both
 * numbers, since direction would then decide which side is "theirs".
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('number_one_id')->constrained('numbers')->cascadeOnDelete();
            $table->foreignId('number_two_id')->constrained('numbers')->cascadeOnDelete();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['number_one_id', 'number_two_id']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('conversation_id')->nullable()->after('id')
                ->constrained()->cascadeOnDelete();
        });

        $this->backfill();

        Schema::table('messages', function (Blueprint $table) {
            $table->unsignedBigInteger('conversation_id')->nullable(false)->change();
        });
    }

    /** Group every existing message into its pair's conversation. */
    private function backfill(): void
    {
        $conversations = [];   // "low:high" => ['id' => int, 'last' => string]
        $now = now();

        DB::table('messages')
            ->select('id', 'sender_number_id', 'receiver_number_id', 'created_at')
            ->orderBy('id')
            ->chunk(500, function ($messages) use (&$conversations, $now) {
                foreach ($messages as $message) {
                    $low = min($message->sender_number_id, $message->receiver_number_id);
                    $high = max($message->sender_number_id, $message->receiver_number_id);
                    $key = $low.':'.$high;

                    if (! isset($conversations[$key])) {
                        $conversations[$key] = [
                            'id' => DB::table('conversations')->insertGetId([
                                'number_one_id' => $low,
                                'number_two_id' => $high,
                                'last_message_at' => $message->created_at,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]),
                            'last' => $message->created_at,
                        ];
                    }

                    // 'Y-m-d H:i:s' compares correctly as a string.
                    if ($message->created_at > $conversations[$key]['last']) {
                        $conversations[$key]['last'] = $message->created_at;
                    }

                    DB::table('messages')->where('id', $message->id)
                        ->update(['conversation_id' => $conversations[$key]['id']]);
                }
            });

        foreach ($conversations as $conversation) {
            DB::table('conversations')->where('id', $conversation['id'])
                ->update(['last_message_at' => $conversation['last']]);
        }
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['conversation_id']);
            $table->dropColumn('conversation_id');
        });

        Schema::dropIfExists('conversations');
    }
};
