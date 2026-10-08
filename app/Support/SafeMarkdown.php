<?php

namespace App\Support;

use League\CommonMark\GithubFlavoredMarkdownConverter;

class SafeMarkdown
{
    private static ?GithubFlavoredMarkdownConverter $converter = null;

    /**
     * Render untrusted AI output as safe HTML: full Markdown (paragraphs,
     * headings, lists, tables, fenced code, inline code, emphasis, links)
     * with raw HTML escaped and dangerous links/attributes stripped.
     *
     * Treat model output as untrusted content: no `<script>`, no event
     * handlers, no `javascript:` URLs — ever.
     */
    public static function render(string $text): string
    {
        $html = (string) self::converter()->convert($text);

        return self::sanitize($html);
    }

    private static function converter(): GithubFlavoredMarkdownConverter
    {
        if (self::$converter === null) {
            self::$converter = new GithubFlavoredMarkdownConverter([
                'html_input' => 'escape',
                'allow_unsafe_links' => false,
            ]);
        }

        return self::$converter;
    }

    /**
     * Defense-in-depth cleanup of converter output: drop executable
     * elements, event-handler/style attributes, and non-http(s)/mailto
     * link targets. The converter is already configured to escape raw
     * HTML and unsafe links; this removes anything that slips through.
     */
    private static function sanitize(string $html): string
    {
        $html = (string) preg_replace(
            '#<\s*(script|style|iframe|object|embed|form|input|button|select|textarea|link|meta|base|noscript)[^>]*>.*?<\s*/\s*\1\s*>#is',
            '',
            $html
        );

        $html = (string) preg_replace(
            '#<\s*(script|style|iframe|object|embed|form|input|button|select|textarea|link|meta|base|noscript)[^>]*/?>#i',
            '',
            $html
        );

        // Event handlers (onclick=...) and inline styles cannot survive.
        $html = (string) preg_replace('#\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);
        $html = (string) preg_replace('#\s+style\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);

        // Links: only http(s) and mailto targets keep their href; anything
        // else (javascript:, data:, vbscript:) loses it but keeps its text.
        $html = (string) preg_replace_callback(
            '#<a\s+([^>]*?)>#i',
            function (array $matches): string {
                $attrs = $matches[1];

                if (! preg_match('#href\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))#i', $attrs, $href)) {
                    return '<a>';
                }

                $url = trim($href[2] ?? $href[3] ?? $href[4] ?? '');

                if (! preg_match('#^(https?://|mailto:)#i', $url)) {
                    $attrs = (string) preg_replace('#href\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $attrs);
                }

                $attrs = trim((string) preg_replace('/\s+/', ' ', $attrs));

                return $attrs === '' ? '<a>' : "<a {$attrs}>";
            },
            $html
        );

        return $html;
    }
}
