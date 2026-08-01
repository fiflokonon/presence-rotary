<?php

use App\Models\User;
use App\Session\TenantSafeDatabaseSessionHandler;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;

it('is the handler bound for the database session driver', function () {
    config(['session.driver' => 'database']);

    expect(app('session')->getHandler())->toBeInstanceOf(TenantSafeDatabaseSessionHandler::class);
});

it('persists a session for an authenticated user without touching the tenant connection', function () {
    config(['session.driver' => 'database']);

    $user = User::factory()->create(['password' => 'secret12345']);

    $this->post(route('admin.login'), [
        'email' => $user->email,
        'password' => 'secret12345',
    ])->assertRedirect(route('admin.dashboard'));

    $sessionId = session()->getId();
    $originalPath = config('database.connections.sqlite.database');

    // A real subsequent request resolves a brand new, uncached guard that
    // must re-hydrate the user from the session id to persist it. Both the
    // AuthManager's own guard cache and the container's separate
    // "auth.driver" singleton (what DatabaseSessionHandler resolves) need
    // clearing to simulate that.
    app('auth')->forgetGuards();
    app()->forgetInstance('auth.driver');

    // Reproduce production's exact failure mode: the per-tenant "sqlite"
    // connection pointing at a file that does not exist.
    config(['database.connections.sqlite.database' => '/nonexistent/does-not-exist.sqlite']);
    DB::purge('sqlite');

    $handler = app('session')->getHandler();

    $writeError = null;
    try {
        $handler->write($sessionId, '{"_token":"test"}');
    } catch (Throwable $e) {
        $writeError = $e->getMessage();
    }

    expect($writeError)->toBeNull();

    // Restore the connection so RefreshDatabase's own teardown (which rolls
    // back the sqlite in-memory connection) doesn't hit the broken path too.
    config(['database.connections.sqlite.database' => $originalPath]);
    DB::purge('sqlite');
    unset(RefreshDatabaseState::$inMemoryConnections['sqlite']);
    RefreshDatabaseState::$migrated = false;
});
