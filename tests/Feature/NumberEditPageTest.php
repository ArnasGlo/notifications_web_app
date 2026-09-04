<?php

namespace Tests\Feature;

use App\Models\Delegate;
use App\Models\Number;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NumberController@edit called $this->authorize('update', $number) with no registered
 * NumberPolicy (AuthServiceProvider::$policies is empty), so the ability always denied
 * and the edit page was unreachable for everyone — including the owner. It now uses the
 * same manual ownership check as update()/destroy(). Nothing covered this route before,
 * which is why the breakage went unnoticed.
 */
class NumberEditPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_can_open_the_edit_page(): void
    {
        $owner = User::factory()->create();
        $number = Number::factory()->for($owner)->create(['number' => '+37060011111']);

        $this->actingAs($owner)
            ->get(route('numbers.edit', $number))
            ->assertStatus(200)
            ->assertSee('+37060011111');
    }

    public function test_an_unrelated_user_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $number = Number::factory()->for($owner)->create();

        $this->actingAs($other)
            ->get(route('numbers.edit', $number))
            ->assertStatus(403);
    }

    public function test_an_assistant_is_forbidden(): void
    {
        // Editing a number is owner-only (§3): delegates can view and reply to messages
        // on it, nothing more.
        $owner = User::factory()->create();
        $assistant = User::factory()->create();
        $number = Number::factory()->for($owner)->create();
        Delegate::create(['number_id' => $number->id, 'assistant_user_id' => $assistant->id]);

        $this->actingAs($assistant)
            ->get(route('numbers.edit', $number))
            ->assertStatus(403);
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $number = Number::factory()->create();

        $this->get(route('numbers.edit', $number))->assertRedirect('/login');
    }
}
