<?php

namespace Tests\Support;

use App\Tenancy\TenantBuilder;
use Illuminate\Database\SQLiteConnection;

/** Exercise the same implicit POS tenant scope as the production connection. */
class TenantSqliteConnection extends SQLiteConnection
{
    public function query()
    {
        return new TenantBuilder($this, $this->getQueryGrammar(), $this->getPostProcessor());
    }
}
