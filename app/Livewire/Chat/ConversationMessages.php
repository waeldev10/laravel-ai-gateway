<?php

namespace App\Livewire\Chat;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\Message\MessageService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\On;
use Livewire\Component;

class ConversationMessages extends Component
{
    /**
     * Minimum message count before the side navigator renders. Defined once
     * here so Blade and tests share the same threshold.
     */
    public const NAVIGATOR_THRESHOLD = 5;

    public Conversation $conversation;

    public function mount(Conversation $conversation): void
    {
        $this->conversation = $conversation;
    }

    #[On('message-sent')]
    public function refreshList(): void
    {
        // Intentionally empty: handling the event re-renders the list below
        // from MessageService, the single source of truth. Dispatched by
        // MessageComposer after it persists a message, so the new message
        // appears with no redirect and no full page reload. Same pattern as
        // SidebarConversations::refreshList for `conversations-changed`.
    }

    /**
     * Persist an inline edit of one user message. The draft arrives as plain
     * parameters (Alpine owns the transient editor only); validation runs on
     * the server and failures surface through the unified error Toast while
     * the editor stays open. Success closes the editor via the
     * `close-edit-message` browser event and the re-render shows the
     * server-authoritative content — never a client-side copy.
     */
    public function updateMessage(string $id, string $content, MessageService $service): void
    {
        $validator = Validator::make(['content' => $content], ['content' => ['required', 'string', 'min:1', 'max:10000']]);

        if ($validator->fails()) {
            $this->dispatch('toast', type: 'error', title: 'تعذر الحفظ', message: (string) $validator->errors()->first('content'));

            return;
        }

        $message = Message::query()->findOrFail($id);

        $service->updateUserMessage(Auth::user(), $message, $content);

        $this->dispatch('close-edit-message');
        $this->dispatch('toast', type: 'success', title: 'تم التعديل', message: 'تم حفظ التعديلات بنجاح.');
    }

    public function render(MessageService $messages)
    {
        return view('livewire.chat.conversation-messages', [
            'messages' => $messages->listFor(Auth::user(), $this->conversation),
        ]);
    }
}
