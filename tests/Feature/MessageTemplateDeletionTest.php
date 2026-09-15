<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\MessageCategory;
use App\Models\MessageTemplate;
use App\Models\Number;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Templates are never hard-deleted: messages.template_id must always point at a
 * real row. The admin "delete" retires a template instead.
 */
class MessageTemplateDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_deleting_a_template_messages_were_sent_from_deactivates_it_instead(): void
    {
        $viewer = User::factory()->create();
        $template = MessageTemplate::factory()->create(['body' => 'Can you talk?']);
        $message = Message::factory()->create([
            'sender_number_id' => Number::factory()->create()->id,
            'receiver_number_id' => Number::factory()->for($viewer)->create()->id,
            'template_id' => $template->id,
        ]);

        $this->actingAs($this->admin())
            ->delete(route('admin.templates.destroy', $template))
            ->assertRedirect(route('admin.templates.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('message_templates', ['id' => $template->id, 'is_active' => false]);
        $this->assertDatabaseHas('messages', ['id' => $message->id, 'template_id' => $template->id]);

        // The message still resolves its template for a client.
        $this->actingAs($viewer, 'sanctum')
            ->getJson("/api/messages/{$message->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.template.id', $template->id)
            ->assertJsonPath('data.template.body', 'Can you talk?');
    }

    public function test_deleting_an_unused_template_also_only_deactivates_it(): void
    {
        $template = MessageTemplate::factory()->create();

        $this->actingAs($this->admin())
            ->delete(route('admin.templates.destroy', $template))
            ->assertRedirect(route('admin.templates.index'));

        $this->assertDatabaseHas('message_templates', ['id' => $template->id, 'is_active' => false]);
    }

    public function test_the_database_refuses_to_hard_delete_a_template_a_message_references(): void
    {
        // The guarantee underneath the controller: messages.template_id is a
        // NO ACTION foreign key on sqlite and MySQL alike, so even a delete that
        // bypasses the admin screen can't orphan a message.
        $template = MessageTemplate::factory()->create();
        $message = Message::factory()->create(['template_id' => $template->id]);

        try {
            DB::table('message_templates')->where('id', $template->id)->delete();
            $this->fail('Hard-deleting a referenced template should violate messages.template_id.');
        } catch (QueryException $e) {
            $this->assertSame('23000', (string) $e->getCode());
        }

        $this->assertDatabaseHas('message_templates', ['id' => $template->id]);
        $this->assertDatabaseHas('messages', ['id' => $message->id, 'template_id' => $template->id]);
    }

    public function test_a_category_that_still_has_templates_cannot_be_deleted(): void
    {
        // message_templates.category_id cascades, so deleting the category would
        // hard-delete its templates in the database, around the controller above.
        // Retired templates count too.
        $category = MessageCategory::factory()->create();
        $template = MessageTemplate::factory()->for($category, 'category')->create(['is_active' => false]);
        Message::factory()->create(['template_id' => $template->id]);

        $this->actingAs($this->admin())
            ->from(route('admin.categories.index'))
            ->delete(route('admin.categories.destroy', $category))
            ->assertRedirect(route('admin.categories.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('message_categories', ['id' => $category->id]);
        $this->assertDatabaseHas('message_templates', ['id' => $template->id]);
    }

    public function test_a_non_admin_cannot_delete_a_template(): void
    {
        $template = MessageTemplate::factory()->create();

        $this->actingAs(User::factory()->create())
            ->delete(route('admin.templates.destroy', $template))
            ->assertStatus(403);

        $this->assertDatabaseHas('message_templates', ['id' => $template->id, 'is_active' => true]);
    }
}
