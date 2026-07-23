<?php

it('serves the public check-in page for a known tenant host', function () {
    $this->get('http://localhost/')->assertOk();
});

it('returns 404 for an unknown host', function () {
    $this->get('http://unknown-host.example.test/')->assertNotFound();
});

it('returns 404 for the admin login page on an unknown host', function () {
    $this->get('http://unknown-host.example.test/admin/login')->assertNotFound();
});
