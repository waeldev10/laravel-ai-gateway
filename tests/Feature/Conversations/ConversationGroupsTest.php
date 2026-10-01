<?php

use App\Livewire\Conversation\ConversationSearch;
use App\Livewire\Sidebar\SidebarConversations;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Conversation\ConversationService;
use App\Support\ArabicDateTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

describe('pinned and recent grouping logic', function () {
    test('splitPinned isolates pinned conversations and preserves order', function () {
        $user = User::factory()->create();
        $fresh = Conversation::factory()->create(['user_id' => $user->id, 'title' => 'Fresh']);
        $pinnedOld = Conversation::factory()->create([
            'user_id' => $user->id, 'title' => 'Pinned old', 'pinned_at' => now()->subDay(),
        ]);
        $pinnedNew = Conversation::factory()->create([
            'user_id' => $user->id, 'title' => 'Pinned new', 'pinned_at' => now(),
        ]);
        $older = Conversation::factory()->create([
            'user_id' => $user->id, 'title' => 'Older', 'created_at' => now()->subWeek(),
        ]);

        // Incoming order mirrors the service query: pinned first, newest first.
        $groups = app(ConversationService::class)->splitPinned(
            collect([$pinnedNew, $pinnedOld, $fresh, $older])
        );

        expect($groups['pinned']->map->title->all())->toBe(['Pinned new', 'Pinned old'])
            ->and($groups['recent']->map->title->all())->toBe(['Fresh', 'Older'])
            ->and($groups['recent']->pluck('id')->contains($pinnedOld->id))->toBeFalse()
            ->and($groups['recent']->pluck('id')->contains($pinnedNew->id))->toBeFalse();
    });
});

describe('sidebar groups', function () {
    test('without pinned conversations only الأخيرة is displayed', function () {
        $user = User::factory()->create();
        Conversation::factory()->create(['user_id' => $user->id, 'title' => 'Only recent']);

        $this->actingAs($user);
        $html = Livewire::test(SidebarConversations::class)->html();

        expect($html)->toContain('الأخيرة')
            ->and($html)->not()->toContain('المثبتة')
            ->and($html)->toContain('Only recent');
    });

    test('pinned conversations appear first under المثبتة and never repeat', function () {
        $user = User::factory()->create();
        Conversation::factory()->create(['user_id' => $user->id, 'title' => 'Recent one']);
        Conversation::factory()->create([
            'user_id' => $user->id, 'title' => 'Pinned one', 'pinned_at' => now(),
        ]);

        $this->actingAs($user);
        $html = Livewire::test(SidebarConversations::class)->html();

        expect($html)->toContain('المثبتة')
            ->and($html)->toContain('الأخيرة');

        $pinnedPos = strpos($html, 'المثبتة');
        $recentPos = strpos($html, 'الأخيرة');

        expect($pinnedPos)->toBeLessThan($recentPos);

        // Every mention of the pinned title (link, aria-label, data attrs)
        // lives inside the المثبتة section: nothing leaks into الأخيرة.
        $positions = [];
        $offset = 0;
        while (($p = strpos($html, 'Pinned one', $offset)) !== false) {
            $positions[] = $p;
            $offset = $p + 1;
        }

        expect($positions)->not()->toBeEmpty();

        foreach ($positions as $p) {
            expect($p)->toBeGreaterThan($pinnedPos)
                ->and($p)->toBeLessThan($recentPos);
        }

        expect(strpos($html, 'Recent one'))->toBeGreaterThan($recentPos);
    });

    test('sidebar rows reveal time only on hover through CSS without new state', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user);
        $html = Livewire::test(SidebarConversations::class)->html();

        expect($html)->toContain(ArabicDateTime::timeOnly($conversation->created_at))
            ->and($html)->toContain('group-hover:opacity-100')
            ->and($html)->not()->toContain(ArabicDateTime::forSearch($conversation->created_at));
    });

    test('recent conversations keep their newest-first ordering', function () {
        $user = User::factory()->create();
        Conversation::factory()->create([
            'user_id' => $user->id, 'title' => 'Oldest recent', 'created_at' => now()->subWeek(),
        ]);
        Conversation::factory()->create(['user_id' => $user->id, 'title' => 'Newest recent']);

        $this->actingAs($user);
        $html = Livewire::test(SidebarConversations::class)->html();

        expect($html)->toContain('الأخيرة')
            ->and(strpos($html, 'Newest recent'))->toBeLessThan(strpos($html, 'Oldest recent'));
    });
});

describe('search groups and dates', function () {
    test('search results always show the real date and time in Arabic', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id, 'title' => 'Dated result']);

        $this->actingAs($user);
        $html = Livewire::test(ConversationSearch::class)->html();

        expect($html)->toContain('Dated result')
            ->and($html)->toContain(ArabicDateTime::forSearch($conversation->created_at));
    });

    test('search groups pinned first without duplication', function () {
        $user = User::factory()->create();
        Conversation::factory()->create(['user_id' => $user->id, 'title' => 'Search recent']);
        Conversation::factory()->create([
            'user_id' => $user->id, 'title' => 'Search pinned', 'pinned_at' => now(),
        ]);

        $this->actingAs($user);
        $html = Livewire::test(ConversationSearch::class)->html();

        expect($html)->toContain('المثبتة')
            ->and($html)->toContain('الأخيرة')
            ->and(strpos($html, 'المثبتة'))->toBeLessThan(strpos($html, 'الأخيرة'));

        $pinnedPos = strpos($html, 'المثبتة');
        $recentPos = strpos($html, 'الأخيرة');
        $positions = [];
        $offset = 0;
        while (($p = strpos($html, 'Search pinned', $offset)) !== false) {
            $positions[] = $p;
            $offset = $p + 1;
        }

        expect($positions)->not()->toBeEmpty();

        foreach ($positions as $p) {
            expect($p)->toBeGreaterThan($pinnedPos)
                ->and($p)->toBeLessThan($recentPos);
        }
    });

    test('search without pinned conversations shows only الأخيرة', function () {
        $user = User::factory()->create();
        Conversation::factory()->create(['user_id' => $user->id, 'title' => 'Plain result']);

        $this->actingAs($user);
        $html = Livewire::test(ConversationSearch::class)->html();

        expect($html)->toContain('الأخيرة')
            ->and($html)->not()->toContain('المثبتة');
    });

    test('no technical English labels leak into navigation', function () {
        $user = User::factory()->create();
        Conversation::factory()->create(['user_id' => $user->id, 'title' => 'Label check']);

        $this->actingAs($user);
        $html = Livewire::test(SidebarConversations::class)->html();

        expect($html)->not()->toContain('>Pinned<')
            ->and($html)->not()->toContain('>Recent<')
            ->and($html)->not()->toContain('Created at')
            ->and($html)->not()->toContain('Updated at');
    });
});
