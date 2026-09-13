<?php

namespace App\Livewire;

use App\Services\Conversation\ConversationService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

class SidebarConversations extends Component
{
    public ?string $activeId = null;

    public ?string $searchFilter = null;

    public function mount(): void
    {
        // Captured once per full page load: follow-up Livewire requests
        // (e.g. event refreshes) run without route/query context, so these
        // must not be re-read in render().
        $this->activeId = request()->route('conversation')?->id;

        $search = request()->query('search');
        $this->searchFilter = is_string($search) ? $search : null;
    }

    #[On('conversations-changed')]
    public function refreshList(): void
    {
        // Intentionally empty: handling the event re-renders the list below
        // from ConversationService, the single source of truth. Dispatched by
        // ConversationSearch after search-initiated pin/rename/delete, and by
        // togglePin below after sidebar-initiated pins.
    }

    public function togglePin(string $id, ConversationService $service): void
    {
        // Same server-side pin action as ConversationSearch::togglePin:
        // ConversationService::setPinnedFor stays authoritative (persistence +
        // authorization); the dispatch below lets the search list refresh too.
        $conversation = $service->findFor(Auth::user(), $id);
        $conversation = $service->setPinnedFor(Auth::user(), $conversation, $conversation->pinned_at === null);

        $this->dispatch('conversations-changed');
        $this->dispatch('toast',
            type: 'success',
            title: $conversation->pinned_at !== null ? 'تم التثبيت' : 'تم إلغاء التثبيت',
            message: $conversation->title,
        );
    }

    public function render(ConversationService $service)
    {
        $user = Auth::user();

        $conversations = $user !== null
            ? $service->listFor($user, $this->searchFilter)
            : collect();

        return view('livewire.sidebar-conversations', [
            'groups' => $service->splitPinned($conversations),
            'activeId' => $this->activeId,
        ]);
    }
}
