<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('chat frontend wiring', function () {
    test('the shared stream client exports the api consumers use', function () {
        $source = file_get_contents(resource_path('js/stream-client.js'));

        foreach ([
            'chatPhase',
            'setChatPhase',
            'abortActiveGeneration',
            'setComposerStreamingUI',
            'toast',
            'scrollContainer',
            'streamSlot',
            'conversationId',
            'nearBottom',
            'scrollToBottom',
            'buildUserNode',
            'buildAssistantNode',
            'adoptStreamNodes',
            'refreshMessageList',
            'httpErrorMessage',
            'postEventStream',
            'shareResponse',
            'setStreamingText',
        ] as $name) {
            // Declared and exported (plain or async) so bundlers keep the
            // implementation instead of tree-shaking the module away.
            $exported = str_contains($source, "export function {$name}(")
                || str_contains($source, "export async function {$name}(");

            expect($exported)->toBeTrue("expected {$name} to be exported from stream-client.js");
        }
    });

    test('the composer imports its streaming api instead of relying on globals', function () {
        $source = file_get_contents(resource_path('js/chat-stream.js'));

        expect($source)->toContain("from './stream-client.js'");

        foreach ([
            'abortActiveGeneration',
            'adoptStreamNodes',
            'buildAssistantNode',
            'buildUserNode',
            'chatPhase',
            'nearBottom',
            'postEventStream',
            'refreshMessageList',
            'scrollContainer',
            'scrollToBottom',
            'setChatPhase',
            'setStreamingText',
            'streamSlot',
            'toast',
        ] as $name) {
            // Once in the import block and at least once at a call site, so
            // bundlers keep the implementation instead of tree-shaking it.
            expect(substr_count($source, $name))->toBeGreaterThanOrEqual(2, "expected {$name} to be imported and used");
        }
    });

    test('the message actions import their streaming api instead of relying on globals', function () {
        $source = file_get_contents(resource_path('js/message-actions.js'));

        expect($source)->toContain("from './stream-client.js'");

        foreach ([
            'adoptStreamNodes',
            'buildAssistantNode',
            'chatPhase',
            'conversationId',
            'nearBottom',
            'postEventStream',
            'refreshMessageList',
            'scrollContainer',
            'scrollToBottom',
            'setChatPhase',
            'setComposerStreamingUI',
            'setStreamingText',
            'shareResponse',
            'streamSlot',
            'toast',
        ] as $name) {
            expect(substr_count($source, $name))->toBeGreaterThanOrEqual(2, "expected {$name} to be imported and used");
        }
    });

    test('the edit-follow listener is on window where livewire dispatches browser events', function () {
        $source = file_get_contents(resource_path('js/message-actions.js'));

        expect($source)->toContain("window.addEventListener('close-edit-message'")
            ->and($source)->not()->toContain("document.addEventListener('close-edit-message'");
    });

    test('stream handoff adopts optimistic nodes into the list instead of clearing them later', function () {
        $client = file_get_contents(resource_path('js/stream-client.js'));
        $composer = file_get_contents(resource_path('js/chat-stream.js'));
        $actions = file_get_contents(resource_path('js/message-actions.js'));

        // Single ownership: on completion the optimistic nodes move
        // into the persisted list BEFORE the refresh, so the morph
        // reconciles them in place. No second copy is ever inserted,
        // and no timer, observer, or post-hoc cleanup is involved.
        expect($client)->toContain('export function adoptStreamNodes(')
            ->and($client)->toContain('[data-message-list-root]')
            ->and($composer)->toContain('adoptStreamNodes()')
            ->and($actions)->toContain('adoptStreamNodes()');

        // No timer-driven reconciliation and no duplicate sweeping in
        // any of the stream flows: completion handling is synchronous.
        // (`livewire:updated` is not a Livewire v4 document event.)
        foreach (['stream-client.js', 'chat-stream.js', 'message-actions.js'] as $file) {
            $source = file_get_contents(resource_path("js/{$file}"));

            expect($source)->not()->toContain('setTimeout')
                ->and($source)->not()->toContain('MutationObserver')
                ->and($source)->not()->toContain('clearSlotAfterListRefresh')
                ->and($source)->not()->toContain("addEventListener('livewire:updated'");
        }
    });

    test('chat transitions never force a full browser reload', function () {
        $composer = file_get_contents(resource_path('js/chat-stream.js'));

        // SPA navigation keeps layout/sidebar/theme mounted (no flash);
        // the plain reload exists exactly once as the no-Livewire fallback.
        // There is also no `livewire:load` document event in Livewire v4.
        expect($composer)->toContain('Livewire.navigate')
            ->and(substr_count($composer, 'window.location.href'))->toBe(1)
            ->and($composer)->not()->toContain("addEventListener('livewire:load'");
    });

    test('every fetch streaming call site shares one abort pointer and one stop control', function () {
        $client = file_get_contents(resource_path('js/stream-client.js'));
        $composer = file_get_contents(resource_path('js/chat-stream.js'));
        $actions = file_get_contents(resource_path('js/message-actions.js'));

        expect($client)->toContain('window.__abortActiveGeneration')
            ->and($composer)->toContain('abortActiveGeneration()')
            ->and($actions)->toContain('window.__abortActiveGeneration =');
    });
});
