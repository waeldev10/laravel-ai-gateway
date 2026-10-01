<?php

use App\Livewire\Conversation\ConversationSearch;
use App\Livewire\Sidebar\SidebarConversations;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

describe('sidebar scrollbar styling', function () {
    test('a reusable subtle scrollbar utility exists for both themes', function () {
        $css = file_get_contents(resource_path('css/app.css'));

        expect($css)->toContain('.ui-scroll-subtle')
            ->and($css)->toContain('scrollbar-width: thin')
            ->and($css)->toContain('::-webkit-scrollbar-thumb')
            ->and($css)->toContain('border-radius: 9999px')
            ->and($css)->toContain('.dark .ui-scroll-subtle::-webkit-scrollbar-thumb');
    });

    test('only the sidebar list opts into the custom scrollbar', function () {
        $user = User::factory()->create();
        Conversation::factory()->create(['user_id' => $user->id]);
        $this->actingAs($user);

        expect(Livewire::test(SidebarConversations::class)->html())->toContain('ui-scroll-subtle')
            ->and(Livewire::test(ConversationSearch::class)->html())->not()->toContain('ui-scroll-subtle');
    });

    test('sidebar scrolling behavior and layout classes are unchanged', function () {
        $source = file_get_contents(resource_path('views/components/conversation/conversation-nav.blade.php'));

        expect($source)->toContain('overflow-y-auto overflow-x-hidden');
    });
});
