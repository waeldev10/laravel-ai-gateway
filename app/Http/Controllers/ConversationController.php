<?php

namespace App\Http\Controllers;

use App\Http\Requests\DestroyManyConversationsRequest;
use App\Http\Requests\ListConversationsRequest;
use App\Http\Requests\StoreConversationRequest;
use App\Models\Conversation;
use App\Services\Conversation\ConversationService;
use App\Services\Message\MessageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ConversationController extends Controller
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly MessageService $messages,
    ) {}

    public function index(ListConversationsRequest $request): View
    {
        $search = $request->validated('search');

        return view('conversations.index', [
            'conversations' => $this->conversations->listFor($request->user(), $search),
            'search' => $search,
        ]);
    }

    public function create(): View
    {
        return view('conversations.create');
    }

    public function store(StoreConversationRequest $request): RedirectResponse
    {
        $conversation = $this->conversations->createFor($request->user(), $request->validated());

        return redirect()->route('conversations.show', $conversation);
    }

    public function show(Request $request, Conversation $conversation): View
    {
        $conversation = $this->conversations->showFor($request->user(), $conversation);

        return view('conversations.show', [
            'conversation' => $conversation,
            'messages' => $this->messages->listFor($request->user(), $conversation),
        ]);
    }

    public function destroy(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->authorize('delete', $conversation);

        $this->conversations->deleteFor($request->user(), $conversation);

        return redirect()->route('conversations.index');
    }

    public function destroyMany(DestroyManyConversationsRequest $request): RedirectResponse
    {
        $this->conversations->deleteMany($request->user(), $request->validated('ids', []));

        return redirect()->route('conversations.index');
    }
}
