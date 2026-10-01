<?php

return [
    /*
    |--------------------------------------------------------------------------
    | UI accent colors
    |--------------------------------------------------------------------------
    |
    | Single authoritative definition of the default UI color tokens. These
    | defaults preserve the existing black/white identity per color scheme:
    | light uses dark-on-light values, dark uses light-on-dark values.
    |
    | Customizations are browser-local (localStorage key `ui_colors`, one
    | strict #RRGGBB value per token, validated before use) and apply to
    | both schemes, exactly like the light/dark theme preference. When no
    | customization exists, the scheme-specific defaults below are used, so
    | the current light/dark appearance is unchanged. Laravel never stores
    | or reads custom palettes: no database column, no cookies, no requests.
    |
    */

    'colors' => [
        'tokens' => ['primary', 'primary_text', 'accent', 'link'],

        'defaults' => [
            'light' => [
                'primary' => '#000000',
                'primary_text' => '#ffffff',
                'accent' => '#000000',
                'link' => '#000000',
            ],
            'dark' => [
                'primary' => '#ffffff',
                'primary_text' => '#000000',
                'accent' => '#ffffff',
                'link' => '#ffffff',
            ],
        ],
    ],
];
