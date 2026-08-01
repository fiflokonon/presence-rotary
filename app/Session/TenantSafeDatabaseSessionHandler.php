<?php

namespace App\Session;

use Illuminate\Session\DatabaseSessionHandler;

class TenantSafeDatabaseSessionHandler extends DatabaseSessionHandler
{
    /**
     * The sessions table lives on the "central" connection, independent of
     * whichever tenant is active, but resolving the authenticated user to
     * populate this optional metadata column requires the per-tenant
     * "sqlite" connection. That connection is only guaranteed to be correct
     * for the current request's own duration, not for this handler's
     * lifetime, so skip it: this app never reads sessions.user_id.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function addUserInformation(&$payload)
    {
        return $this;
    }
}
