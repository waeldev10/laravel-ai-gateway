<?php

use App\Livewire\Conversation\ConversationSearch;
use App\Livewire\Sidebar\SidebarConversations;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

describe('conversation title tooltip', function () {
    test('sidebar exposes the full title to a custom tooltip, never a native one', function () {
        $user = User::factory()->create();
        $title = 'عنوان محادثة طويل جدا يحتاج إلى تلميح مخصص لعرضه بالكامل دون اقتطاع';
        Conversation::factory()->create(['user_id' => $user->id, 'title' => $title]);

        $this->actingAs($user);
        $html = Livewire::test(SidebarConversations::class)->html();

        // Full real title is available for the tooltip via an escaped data attribute.
        expect($html)->toContain('data-full-title="'.$title.'"')
            // Shared custom tooltip element, teleported so nothing clips it.
            ->and($html)->toContain('role="tooltip"')
            ->and($html)->toContain('x-teleport="body"')
            ->and($html)->toContain('x-text="tipTitle"')
            // No browser-native tooltip anywhere in the navigation markup.
            ->and($html)->not()->toContain(' title="');
    });

    test('search exposes the same custom tooltip behavior', function () {
        $user = User::factory()->create();
        $title = 'نتيجة بحث بعنوان طويل جدا لاختبار التلميح المخصص في صفحة البحث';
        Conversation::factory()->create(['user_id' => $user->id, 'title' => $title]);

        $this->actingAs($user);
        $html = Livewire::test(ConversationSearch::class)->html();

        expect($html)->toContain('data-full-title="'.$title.'"')
            ->and($html)->toContain('role="tooltip"')
            ->and($html)->toContain('x-text="tipTitle"')
            ->and($html)->not()->toContain(' title="');
    });

    test('tooltip is small and compact yet shows the full title unwrapped-untruncated', function () {
        $source = file_get_contents(resource_path('views/components/conversation/title-tip.blade.php'));

        // Compact visual styling: small radius/padding/shadow and small type.
        expect($source)->toContain('max-w-[260px]')
            ->and($source)->toContain('rounded-lg')
            ->and($source)->toContain('px-2 py-1')
            ->and($source)->toContain('shadow-md')
            ->and($source)->toContain('text-[11px]')
            ->and($source)->not()->toContain('rounded-xl')
            ->and($source)->not()->toContain('shadow-xl')
            // Full title, never truncated: plain text binding that wraps.
            ->and($source)->toContain('x-text="tipTitle"')
            ->and($source)->toContain('break-words')
            ->and($source)->not()->toContain('truncate')
            ->and($source)->not()->toContain('line-clamp')
            ->and($source)->not()->toContain('…')
            ->and($source)->toContain('dark:bg-[#1e293b]')
            ->and($source)->toContain('pointer-events-none')
            ->and($source)->toContain('x-transition')
            ->and($source)->not()->toContain('$wire')
            ->and($source)->not()->toContain('wire:');
    });

    test('tooltip coexists with sidebar time and actions menu', function () {
        $user = User::factory()->create();
        Conversation::factory()->create(['user_id' => $user->id, 'title' => 'عنوان للتعايش']);

        $this->actingAs($user);
        $html = Livewire::test(SidebarConversations::class)->html();

        $linkPos = strpos($html, 'data-full-title="عنوان للتعايش"');
        $timePos = strpos($html, 'group-hover:opacity-100');
        $menuPos = strpos($html, 'خيارات عنوان للتعايش');

        expect($linkPos)->not()->toBeFalse()
            ->and($timePos)->not()->toBeFalse()
            ->and($menuPos)->not()->toBeFalse();
    });
});
