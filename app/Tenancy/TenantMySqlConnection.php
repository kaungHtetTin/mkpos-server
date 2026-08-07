<?php

namespace App\Tenancy;

use Illuminate\Database\MySqlConnection;

class TenantMySqlConnection extends MySqlConnection
{
    public function query()
    {
        return new TenantBuilder(
            $this,
            $this->getQueryGrammar(),
            $this->getPostProcessor()
        );
    }
}
