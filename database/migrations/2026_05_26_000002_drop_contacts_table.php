<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('contacts');
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\DB::statement('
            CREATE TABLE IF NOT EXISTS contacts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                number_id BIGINT UNSIGNED NOT NULL,
                contact_number_id BIGINT UNSIGNED NOT NULL,
                status ENUM("pending","accepted","blocked") NOT NULL DEFAULT "pending",
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                FOREIGN KEY (number_id) REFERENCES numbers(id) ON DELETE CASCADE,
                FOREIGN KEY (contact_number_id) REFERENCES numbers(id) ON DELETE CASCADE
            )
        ');
    }
};
