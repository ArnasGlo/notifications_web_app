<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Messages carry their own text from here on.
 *
 * Until now `template_id` *was* the message: the body shown to users was read
 * through the relation, so every message had to be one of the pre-written
 * templates verbatim. The composer now allows free typing and editing a template
 * after inserting it, so the sent text has to be stored on the message itself and
 * `template_id` becomes optional provenance — which canned response seeded it.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('body')->nullable()->after('template_id');
        });

        // Backfill from the template each existing message was built from: one
        // UPDATE per template rather than per message, and no UPDATE..JOIN, which
        // sqlite cannot express the same way MySQL can.
        foreach (DB::table('message_templates')->pluck('body', 'id') as $id => $body) {
            DB::table('messages')->where('template_id', $id)->update(['body' => $body]);
        }

        // Any message whose template has since been deleted would be left null.
        DB::table('messages')->whereNull('body')->update(['body' => '']);

        Schema::table('messages', function (Blueprint $table) {
            $table->string('body')->nullable(false)->change();
            $table->unsignedBigInteger('template_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Messages written without a template cannot be represented by the old
        // schema, so drop them rather than silently pointing them at a wrong row.
        DB::table('messages')->whereNull('template_id')->delete();

        Schema::table('messages', function (Blueprint $table) {
            $table->unsignedBigInteger('template_id')->nullable(false)->change();
            $table->dropColumn('body');
        });
    }
};
