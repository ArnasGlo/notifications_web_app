<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\MessageTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Message::contentFrom() is the one place that decides a message's body and
 * template_id. template_id means "the body IS this template".
 */
class MessageContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_template_with_no_text_is_sent_verbatim(): void
    {
        $template = MessageTemplate::factory()->create(['body' => 'Can you talk?']);

        $this->assertSame(
            ['body' => 'Can you talk?', 'template_id' => $template->id],
            Message::contentFrom($template, null)
        );
        $this->assertSame(
            ['body' => 'Can you talk?', 'template_id' => $template->id],
            Message::contentFrom($template, '   ')
        );
    }

    public function test_text_identical_to_the_template_keeps_it(): void
    {
        $template = MessageTemplate::factory()->create(['body' => 'Can you talk?']);

        $this->assertSame(
            ['body' => 'Can you talk?', 'template_id' => $template->id],
            Message::contentFrom($template, 'Can you talk?')
        );
    }

    public function test_edited_template_text_is_typed_text(): void
    {
        $template = MessageTemplate::factory()->create(['body' => 'Can you talk?']);

        $this->assertSame(
            ['body' => 'Can you talk now?', 'template_id' => null],
            Message::contentFrom($template, 'Can you talk now?')
        );
        // Exact means exact: a change of case is an edit.
        $this->assertNull(Message::contentFrom($template, 'can you talk?')['template_id']);
    }

    public function test_text_with_no_template_is_typed_text(): void
    {
        $this->assertSame(
            ['body' => 'Typed by hand', 'template_id' => null],
            Message::contentFrom(null, 'Typed by hand')
        );
    }

    public function test_a_message_needs_a_template_or_text(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Message::contentFrom(null, null);
    }
}
