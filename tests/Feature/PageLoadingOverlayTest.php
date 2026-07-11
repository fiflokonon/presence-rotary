<?php

it('includes the page loading overlay on the public attendance layout', function () {
    $this->get(route('attendance.show'))
        ->assertOk()
        ->assertSee('pageLoading', false)
        ->assertSee('ife-logo.png', false);
});
