<?php

namespace App\Livewire\Chat;

use App\Models\Conversation;
use App\Services\Conversation\ConversationService;
use App\Services\Message\MessageService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Throwable;

class MessageComposer extends Component
{
    public ?Conversation $conversation = null;

    public bool $centered = false;

    public string $content = '';

    public function send(MessageService $messages, ConversationService $conversations): void
    {
        $this->validate(['content' => ['required', 'string', 'min:1', 'max:10000']]);

        // New-chat mode: no conversation yet. Create the conversation, persist
        // the exact submitted text as the first message, and assign a title —
        // one service operation — then navigate (SPA) to the real conversation.
        if ($this->conversation === null) {
            try {
                $conversation = $conversations->startFor(Auth::user(), $this->content);
            } catch (Throwable $e) {
                report($e);

                $this->dispatch('toast', type: 'error', title: 'تعذر إنشاء المحادثة', message: 'حاول مرة أخرى.');

                return;
            }

            $this->reset('content');
            $this->dispatch('message-sent');
            session()->flash('toast', ['type' => 'success', 'title' => 'محادثة جديدة', 'message' => 'تم إنشاء المحادثة بنجاح.']);
            $this->redirect(route('conversations.show', $conversation), navigate: true);

            return;
        }

        // Existing-conversation mode: persist one message and stay on the page.
        // No redirect here: a redirect would force a full browser reload on
        // every message. Instead the message list (ConversationMessages, which
        // listens for `message-sent`) re-renders from the persisted state and
        // the unified Toast confirms via a browser event — same mechanism as
        // every other Livewire action in the application.
        $messages->createUserMessage(Auth::user(), $this->conversation, $this->content);
        $this->reset('content');
        $this->dispatch('message-sent');
        $this->dispatch('toast', type: 'success', title: 'تم الإرسال', message: 'تم إرسال رسالتك بنجاح.');
    }

    public function render()
    {
        return view('livewire.chat.message-composer');
    }
}
