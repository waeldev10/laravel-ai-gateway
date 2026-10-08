<?php

namespace App\Http\Controllers;

use App\Http\Requests\DestroyManyConversationsRequest;
use App\Http\Requests\StoreConversationRequest;
use App\Http\Requests\UpdateConversationRequest;
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

    public function create(): View
    {
        return view('conversations.create');
    }

    public function search(): View
    {
        return view('conversations.search');
    }

    public function store(StoreConversationRequest $request): RedirectResponse
    {
        $conversation = $this->conversations->createFor($request->user(), $request->validated());

        return redirect()->route('conversations.show', $conversation)
            ->with('toast', ['type' => 'success', 'title' => 'محادثة جديدة', 'message' => 'تم إنشاء المحادثة بنجاح.']);
    }

    public function show(Request $request, Conversation $conversation): View
    {
        $conversation = $this->conversations->showFor($request->user(), $conversation);

        // Initial paint loads only the latest history page (at most
        // HISTORY_PAGE_SIZE messages): the full history is never loaded
        // here. Older pages are fetched on demand through
        // MessageController@index as the user scrolls up.
        $page = $this->messages->latestPageFor($request->user(), $conversation);

        return view('conversations.show', [
            'conversation' => $conversation,
            'messages' => $page['messages'],
            'historyHasMore' => $page['hasMore'],
            'historyOldestCursor' => $page['oldestCursor'],
        ]);
    }

    public function destroy(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->authorize('delete', $conversation);

        $this->conversations->deleteFor($request->user(), $conversation);

        return redirect()->route('conversations.search')
            ->with('toast', ['type' => 'success', 'title' => 'تم حذف المحادثة', 'message' => 'تم حذف المحادثة نهائياً.']);
    }

    public function update(UpdateConversationRequest $request, Conversation $conversation): RedirectResponse
    {
        $this->conversations->renameFor($request->user(), $conversation, $request->validated('title'));

        return back()
            ->with('toast', ['type' => 'success', 'title' => 'تمت إعادة التسمية', 'message' => $request->validated('title')]);
    }

    public function togglePin(Request $request, Conversation $conversation): RedirectResponse
    {
        $conversation = $this->conversations->setPinnedFor($request->user(), $conversation, $conversation->pinned_at === null);

        return back()
            ->with('toast', [
                'type' => 'success',
                'title' => $conversation->pinned_at !== null ? 'تم التثبيت' : 'تم إلغاء التثبيت',
                'message' => $conversation->title,
            ]);
    }

    public function destroyMany(DestroyManyConversationsRequest $request): RedirectResponse
    {
        $ids = $request->validated('ids', []);
        $this->conversations->deleteMany($request->user(), $ids);

        return redirect()->route('conversations.search')
            ->with('toast', ['type' => 'success', 'title' => 'تم حذف المحادثات', 'message' => 'تم حذف '.count($ids).' من المحادثات نهائياً.']);
    }
}
