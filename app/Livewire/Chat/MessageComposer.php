<?php

namespace App\Livewire\Chat;

use App\AI\Exceptions\AiException;
use App\AI\Exceptions\AiUsageLimitException;
use App\Models\Conversation;
use App\Services\AI\AiUsageService;
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

    /**
     * Server-authoritative usage-limit state for the initial paint: the
     * backend (AiUsageService) is the source of truth, never Alpine/JS.
     * Refreshed on every legacy send and on demand via refreshUsageState
     * (called by the streaming JS after it re-checks the status endpoint).
     */
    public bool $usageLimited = false;

    public ?int $usageRetryAfter = null;

    public function mount(AiUsageService $usage): void
    {
        $this->refreshUsageState($usage);
    }

    public function refreshUsageState(AiUsageService $usage): void
    {
        $remaining = $usage->remainingCooldown(Auth::user());

        $this->usageLimited = $remaining !== null;
        $this->usageRetryAfter = $remaining;
    }

    /**
     * Server-rendered cooldown duration for the banner (e.g. "4 دقائق
     * و32 ثانية"). The streaming JS mirrors this exact formatting for
     * its client-side countdown ticks.
     */
    public function formatCooldown(?int $seconds): string
    {
        if ($seconds === null || $seconds <= 0) {
            return '';
        }

        $parts = [];
        $minutes = intdiv($seconds, 60);
        $rest = $seconds % 60;

        if ($minutes > 0) {
            $parts[] = $minutes.' '.$this->arabicUnit($minutes, 'دقيقة', 'دقيقتان', 'دقائق');
        }

        if ($rest > 0) {
            $parts[] = $rest.' '.$this->arabicUnit($rest, 'ثانية', 'ثانيتان', 'ثوانٍ');
        }

        return implode(' و', $parts);
    }

    private function arabicUnit(int $count, string $one, string $two, string $few): string
    {
        if ($count === 1) {
            return $one;
        }

        if ($count === 2) {
            return $two;
        }

        return $count <= 10 ? $few : $one;
    }

    public function send(MessageService $messages, ConversationService $conversations, AiUsageService $usage): void
    {
        $this->validate(['content' => ['required', 'string', 'min:1', 'max:10000']]);

        // New-chat mode: no conversation yet. Create the conversation, persist
        // the exact submitted text as the first message, and assign a title —
        // one service operation — then generate the assistant's reply before
        // navigating (SPA) to the real conversation.
        if ($this->conversation === null) {
            try {
                $conversation = $conversations->startFor(Auth::user(), $this->content);
            } catch (Throwable $e) {
                report($e);

                $this->dispatch('toast', type: 'error', title: 'تعذر إنشاء المحادثة', message: 'حاول مرة أخرى.');

                return;
            }

            $this->reset('content');

            // The first user message is already persisted: a provider failure
            // must not lose it, so still navigate and report the failure
            // through the session toast on the destination page.
            try {
                $messages->generateReply(Auth::user(), $conversation);
            } catch (AiUsageLimitException $e) {
                report($e);

                // No toast here: the usage-limit banner (refreshed below)
                // is the dedicated UI state, never a generic error message.
                $this->refreshUsageState($usage);
                $this->redirect(route('conversations.show', $conversation), navigate: true);

                return;
            } catch (AiException $e) {
                report($e);

                session()->flash('toast', ['type' => 'error', 'title' => 'تعذر الحصول على رد المساعد', 'message' => $e->userMessage()]);
                $this->redirect(route('conversations.show', $conversation), navigate: true);

                return;
            }

            $this->dispatch('message-sent');
            session()->flash('toast', ['type' => 'success', 'title' => 'محادثة جديدة', 'message' => 'تم إنشاء المحادثة بنجاح.']);
            $this->redirect(route('conversations.show', $conversation), navigate: true);

            return;
        }

        // Existing-conversation mode: persist the message, generate the
        // assistant's reply, and stay on the page. No redirect here: a
        // redirect would force a full browser reload on every message.
        // Instead the message list (ConversationMessages, which listens for
        // `message-sent`) re-renders from the persisted state and the unified
        // Toast confirms via a browser event — same mechanism as every other
        // Livewire action in the application. On provider failure the user
        // message is already persisted, so refresh the list and report a
        // safe error without creating an assistant message.
        try {
            $messages->sendUserMessage(Auth::user(), $this->conversation, $this->content);
        } catch (AiUsageLimitException $e) {
            report($e);

            // Same dedicated handling: refresh the banner state instead of
            // a generic toast. The user message stays persisted and the
            // list refreshes, exactly like other provider failures.
            $this->refreshUsageState($usage);
            $this->reset('content');
            $this->dispatch('message-sent');

            return;
        } catch (AiException $e) {
            report($e);

            $this->reset('content');
            $this->dispatch('message-sent');
            $this->dispatch('toast', type: 'error', title: 'تعذر الحصول على رد المساعد', message: $e->userMessage());

            return;
        }

        $this->reset('content');
        $this->dispatch('message-sent');
        $this->dispatch('toast', type: 'success', title: 'تم الإرسال', message: 'تم إرسال رسالتك بنجاح.');
    }

    public function render()
    {
        return view('livewire.chat.message-composer');
    }
}
