<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMessageRequest;
use App\Models\Conversation;
use App\Services\Message\MessageService;
use Illuminate\Http\RedirectResponse;

class MessageController extends Controller
{
    public function __construct(private readonly MessageService $messages) {}

    public function store(StoreMessageRequest $request, Conversation $conversation): RedirectResponse
    {
        $this->messages->createUserMessage(
            $request->user(),
            $conversation,
            $request->validated('content')
        );

        return redirect()
            ->route('conversations.show', $conversation)
            ->with('status', 'تم إرسال رسالتك بنجاح.');
    }
}
