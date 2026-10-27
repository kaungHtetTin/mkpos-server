<?php

namespace App\Support;

use GuzzleHttp\Exception\ConnectException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class AiFailureDiagnostic
{
    /** Never include exception messages, SQL, provider bodies or conversation data. */
    public static function context(\Throwable $error): array
    {
        $context = ['exception_class' => get_class($error)];
        $file = str_replace('\\', '/', $error->getFile());
        $root = rtrim(str_replace('\\', '/', base_path()), '/').'/';
        if (str_starts_with($file, $root)) {
            $context['source'] = substr($file, strlen($root));
            $context['line'] = $error->getLine();
        }
        if ($error instanceof HttpExceptionInterface) {
            $context['http_status'] = $error->getStatusCode();
        }
        // These PHP diagnostics contain identifiers only, never arbitrary error text.
        if ($error instanceof \Error && preg_match('/^(Class|Interface|Trait) "([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)" not found$/D', $error->getMessage(), $match)) {
            $context['failure_kind'] = 'missing_class';
            $context['symbol'] = $match[2];
        } elseif ($error instanceof \Error && preg_match('/^Undefined constant ([A-Za-z_\\\\][A-Za-z0-9_\\\\]*(?:::[A-Za-z_][A-Za-z0-9_]*)?)$/D', $error->getMessage(), $match)) {
            $context['failure_kind'] = 'missing_constant';
            $context['symbol'] = $match[1];
        }
        for ($cause = $error; $cause; $cause = $cause->getPrevious()) {
            if ($cause instanceof ConnectException) {
                $context['connection_error_code'] = (int) ($cause->getHandlerContext()['errno'] ?? 0);
                break;
            }
        }

        return $context;
    }
}
