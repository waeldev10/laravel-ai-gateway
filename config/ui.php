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
    | A saved user customization is a single #RRGGBB value per token that
    | applies to both schemes (see UiColorService). When no customization
    | exists, the scheme-specific defaults below are used, so the current
    | light/dark appearance is unchanged.
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
