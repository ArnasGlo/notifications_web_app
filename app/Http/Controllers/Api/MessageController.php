<?php

namespace App\Http\Controllers\Api;

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

        $messages = Message::where(function ($q) use ($accessibleIds) {
            $q->whereIn('receiver_number_id', $accessibleIds)
                ->orWhereIn('sender_number_id', $accessibleIds);
        })
            ->whereNull('parent_id')
            ->with(['sender', 'receiver', 'template.category'])
            ->latest()
            ->paginate(20);

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
        $categories = MessageCategory::with(['templates' => function ($q) {
            $q->where('is_active', true)->where('is_reply', false);
        }])
            ->where('is_active', true)
            ->get();

        return ComposeCategoryResource::collection($categories);
    }

    public function store(MessageStoreRequest $request)
    {
        $data = $request->validated();

        $sender = Number::findOrFail($data['sender_number_id']);
        $receiver = Number::findOrFail($data['receiver_number_id']);

        abort_unless($sender->user_id === $request->user()->id, 403);

        abort_unless($receiver->canReceiveFrom($sender), 422, 'This number cannot receive your message (blocked or DND).');

        $status = ($receiver->user->status === 'busy') ? 'queued' : 'sent';

        $message = Message::create(array_merge($data, ['status' => $status]));

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

        $replyTemplates = $message->template->category->templates()
            ->where('is_reply', true)
            ->where('is_active', true)
            ->get();
        $message->setRelation('replyTemplates', $replyTemplates);

        return new MessageResource($message);
    }

    public function reply(MessageReplyRequest $request, Message $message)
    {
        $accessibleIds = $request->user()->accessibleNumberIds();

        abort_unless($accessibleIds->contains($message->receiver_number_id), 403);

        $template = MessageTemplate::findOrFail($request->validated('template_id'));

        abort_unless(! $message->replies()->exists(), 422, 'A reply has already been sent for this message.');

        abort_unless(
            $template->category_id === $message->template->category_id && $template->is_reply && $template->is_active,
            422,
            'This template cannot be used as a reply to this message.'
        );

        $reply = Message::create([
            'sender_number_id' => $message->receiver_number_id,
            'receiver_number_id' => $message->sender_number_id,
            'template_id' => $template->id,
            'parent_id' => $message->id,
            'status' => 'sent',
        ]);

        return (new MessageResource($reply->load(['sender', 'receiver', 'template.category'])))
            ->response()
            ->setStatusCode(201);
    }
}
