<?php

it('redirects the root according to authentication state', function () {
    $this->get('/')->assertRedirect(route('login'));
});
