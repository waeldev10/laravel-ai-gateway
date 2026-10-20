<?php

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('assistant share text resolution', function () {
    test('the share handler resolves text through the button stable id, not self-closest', function () {
        $actions = file_get_contents(resource_path('js/message-actions.js'));

        // Regression: the share button itself carries data-message-id,
        // so btn.closest('[data-message-id]') matched the BUTTON and
        // found no [data-full-text] inside it ("no text to share").
        // The handler must resolve via the button's own stable id.
        expect($actions)->toContain('btn.dataset.messageId')
            ->and($actions)->toContain('messageNode(')
            ->and($actions)->toContain("querySelector('[data-full-text]')");
    });

    test('the rendered assistant block exposes the share id and its text node', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        $assistant = $conversation->messages()->create([
            'role' => MessageRole::Assistant,
            'content' => 'نص الرد القابل للمشاركة',
        ]);

        $html = $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->getContent();

        $id = (string) $assistant->getKey();

        // The share button carries the stable message id...
        expect($html)->toContain('data-action="share"')
            ->and($html)->toContain('data-message-id="'.$id.'"')
            // ...and the same id anchors a block holding the readable
            // response text the handler shares.
            ->and($html)->toContain('نص الرد القابل للمشاركة');

        $blockPattern = '/<div[^>]*data-message-id="'.preg_quote($id, '/').'"[^>]*>.*?data-full-text/s';

        expect(preg_match($blockPattern, $html))->toBe(1);
    });

    test('sharing keeps the web share api with clipboard fallback and shares text only', function () {
        $client = file_get_contents(resource_path('js/stream-client.js'));

        expect($client)->toContain('navigator.share')
            ->and($client)->toContain('navigator.clipboard');
    });
});
