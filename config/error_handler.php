<?php
/** Small, argument-free diagnostics. Never log exception messages or request data:
 * PDO/SMTP/filesystem messages can contain bound values, credentials or tokens. */
function gsoLogApplicationException(Throwable $error, string $operation): string
{
    $reference = 'GSOERR-' . bin2hex(random_bytes(6));
    $frames = [];
    foreach (array_slice($error->getTrace(), 0, 12) as $frame) {
        $frames[] = [
            'file' => (string) ($frame['file'] ?? ''),
            'line' => (int) ($frame['line'] ?? 0),
            'call' => (string) ($frame['class'] ?? '') . (string) ($frame['type'] ?? '') . (string) ($frame['function'] ?? ''),
        ];
    }
    $diagnostic = [
        'reference' => $reference,
        'operation' => substr(preg_replace('/[^a-zA-Z0-9 _.-]/', '', $operation), 0, 100),
        'exception' => get_class($error),
        'file' => $error->getFile(),
        'line' => $error->getLine(),
        'trace' => $frames,
    ];
    // Whitelisted diagnostic codes, never driver message/errorInfo[2].
    $code = (string) $error->getCode();
    if (preg_match('/\A[A-Z0-9]{5}\z/', $code)) $diagnostic['sqlstate'] = $code;
    elseif (preg_match('/\A[0-9]{1,10}\z/', $code)) $diagnostic['code'] = (int) $code;
    if ($error instanceof PDOException && isset($error->errorInfo[0])
        && preg_match('/\A[A-Z0-9]{5}\z/', (string) $error->errorInfo[0])) {
        $diagnostic['sqlstate'] = $error->errorInfo[0];
        if (isset($error->errorInfo[1]) && is_numeric($error->errorInfo[1])) $diagnostic['driver_code'] = (int) $error->errorInfo[1];
    }
    error_log('GSO application diagnostic ' . json_encode($diagnostic, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    return $reference;
}

function gsoSafeApplicationError(Throwable $error, string $operation, string $fallback = 'Unable to process the request right now. Please try again.'): string
{
    // DomainExceptions are deliberately authored business validation. Technical
    // failures must never be reclassified as DomainExceptions by a caller.
    if ($error instanceof DomainException) {
        $message = $error->getMessage();
        if ($message !== '' && strlen($message) <= 2000
            && !preg_match('~SQLSTATE|PDOException|stack trace|(?:[A-Za-z]:[\\\\/]|/(?:var|srv|home|tmp|etc|Users)/)|\b(?:password|token|secret)\s*[=:]|[\r\n]~i', $message)) {
            return $message;
        }
    }
    $reference = gsoLogApplicationException($error, $operation);
    return $fallback . ' Reference: ' . $reference . '.';
}

function gsoProductionEnvironment(): bool
{
    return in_array(strtolower(trim((string) gsoEnv('GSO_APP_ENV', 'production'))), ['production', 'prod', 'staging'], true);
}

function gsoInstallSafeErrorHandlers(): void
{
    set_exception_handler(static function (Throwable $error): void {
        $reference = gsoLogApplicationException($error, 'uncaught application failure');
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, 'Application operation failed. Reference: ' . $reference . PHP_EOL);
        } else {
            if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=UTF-8'); }
            echo 'The system is temporarily unavailable. Please try again. Reference: ' . $reference . '.';
        }
        exit(1);
    });
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) return true;
        gsoLogApplicationException(new ErrorException('Runtime warning', 0, $severity, $file, $line), 'runtime warning');
        return true; // Never print original warning text, even with display_errors=On.
    });
    register_shutdown_function(static function (): void {
        $last = error_get_last();
        if (!$last || !in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
        $reference = gsoLogApplicationException(new ErrorException('Fatal runtime failure', 0, $last['type'], $last['file'], $last['line']), 'fatal application failure');
        if (PHP_SAPI !== 'cli') {
            if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=UTF-8'); }
            echo 'The system is temporarily unavailable. Reference: ' . $reference . '.';
        }
    });
}
