<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

final class DatabaseAvailability
{
    public static function unavailable(Throwable $error): bool
    {
        if (! $error instanceof QueryException && ! $error instanceof PDOException) {
            return false;
        }

        do {
            if ($error instanceof PDOException) {
                $state = (string) ($error->errorInfo[0] ?? $error->getCode());
                $driverCode = (int) ($error->errorInfo[1] ?? $error->getCode());
                if (str_starts_with($state, '08') || in_array($driverCode, [1040, 1203, 1226, 2002, 2003, 2006, 2013, 2055], true)) {
                    return true;
                }
            }
        } while ($error = $error->getPrevious());

        return false;
    }
}
