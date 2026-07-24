<?php

use Illuminate\Support\Facades\Schema;

it('creates session, cache, and queue tables on the central connection, not the tenant connection', function () {
    expect(Schema::connection('central')->hasTable('sessions'))->toBeTrue();
    expect(Schema::connection('central')->hasTable('cache'))->toBeTrue();
    expect(Schema::connection('central')->hasTable('cache_locks'))->toBeTrue();
    expect(Schema::connection('central')->hasTable('jobs'))->toBeTrue();
    expect(Schema::connection('central')->hasTable('job_batches'))->toBeTrue();
    expect(Schema::connection('central')->hasTable('failed_jobs'))->toBeTrue();

    expect(Schema::hasTable('sessions'))->toBeFalse();
    expect(Schema::hasTable('cache'))->toBeFalse();
    expect(Schema::hasTable('jobs'))->toBeFalse();
    expect(Schema::hasTable('users'))->toBeTrue();
});

it('points the session, cache, and queue database connections at central', function () {
    expect(config('session.connection'))->toBe('central');
    expect(config('cache.stores.database.connection'))->toBe('central');
    expect(config('queue.connections.database.connection'))->toBe('central');
});
