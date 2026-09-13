<?php

namespace App\Livewire;

use App\Services\Conversation\ConversationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\On;
use Livewire\Component;

class ConversationSearch extends Component
{
    public const PAGE_SIZE = 5;

    public string $search = '';

    public int $perPage = self::PAGE_SIZE;

    public function mount(string $search = ''): void
    {
        $query = request()->query('search');

        $this->search = $search !== '' ? $search : (is_string($query) ? mb_substr($query, 0, 255) : '');
    }

    public function updatedSearch(): void
    {
        $this->perPage = self::PAGE_SIZE;
    }

    public function searchAction(): void
    {
        // explicit search submit (Enter) — render fetches with current $search
    }

    public function clear(): void
    {
        $this->search = '';
        $this->perPage = self::PAGE_SIZE;
    }

    public function loadMore(): void
    {
        $this->perPage += self::PAGE_SIZE;
    }

    /**
     * Delete one or more conversations (single + bulk share this submit path).
     *
     * Selection itself lives in Alpine until submit; the ids arrive here only
     * when the user confirms the delete modal. Authorization stays in the
     * service (all-or-nothing: foreign/missing ids abort with an exception).
     *
     * @param  array<int, string>  $ids
     */
    public function deleteConversations(array $ids, ConversationService $service): void
    {
        $ids = array_values(array_unique(array_map('strval', $ids)));

        if ($ids === []) {
            $this->dispatch('toast', type: 'error', title: 'تعذر الحذف', message: 'لم يتم تحديد أي محادثة.');

            return;
        }

        $service->deleteMany(Auth::user(), $ids);

        $this->dispatch('close-confirm-modal', id: 'page-delete');
        $this->dispatch('conversations-changed');
        $this->dispatch('toast',
            type: 'success',
            title: count($ids) > 1 ? 'تم حذف المحادثات' : 'تم حذف المحادثة',
            message: count($ids) > 1 ? 'تم حذف '.count($ids).' من المحادثات نهائياً.' : 'تم حذف المحادثة نهائياً.',
        );
    }

    /**
     * Rename on submit only. Opening/typing in the modal is purely Alpine;
     * this runs once when the user saves.
     */
    public function renameConversation(string $id, string $title, ConversationService $service): void
    {
        $validator = Validator::make(['title' => $title], ['title' => ['required', 'string', 'max:255']]);

        if ($validator->fails()) {
            $this->dispatch('toast', type: 'error', title: 'تعذر الحفظ', message: (string) $validator->errors()->first('title'));

            return;
        }

        $conversation = $service->findFor(Auth::user(), $id);
        $service->renameFor(Auth::user(), $conversation, $title);

        $this->dispatch('close-rename-modal', id: 'search-rename');
        $this->dispatch('conversations-changed');
        $this->dispatch('toast', type: 'success', title: 'تمت إعادة التسمية', message: $title);
    }

    public function togglePin(string $id, ConversationService $service): void
    {
        $conversation = $service->findFor(Auth::user(), $id);
        $conversation = $service->setPinnedFor(Auth::user(), $conversation, $conversation->pinned_at === null);

        $this->dispatch('conversations-changed');
        $this->dispatch('toast',
            type: 'success',
            title: $conversation->pinned_at !== null ? 'تم التثبيت' : 'تم إلغاء التثبيت',
            message: $conversation->title,
        );
    }

    #[On('conversations-changed')]
    public function refreshAfterSidebarPin(): void
    {
        // Intentionally empty: handling the event re-renders the search list
        // from ConversationService. Dispatched by SidebarConversations after
        // sidebar-initiated pins so both lists stay synchronized.
    }

    public function render(ConversationService $service)
    {
        $results = $service->searchPaginated(Auth::user(), $this->search !== '' ? $this->search : null, $this->perPage);

        return view('livewire.conversation-search', [
            'results' => $results,
            'groups' => $service->splitPinned($results->getCollection()),
            'ids' => $results->getCollection()->map(fn ($c) => (string) $c->id)->all(),
        ]);
    }
}
