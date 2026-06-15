<?php

namespace Database\Seeders;

use App\Models\User; 
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'user@example.com'],
            ['name' => 'User', 'password' => bcrypt('password123')]
        );

        $this->call(MessageCategorySeeder::class);
    }
}
