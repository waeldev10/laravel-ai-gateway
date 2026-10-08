<?php

use App\Livewire\Chat\MessageComposer;
use App\Livewire\Sidebar\SidebarConversations;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Conversation\ConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

describe('sidebar bounded loading', function () {
    test('initial page paint shows a skeleton and defers the list instead of blocking', function () {
        $user = User::factory()->create();
        Conversation::factory()->create(['user_id' => $user->id, 'title' => 'Deferred entry']);

        $html = $this->actingAs($user)->get(route('conversations.create'))->assertOk()->getContent();

        // Skeleton + lazy hydration marker: the shell paints first.
        expect($html)->toContain('animate-pulse')
            ->and($html)->toContain('__lazyLoad')
            // The full list is NOT rendered synchronously with the page.
            ->and($html)->not()->toContain('Deferred entry');
    });

    test('the open conversation highlights instantly while the rest stays deferred', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id, 'title' => 'Current chat']);
        Conversation::factory()->create(['user_id' => $user->id, 'title' => 'Other chat']);

        $html = $this->actingAs($user)->get(route('conversations.show', $conversation))->assertOk()->getContent();

        // Active row is server-rendered synchronously; the full list hydrates later.
        expect($html)->toContain('aria-current="page"')
            ->and($html)->toContain('ui-accent-soft')
            ->and($html)->toContain('animate-pulse')
            ->and($html)->toContain('__lazyLoad')
            ->and($html)->not()->toContain('Other chat');
    });

    test('sidebar renders at most the recent cap plus all pinned, with a link to search', function () {
        $user = User::factory()->create();

        // Boundaries derive from the service cap so the test stays honest if
        // the cap is ever retuned: newest $cap entries visible, older hidden.
        $cap = ConversationService::SIDEBAR_RECENT_LIMIT;
        $total = $cap + 5;

        foreach (range(1, $total) as $i) {
            Conversation::factory()->create([
                'user_id' => $user->id,
                'title' => "Conversation {$i}",
                'created_at' => now()->addSeconds($i),
            ]);
        }

        $this->actingAs($user);
        $html = Livewire::test(SidebarConversations::class)->html();

        expect($html)->toContain("Conversation {$total}")
            ->and($html)->toContain('Conversation '.($total - $cap + 1))
            ->and($html)->not()->toContain('Conversation '.($total - $cap))
            ->and($html)->toContain('عرض كل المحادثات');
    });

    test('pinned conversations survive the recent cap', function () {
        $user = User::factory()->create();

        foreach (range(1, 35) as $i) {
            Conversation::factory()->create([
                'user_id' => $user->id,
                'title' => "Conversation {$i}",
                'created_at' => now()->addSeconds($i),
            ]);
        }

        $oldest = Conversation::where('user_id', $user->id)->orderBy('created_at')->first();
        app(ConversationService::class)->setPinnedFor($user, $oldest, true);

        $this->actingAs($user);
        $html = Livewire::test(SidebarConversations::class)->html();

        expect($html)->toContain('المثبتة')
            ->and($html)->toContain($oldest->title);
    });

    test('the open conversation is included even outside the recent window', function () {
        $user = User::factory()->create();

        foreach (range(1, 35) as $i) {
            Conversation::factory()->create([
                'user_id' => $user->id,
                'title' => "Conversation {$i}",
                'created_at' => now()->addSeconds($i),
            ]);
        }

        $oldest = Conversation::where('user_id', $user->id)->orderBy('created_at')->first();

        $this->actingAs($user);
        $html = Livewire::test(SidebarConversations::class, ['activeId' => (string) $oldest->id])->html();

        expect($html)->toContain($oldest->title)
            ->and($html)->toContain('aria-current="page"');
    });

    test('sidebar render runs bounded queries, never an unbounded full load', function () {
        $user = User::factory()->create();
        Conversation::factory()->count(3)->create(['user_id' => $user->id]);

        $this->actingAs($user);

        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::test(SidebarConversations::class)->html();

        $conversationQueries = collect(DB::getQueryLog())
            ->filter(fn ($entry) => str_contains($entry['query'], 'conversations'))
            ->values();

        // One capped pinned query + one capped recent query. No full load, no
        // per-row queries, no refresh SELECT.
        expect($conversationQueries->count())->toBe(2);

        foreach ($conversationQueries as $entry) {
            expect($entry['query'])->toContain('limit');
        }
    });

    test('fast navigations never flash the progress bar', function () {
        $js = file_get_contents(resource_path('js/app.js'));
        $css = file_get_contents(resource_path('css/app.css'));

        // Visibility gate only: armed at navigation start, lifted after
        // ~250ms or immediately when navigation finishes — whichever is
        // first. The framework bar itself is never disabled or replaced,
        // and navigation timing is untouched.
        expect($js)->toContain('nav-loading-pending')
            ->and($js)->toContain("document.addEventListener('livewire:navigate'")
            ->and($js)->toContain('disarmNavLoadingGate')
            ->and($js)->not()->toContain('disableProgressBar')
            ->and($css)->toContain('html.nav-loading-pending #nprogress');
    });

    test('livewire navigation fetches render the sidebar list synchronously without skeleton', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id, 'title' => 'Current chat']);
        Conversation::factory()->create(['user_id' => $user->id, 'title' => 'Other chat']);

        // A wire:navigate fetch carries X-Livewire-Navigate: the swapped
        // body must already contain the real list — no skeleton phase and
        // no second hydration request flashing the sidebar on navigation.
        $html = $this->actingAs($user)
            ->get(route('conversations.show', $conversation), ['X-Livewire-Navigate' => '1'])
            ->assertOk()
            ->getContent();

        expect($html)->toContain('Current chat')
            ->and($html)->toContain('Other chat')
            ->and($html)->toContain('aria-current="page"')
            ->and($html)->not()->toContain('__lazyLoad')
            ->and($html)->not()->toContain('animate-pulse');
    });

    test('placeholder is a lightweight skeleton without data', function () {
        $html = (new SidebarConversations)->placeholder();

        expect($html)->toContain('animate-pulse')
            ->and($html)->not()->toContain('المثبتة')
            ->and($html)->not()->toContain('الأخيرة');
    });
});

