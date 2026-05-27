<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\MessageCategory;
use App\Models\Number;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function index()
    {
        $accessibleIds = auth()->user()->accessibleNumberIds();

        $messages = Message::where(function ($q) use ($accessibleIds) {
            $q->whereIn('receiver_number_id', $accessibleIds)
                ->orWhereIn('sender_number_id', $accessibleIds);
        })
            ->whereNull('parent_id')
            ->with(['sender', 'receiver', 'template.category'])
            ->latest()
            ->paginate(20);

        $myNumberIds = auth()->user()->numbers()->pluck('id');

        return view('messages.index', compact('messages', 'myNumberIds'));
    }

    public function compose()
    {
        $myNumbers = auth()->user()->numbers()->where('status', 'active')->get();
        $categories = MessageCategory::with('templates')->where('is_active', true)->get();
        return view('messages.compose', compact('myNumbers', 'categories'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'sender_number_id' => 'required|exists:numbers,id',
            'receiver_number_id' => 'required|exists:numbers,id|different:sender_number_id',
            'template_id' => 'required|exists:message_templates,id',
        ]);

        $sender = Number::findOrFail($data['sender_number_id']);
        $receiver = Number::findOrFail($data['receiver_number_id']);

        abort_unless($sender->user_id === auth()->id(), 403);

        if (!$receiver->canReceiveFrom($sender)) {
            return back()->with('error', 'This number cannot receive your message (blocked or DND).');
        }

        $status = ($receiver->user->status === 'busy') ? 'queued' : 'sent';

        Message::create(array_merge($data, ['status' => $status]));

        return redirect()->route('messages.index')->with('success', 'Message sent!');
    }

    public function show(Message $message)
    {
        $accessibleIds = auth()->user()->accessibleNumberIds();
        $myNumberIds = auth()->user()->numbers()->pluck('id');

        abort_unless(
            $accessibleIds->contains($message->receiver_number_id) ||
            $accessibleIds->contains($message->sender_number_id),
            403
        );

        $message->load('template.category', 'sender', 'receiver', 'replies.template');

        if ($accessibleIds->contains($message->receiver_number_id) && $message->status === 'sent') {
            $message->update(['status' => 'read', 'read_at' => now()]);
        }

        $replyTemplates = $message->template->category->templates()
            ->where('is_reply', true)
            ->where('is_active', true)
            ->get();

        return view('messages.show', compact('message', 'replyTemplates', 'myNumberIds', 'accessibleIds'));
    }

    public function reply(Request $request, Message $message)
    {
        $accessibleIds = auth()->user()->accessibleNumberIds();
        abort_unless($accessibleIds->contains($message->receiver_number_id), 403);

        $data = $request->validate([
            'template_id' => 'required|exists:message_templates,id',
        ]);

        Message::create([
            'sender_number_id' => $message->receiver_number_id,
            'receiver_number_id' => $message->sender_number_id,
            'template_id' => $data['template_id'],
            'parent_id' => $message->id,
            'status' => 'sent',
        ]);

        return redirect()->route('messages.show', $message)->with('success', 'Reply sent!');
    }

    public function numberInbox(Number $number)
    {


        // abort_unless($number->isAccessibleBy(auth()->user()), 403);

        // dd([
        //     'number_id' => $number->id,
        //     'receiver_messages' => \App\Models\Message::where('receiver_number_id', $number->id)->count(),
        //     'sender_messages' => \App\Models\Message::where('sender_number_id', $number->id)->count(),
        //     'all_messages' => \App\Models\Message::all(['id', 'sender_number_id', 'receiver_number_id', 'parent_id']),
        // ]);
        // Must be owner or assistant
        abort_unless($number->isAccessibleBy(auth()->user()), 403);

        $messages = Message::where(function ($q) use ($number) {
            $q->where('receiver_number_id', $number->id)
                ->orWhere('sender_number_id', $number->id);
        })
            ->whereNull('parent_id')
            ->with(['sender', 'receiver', 'template.category'])
            ->latest()
            ->paginate(20);

        $isOwner = $number->user_id === auth()->id();

        return view('messages.number_inbox', compact('number', 'messages', 'isOwner'));
    }
}
