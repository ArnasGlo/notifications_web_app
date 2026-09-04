<?php

namespace App\Http\Controllers\Api;

use App\Actions\ReplyToMessage;
use App\Actions\SendMessage;
use App\Exceptions\CannotSendMessage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\MessageReplyRequest;
use App\Http\Requests\Api\MessageStoreRequest;
use App\Http\Resources\ComposeCategoryResource;
use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Models\MessageCategory;
use App\Models\MessageTemplate;
use App\Models\Number;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function index(Request $request)
    {
        $accessibleIds = $request->user()->accessibleNumberIds();
        $search = trim((string) $request->input('q'));

        $messages = Message::accessibleTo($accessibleIds)
            ->when($search !== '', fn ($q) => $q->withCounterpart($search, $accessibleIds))
            ->whereNull('parent_id')
            ->with(['sender', 'receiver', 'template.category'])
            ->latest()
            ->paginate(20)
            ->appends($request->only('q'));

        return MessageResource::collection($messages);
    }

    public function numberInbox(Request $request, Number $number)
    {
        abort_unless($number->isAccessibleBy($request->user()), 403);

        $messages = Message::where(function ($q) use ($number) {
            $q->where('receiver_number_id', $number->id)
                ->orWhere('sender_number_id', $number->id);
        })
            ->whereNull('parent_id')
            ->with(['sender', 'receiver', 'template.category'])
            ->latest()
            ->paginate(20);

        return MessageResource::collection($messages);
    }

    public function composeData(Request $request)
    {
        $categories = MessageCategory::composePayload();

        return ComposeCategoryResource::collection($categories);
    }

    public function store(MessageStoreRequest $request, SendMessage $send)
    {
        try {
            $message = $send($request->user(), $request->validated());
        } catch (CannotSendMessage $e) {
            abort($e->status, $e->getMessage());
        }

        return (new MessageResource($message->load(['sender', 'receiver', 'template.category'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Message $message)
    {
        $accessibleIds = $request->user()->accessibleNumberIds();

        abort_unless(
            $accessibleIds->contains($message->receiver_number_id) ||
            $accessibleIds->contains($message->sender_number_id),
            403
        );

        $message->load([
            'sender', 'receiver', 'template.category',
            'replies.sender', 'replies.receiver', 'replies.template',
        ]);

        if ($accessibleIds->contains($message->receiver_number_id) && $message->status === 'sent') {
            $message->update(['status' => 'read', 'read_at' => now()]);
        }

        $replyTemplates = $message->availableReplyTemplates();
        $message->setRelation('replyTemplates', $replyTemplates);

        return new MessageResource($message);
    }

    public function reply(MessageReplyRequest $request, Message $message, ReplyToMessage $replyTo)
    {
        $template = MessageTemplate::findOrFail($request->validated('template_id'));

        try {
            $reply = $replyTo($request->user(), $message, $template);
        } catch (CannotSendMessage $e) {
            abort($e->status, $e->getMessage());
        }

        return (new MessageResource($reply->load(['sender', 'receiver', 'template.category'])))
            ->response()
            ->setStatusCode(201);
    }
}
