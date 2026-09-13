{{-- Centralized UI color tokens (single implementation).
     Server is authoritative: values come from UiColorService::effectiveFor()
     (saved user customization or config/ui.php defaults), already validated
     as strict #RRGGBB server-side so they can never inject arbitrary CSS.
     `:root` holds the light-scheme values, `.dark` the dark-scheme values;
     a saved customization uses one value for both schemes. Every layout
     includes this partial, so saved colors survive refresh, navigation,
     Chat/Profile switches and SPA (wire:navigate) loads. --}}
@php
    $uiColors = app(\App\Services\Theme\UiColorService::class)->effectiveFor(auth()->user());
    $uiLight = $uiColors['light'];
    $uiDark = $uiColors['dark'];
@endphp
<style id="ui-colors">:root{--color-primary:{{ $uiLight['primary'] }};--color-primary-text:{{ $uiLight['primary_text'] }};--color-accent:{{ $uiLight['accent'] }};--color-link:{{ $uiLight['link'] }};}.dark{--color-primary:{{ $uiDark['primary'] }};--color-primary-text:{{ $uiDark['primary_text'] }};--color-accent:{{ $uiDark['accent'] }};--color-link:{{ $uiDark['link'] }};}</style>
