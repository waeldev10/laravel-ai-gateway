<?php

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Support\ArabicDateTime;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 14:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

describe('arabic date helper', function () {
    test('relative message times use Arabic units and Eastern digits', function () {
        $now = Carbon::parse('2026-06-15 14:00:00');

        expect(ArabicDateTime::forMessage($now, $now))->toBe('الآن')
            ->and(ArabicDateTime::forMessage(Carbon::parse('2026-06-15 13:59:00'), $now))->toBe('منذ دقيقة')
            ->and(ArabicDateTime::forMessage(Carbon::parse('2026-06-15 13:58:00'), $now))->toBe('منذ دقيقتين')
            ->and(ArabicDateTime::forMessage(Carbon::parse('2026-06-15 13:55:00'), $now))->toBe('منذ ٥ دقائق')
            ->and(ArabicDateTime::forMessage(Carbon::parse('2026-06-15 13:00:00'), $now))->toBe('منذ ساعة')
            ->and(ArabicDateTime::forMessage(Carbon::parse('2026-06-15 11:00:00'), $now))->toBe('منذ ٣ ساعات');
    });

    test('older message times fall back to Arabic day and date formats', function () {
        $now = Carbon::parse('2026-06-15 14:00:00');

        expect(ArabicDateTime::forMessage(Carbon::parse('2026-06-14 10:15:00'), $now))->toBe('أمس، ١٠:١٥ ص')
            ->and(ArabicDateTime::forMessage(Carbon::parse('2026-03-05 14:15:00'), $now))->toBe('٥ مارس، ٢:١٥ م')
            ->and(ArabicDateTime::forMessage(Carbon::parse('2024-03-05 09:05:00'), $now))->toBe('٥ مارس ٢٠٢٤، ٩:٠٥ ص');
    });

    test('search dates carry full date and time, sidebar times carry time only', function () {
        $at = Carbon::parse('2026-09-13 16:30:00');

        expect(ArabicDateTime::forSearch($at))->toBe('١٣ سبتمبر ٢٠٢٦، ٤:٣٠ م')
            ->and(ArabicDateTime::timeOnly($at))->toBe('٤:٣٠ م')
            ->and(ArabicDateTime::timeOnly(Carbon::parse('2026-09-13 10:15:00')))->toBe('١٠:١٥ ص');
    });
});

describe('message timestamps on the conversation page', function () {
    test('every message shows its real created_at in Arabic', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::User,
            'content' => 'رسالة قبل خمس دقائق',
            'created_at' => Carbon::parse('2026-06-15 13:55:00'),
        ]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::Assistant,
            'content' => 'رد الأمس',
            'created_at' => Carbon::parse('2026-06-14 10:15:00'),
        ]);

        $html = $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->getContent();

        expect($html)->toContain('منذ ٥ دقائق')
            ->and($html)->toContain('أمس، ١٠:١٥ ص')
            ->and(substr_count($html, '<time'))->toBe(2);
    });

    test('user and assistant messages stay visually distinct', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::User,
            'content' => 'رسالة المستخدم',
        ]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::Assistant,
            'content' => 'رسالة المساعد',
        ]);

        $html = $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->getContent();

        expect($html)->toContain('justify-end')
            ->and($html)->toContain('justify-start')
            ->and($html)->toContain('whitespace-pre-wrap')
            ->and($html)->toContain('dir="auto"');
    });

    test('multiline messages render with their line breaks preserved', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::User,
            'content' => "السطر الأول\nالسطر الثاني",
        ]);

        $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->assertSee('السطر الأول', false)
            ->assertSee('السطر الثاني', false)
            ->assertSee('whitespace-pre-wrap', false);
    });
});
