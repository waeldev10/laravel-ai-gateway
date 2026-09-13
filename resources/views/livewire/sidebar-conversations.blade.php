{{-- Sidebar conversation list. Re-renders from ConversationService whenever a
     `conversations-changed` event arrives (pin/rename/delete from either the
     search page or this component's own shared actions menu). Sidebar pin uses
     the same Livewire togglePin action as search; rename/delete use the
     dedicated plain-form modals below. --}}
     
<div class="flex min-h-0 flex-1 flex-col">
    @include('components.conversation.conversation-nav', ['pinned' => $groups['pinned'], 'recent' => $groups['recent'], 'activeId' => $activeId, 'scrollClass' => 'ui-scroll-subtle'])
</div>
