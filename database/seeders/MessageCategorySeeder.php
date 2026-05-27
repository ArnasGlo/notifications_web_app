<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class MessageCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Meeting',
                'icon' => 'fas fa-calendar',
                'messages' => [
                    ['body' => 'Can you talk?', 'is_reply' => false],
                    ['body' => 'Call me back', 'is_reply' => false],
                    ['body' => 'I am busy', 'is_reply' => true],
                    ['body' => 'Let\'s meet at 18:00', 'is_reply' => true],
                    ['body' => 'Sure, one moment', 'is_reply' => true],
                ]
            ],
            [
                'name' => 'Status',
                'icon' => 'fas fa-info-circle',
                'messages' => [
                    ['body' => 'I am on my way', 'is_reply' => false],
                    ['body' => 'Running late', 'is_reply' => false],
                    ['body' => 'Arrived', 'is_reply' => true],
                    ['body' => 'OK, got it', 'is_reply' => true],
                ]
            ],
            [
                'name' => 'Urgent',
                'icon' => 'fas fa-exclamation-triangle',
                'messages' => [
                    ['body' => 'Call me urgently', 'is_reply' => false],
                    ['body' => 'Emergency - contact me', 'is_reply' => false],
                    ['body' => 'On my way', 'is_reply' => true],
                    ['body' => 'Cannot respond now', 'is_reply' => true],
                ]
            ],
        ];

        foreach ($categories as $cat) {
            $category = \App\Models\MessageCategory::create([
                'name' => $cat['name'],
                'icon' => $cat['icon'],
            ]);
            foreach ($cat['messages'] as $msg) {
                $category->templates()->create($msg);
            }
        }
    }
}
