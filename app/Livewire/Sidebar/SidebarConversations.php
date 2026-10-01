<?php

namespace App\Livewire\Sidebar;

use App\Services\Conversation\ConversationService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

class SidebarConversations extends Component
{
    public ?string $activeId = null;

    public ?string $searchFilter = null;

    public function mount(?string $activeId = null, ?string $searchFilter = null): void
    {
        // Preferred: explicit mount params from the sidebar Blade (they survive
        // Livewire's lazy hydration, which runs without route/query context).
        // Fallback: initial full page load, where the request still carries it.
        $this->activeId = $activeId ?? request()->route('conversation')?->id;

        if ($searchFilter !== null) {
            $this->searchFilter = $searchFilter !== '' ? $searchFilter : null;
        } else {
            $search = request()->query('search');
            $this->searchFilter = is_string($search) && $search !== '' ? $search : null;
        }
    }

    /**
     * Skeleton shown while the sidebar loads lazily (see `lazy` on the usage
     * in components/sidebar/sidebar.blade.php): the page shell paints first,
     * then this component hydrates in isolation without blocking it.
     *
     * The placeholder is generated during the initial page render, where the
     * request still carries route context — so the open conversation (one
     * primary-key lookup) renders synchronously, already highlighted, while
     * everything else stays deferred behind the skeleton.
     */
    public function placeholder(): string
    {
        $active = null;
        $user = Auth::user();
        $id = request()->route('conversation')?->id;

        if ($user !== null && filled($id)) {
            $active = $user->conversations()->find($id);
        }

        return view('livewire.sidebar.sidebar-placeholder', ['active' => $active])->render();
    }

    #[On('conversations-changed')]
    public function refreshList(): void
    {
        // Intentionally empty: handling the event re-renders the list below
        // from ConversationService::sidebarFor, the single source of truth.
        // The re-fetch is bounded (pinned + recent caps) and genuinely needed
        // here — pin/rename/delete/create can move entries between groups —
        // while message sends never dispatch it, so typing never reloads the
        // sidebar. Dispatched after sidebar pins, search-page actions, the
        // conversation menu, and the sidebar rename/delete modals.
    }

    public function togglePin(string $id, ConversationService $service): void
    {
        // Single authoritative pin path (find + authorize + update in the
        // service, no refresh SELECT: the returned model already carries the
        // new pinned_at). The dispatch below refreshes the bounded sidebar
        // list and the search list, which re-sort from the database.
        $conversation = $service->togglePinFor(Auth::user(), $id);

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

        $groups = $user !== null
            ? $service->sidebarFor($user, $this->searchFilter, $this->activeId)
            : ['pinned' => collect(), 'recent' => collect(), 'hasMore' => false];

        return view('livewire.sidebar.sidebar-conversations', [
            'groups' => $groups,
            'activeId' => $this->activeId,
        ]);
    }
}
