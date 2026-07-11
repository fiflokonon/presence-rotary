<?php

it('renders the spinning logo with default sizing', function () {
    $html = (string) $this->blade('<x-loader />');

    expect($html)
        ->toContain('rotary-nexus-logo.png')
        ->toContain('animate-spin')
        ->toContain('h-8 w-8');
});

it('lets callers override the sizing classes', function () {
    $html = (string) $this->blade('<x-loader class="h-16 w-16" />');

    expect($html)
        ->toContain('animate-spin')
        ->toContain('h-16 w-16')
        ->not->toContain('h-8 w-8');
});
