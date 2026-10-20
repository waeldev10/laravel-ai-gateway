<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('sidebar conversation menu responsiveness', function () {
    test('the three-dot menu trigger is hidden below md and intact from md up', function () {
        $user = User::factory()->create();
        $conversations = Conversation::factory()->count(2)->create(['user_id' => $user->id]);

        $html = view('components.conversation.conversation-nav', [
            'pinned' => collect(),
            'recent' => $conversations,
            'activeId' => null,
            'context' => 'sidebar',
            'scrollClass' => '',
        ])->render();

        // Pure CSS hiding: the wrapper (trigger + tooltips) leaves flex
        // layout entirely on small screens, so no gap artifact remains.
        expect($html)->toContain('relative hidden shrink-0 md:block')
            // Desktop trigger behavior is untouched.
            ->and($html)->toContain('openMenu(')
            ->and($html)->toContain('lg:opacity-0');
    });
});
