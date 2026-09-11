<?php

declare(strict_types=1);

namespace Phue\Core;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;
use Throwable;

/**
 * Enterprise Lightweight Fail-Safe PSR-3 File Logger.
 *
 * Implements a zero-dependency PSR-3 compliant logging engine with RFC 5424 priority filtering,
 * strict context placeholder interpolation, exception stack trace formatting, and fail-safe file persistence.
 *
 * @package Phue\Core
 */
final class Logger extends AbstractLogger
{
    /**
     * Map of RFC 5424 Log Levels to integer priority hierarchy.
     * Lower numeric value indicates higher priority severity.
     *
     * @var array<string, int>
     */
    private const LOG_LEVEL_PRIORITY = [
        LogLevel::EMERGENCY => 0,
        LogLevel::ALERT     => 1,
        LogLevel::CRITICAL  => 2,
        LogLevel::ERROR     => 3,
        LogLevel::WARNING   => 4,
        LogLevel::NOTICE    => 5,
        LogLevel::INFO      => 6,
        LogLevel::DEBUG     => 7,
    ];

    /**
     * Initialize the Logger instance with a target log file path and minimum severity threshold.
     *
     * @param string $logFile Absolute or relative path to the destination log file.
     * @param string $minLevel Minimum PSR-3 LogLevel threshold required to process write operations. Defaults to LogLevel::DEBUG.
     */
    public function __construct(
        private readonly string $logFile,
        private readonly string $minLevel = LogLevel::DEBUG
    ) {}

    /**
     * Logs with an arbitrary level, interpolates context variables, and safely appends to storage.
     *
     * Adheres to PSR-3 fail-safe requirements; internal IO errors or runtime exceptions
     * will never bubble up to the caller.
     *
     * @param mixed $level RFC 5424 severity level string or custom level representation.
     * @param string|Stringable $message Log message with optional string placeholders delimited by braces `{key}`.
     * @param array<string, mixed> $context Contextual data payload mapped to message placeholders or exception traces.
     *
     * @return void
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $levelString = (string) $level;

        if (!$this->shouldLog($levelString)) {
            return;
        }

        try {
            $logDir = dirname($this->logFile);
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0777, true);
            }

            $formattedMessage = $this->interpolate((string) $message, $context);
            $timestamp = date('Y-m-d H:i:s');
            $logEntry = sprintf("[%s] %s: %s", $timestamp, strtoupper($levelString), $formattedMessage);

            if (isset($context['exception']) && $context['exception'] instanceof Throwable) {
                $e = $context['exception'];
                $logEntry .= sprintf(
                    " | Exception: %s: %s in %s:%d\nStack trace:\n%s",
                    get_class($e),
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine(),
                    $e->getTraceAsString()
                );
            }

            $logEntry .= PHP_EOL;

            // Fail-safe file writing using error suppression operator (@)
            @file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // Fail-safe requirement: PSR-3 logger must never throw exceptions
        }
    }

    /**
     * Evaluates whether the requested log level meets or exceeds the configured minimum threshold.
     *
     * @param string $level Severity level to test.
     *
     * @return bool True if the log entry priority allows writing, false otherwise.
     */
    private function shouldLog(string $level): bool
    {
        $currentPriority = self::LOG_LEVEL_PRIORITY[$level] ?? 7;
        $minPriority = self::LOG_LEVEL_PRIORITY[$this->minLevel] ?? 7;

        return $currentPriority <= $minPriority;
    }

    /**
     * Replaces placeholder tags `{key}` in the log message string with matching values from the context array.
     *
     * Strictly follows PSR-3 specification guidelines regarding scalar values, Stringable objects,
     * generic objects, and exception key exclusions.
     *
     * @param string $message Raw log message template containing `{key}` placeholders.
     * @param array<string, mixed> $context Data dictionary used for placeholder replacement.
     *
     * @return string Interpolated string with replaced variables.
     */
    private function interpolate(string $message, array $context): string
    {
        $replace = [];
        foreach ($context as $key => $val) {
            if ($key === 'exception') {
                continue;
            }
            if ($val === null || is_scalar($val) || (is_object($val) && method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = (string) $val;
            } elseif (is_object($val)) {
                $replace['{' . $key . '}'] = '[object ' . get_class($val) . ']';
            } else {
                $replace['{' . $key . '}'] = '[' . gettype($val) . ']';
            }
        }

        return strtr($message, $replace);
    }
}