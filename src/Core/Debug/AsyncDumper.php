<?php

declare(strict_types=1);

namespace Phue\Core\Debug;

use Phue\Core\ResettableInterface;

/**
 * Request-scoped async dumper that collects dumps during request execution
 * and flushes them at the end via middleware.
 *
 * Designed for persistent servers (Swoole, RoadRunner) where var_dump/dd()
 * would break response output or leak memory.
 *
 * @package Phue\Core\Debug
 */
final class AsyncDumper implements ResettableInterface
{
    /** @var array<int, array{value: mixed, label: string, group: string, time: float, trace: array, request_id: string}> */
    private array $dumps = [];

    private int $maxDumps;
    private int $maxDepth;
    private array $redactKeys;
    private float $samplingRate;
    private string $requestId;

    public function __construct(
        int $maxDumps = 50,
        int $maxDepth = 8,
        array $redactKeys = ['password', 'token', 'secret', 'api_key', 'ssn'],
        float $samplingRate = 1.0
    ) {
        $this->maxDumps = $maxDumps;
        $this->maxDepth = $maxDepth;
        $this->redactKeys = $redactKeys;
        $this->samplingRate = $samplingRate;
        $this->requestId = bin2hex(random_bytes(8));
    }

    /**
     * Dumps a value with optional label and group.
     *
     * @param mixed $value Value to dump
     * @param string $label Optional label for identification
     * @param string $group Optional group for categorization
     * @return self Fluent interface
     */
    public function dump(mixed $value, string $label = '', string $group = 'default'): self
    {
        if (count($this->dumps) >= $this->maxDumps) {
            return $this;
        }

        // Sampling
        if ($this->samplingRate < 1.0 && mt_rand() / mt_getrandmax() > $this->samplingRate) {
            return $this;
        }

        $this->dumps[] = [
            'value' => $this->sanitize($value),
            'label' => $label,
            'group' => $group,
            'time' => microtime(true),
            'trace' => $this->getTrace(),
            'request_id' => $this->requestId,
        ];

        return $this;
    }

    /**
     * Conditionally dumps a value.
     */
    public function dumpIf(bool $condition, mixed $value, string $label = '', string $group = 'default'): self
    {
        if ($condition) {
            $this->dump($value, $label, $group);
        }
        return $this;
    }

    /**
     * Dumps a value when a callable returns true.
     */
    public function dumpWhen(callable $condition, mixed $value, string $label = '', string $group = 'default'): self
    {
        if ($condition()) {
            $this->dump($value, $label, $group);
        }
        return $this;
    }

    /**
     * Dumps a value with a sampling rate (0.0 - 1.0).
     */
    public function dumpSample(float $rate, mixed $value, string $label = '', string $group = 'default'): self
    {
        if ($rate > 0 && mt_rand() / mt_getrandmax() <= $rate) {
            $this->dump($value, $label, $group);
        }
        return $this;
    }

    /**
     * Returns all collected dumps.
     *
     * @return array<int, array{value: mixed, label: string, group: string, time: float, trace: array, request_id: string}>
     */
    public function getDumps(): array
    {
        return $this->dumps;
    }

    /**
     * Returns the request ID for correlation.
     */
    public function getRequestId(): string
    {
        return $this->requestId;
    }

    /**
     * Clears all collected dumps.
     */
    public function clear(): void
    {
        $this->dumps = [];
    }

    /**
     * {@inheritdoc}
     */
    public function reset(): void
    {
        $this->dumps = [];
        $this->requestId = bin2hex(random_bytes(8));
    }

    /**
     * Sanitizes a value by redacting sensitive keys.
     */
    private function sanitize(mixed $value, int $depth = 0): mixed
    {
        if ($depth > $this->maxDepth) {
            return '[Max depth reached]';
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $val) {
                $keyStr = (string)$key;
                if ($this->isSensitiveKey($keyStr)) {
                    $result[$keyStr] = '***REDACTED***';
                } else {
                    $result[$keyStr] = $this->sanitize($val, $depth + 1);
                }
            }
            return $result;
        }

        if (is_object($value)) {
            if (method_exists($value, '__debugInfo')) {
                return $this->sanitize($value->__debugInfo(), $depth + 1);
            }

            $reflection = new \ReflectionObject($value);
            $properties = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_PROTECTED);

            $result = ['__class' => $reflection->getName()];
            foreach ($properties as $prop) {
                $prop->setAccessible(true);
                $key = $prop->getName();
                $val = $prop->getValue($value);
                if ($this->isSensitiveKey($key)) {
                    $result[$key] = '***REDACTED***';
                } else {
                    $result[$key] = $this->sanitize($val, $depth + 1);
                }
            }
            return $result;
        }

        return $value;
    }

    /**
     * Checks if a key name is sensitive.
     */
    private function isSensitiveKey(string $key): bool
    {
        $keyLower = strtolower($key);
        foreach ($this->redactKeys as $redactKey) {
            if (str_contains($keyLower, strtolower($redactKey))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Gets a cleaned stack trace.
     */
    private function getTrace(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        $clean = [];

        foreach ($trace as $frame) {
            if (!isset($frame['file'])) {
                continue;
            }

            // Skip framework internal frames
            $file = $frame['file'];
            if (str_contains($file, '/vendor/')
                || str_contains($file, '/src/Core/Debug/')
                || str_contains($file, 'AsyncDumper.php')) {
                continue;
            }

            $clean[] = [
                'file' => $file,
                'line' => $frame['line'] ?? 0,
                'function' => $frame['function'] ?? '',
                'class' => $frame['class'] ?? '',
            ];
        }

        return $clean;
    }
}