describe('sidebar ordering stability', function () {
    test('renaming keeps the conversation in place', function () {
        $user = User::factory()->create();
        Conversation::factory()->create([
            'user_id' => $user->id, 'title' => 'Oldest', 'created_at' => now()->subWeek(),
        ]);
        $middle = Conversation::factory()->create([
            'user_id' => $user->id, 'title' => 'Middle', 'created_at' => now()->subDay(),
        ]);
        Conversation::factory()->create(['user_id' => $user->id, 'title' => 'Newest']);

        app(ConversationService::class)->renameFor($user, $middle, 'Renamed middle');

        $this->actingAs($user);
        $html = Livewire::test(SidebarConversations::class)->html();

        expect($html)->toContain('Renamed middle')
            ->and(strpos($html, 'Newest'))->toBeLessThan(strpos($html, 'Renamed middle'))
            ->and(strpos($html, 'Renamed middle'))->toBeLessThan(strpos($html, 'Oldest'));
    });

    test('pin moves the conversation to the pinned group, unpin returns it by age', function () {
        $user = User::factory()->create();
        $old = Conversation::factory()->create([
            'user_id' => $user->id, 'title' => 'Oldie', 'created_at' => now()->subWeek(),
        ]);
        Conversation::factory()->create(['user_id' => $user->id, 'title' => 'Fresh']);

        $this->actingAs($user);

        $pinned = Livewire::test(SidebarConversations::class)
            ->call('togglePin', (string) $old->id)
            ->assertDispatched('conversations-changed')
            ->assertDispatched('toast', type: 'success')
            ->html();

        $pinnedPos = strpos($pinned, 'المثبتة');
        $recentPos = strpos($pinned, 'الأخيرة');

        expect($pinnedPos)->toBeLessThan($recentPos)
            ->and(strpos($pinned, 'Oldie'))->toBeGreaterThan($pinnedPos)
            ->and(strpos($pinned, 'Oldie'))->toBeLessThan($recentPos);

        $unpinned = Livewire::test(SidebarConversations::class)
            ->call('togglePin', (string) $old->id)
            ->html();

        // Back in recent, ordered by age: Fresh first, Oldie last.
        expect($unpinned)->not()->toContain('المثبتة')
            ->and(strpos($unpinned, 'Fresh'))->toBeLessThan(strpos($unpinned, 'Oldie'));
    });

    test('sending a message does not reload the sidebar', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        $this->actingAs($user);

        Livewire::test(MessageComposer::class, ['conversation' => $conversation])
            ->set('content', 'مرحبا')
            ->call('send')
            ->assertDispatched('message-sent')
            ->assertNotDispatched('conversations-changed');
    });
});
