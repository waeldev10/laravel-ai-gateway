<?php

namespace App\Livewire\Conversation;

use App\Services\Conversation\ConversationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Action menu for the single conversation page (rename / pin / share /
 * delete). Same authoritative backend as the sidebar and search lists —
 * every mutation goes through ConversationService, so authorization and
 * persistence live in exactly one place and no logic is duplicated.
 *
 * Only primitives are stored as public state (the id, never the Eloquent
 * model). Alpine owns the transient UI only (dropdown open state, clipboard
 * feedback); each confirmed action is exactly one Livewire request, and the
 * sidebar/search lists re-sort from the database via `conversations-changed`.
 */
class ConversationMenu extends Component
{
    #[Locked]
    public string $conversationId = '';

    public string $title = '';

    public bool $isPinned = false;

    public function mount(string $conversationId, ConversationService $service): void
    {
        $conversation = $service->findFor(Auth::user(), $conversationId);

        $this->conversationId = (string) $conversation->id;
        $this->title = $conversation->title;
        $this->isPinned = $conversation->pinned_at !== null;
    }

    public function togglePin(ConversationService $service): void
    {
        $conversation = $service->togglePinFor(Auth::user(), $this->conversationId);

        $this->isPinned = $conversation->pinned_at !== null;

        $this->dispatch('conversations-changed');
        $this->dispatch('toast',
            type: 'success',
            title: $this->isPinned ? 'تم التثبيت' : 'تم إلغاء التثبيت',
            message: $conversation->title,
        );
    }

    /**
     * Rename on submit only. Opening/typing in the modal is purely Alpine
     * (the draft rides along with this single request); validation failures
     * surface through the unified error toast, exactly like the search page.
     */
    public function rename(string $title, ConversationService $service): void
    {
        $validator = Validator::make(['title' => $title], ['title' => ['required', 'string', 'max:255']]);

        if ($validator->fails()) {
            $this->dispatch('toast', type: 'error', title: 'تعذر الحفظ', message: (string) $validator->errors()->first('title'));

            return;
        }

        $conversation = $service->findFor(Auth::user(), $this->conversationId);
        $service->renameFor(Auth::user(), $conversation, $title);

        $this->title = $title;

        $this->dispatch('close-menu-rename');
        $this->dispatch('conversations-changed');
        // The layout header (server-rendered) updates client-side from this.
        $this->dispatch('conversation-renamed', title: $title);
        $this->dispatch('toast', type: 'success', title: 'تمت إعادة التسمية', message: $title);
    }

    public function delete(ConversationService $service): void
    {
        $conversation = $service->findFor(Auth::user(), $this->conversationId);
        $service->deleteFor(Auth::user(), $conversation);

        // No `conversations-changed` dispatch here on purpose: this page is
        // being left (SPA navigate below), so no component update may run
        // against the now-deleted conversation afterwards — that race
        // re-requests the deleted conversation and surfaces a 404 before
        // the navigation lands. The destination renders a fresh sidebar
        // server-side, so no refresh is needed.
        session()->flash('toast', ['type' => 'success', 'title' => 'تم حذف المحادثة', 'message' => 'تم حذف المحادثة نهائياً.']);
        $this->redirect(route('conversations.create'), navigate: true);
    }

    public function render()
    {
        return view('livewire.conversation.conversation-menu');
    }
}
