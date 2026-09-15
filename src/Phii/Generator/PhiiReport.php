<?php

declare(strict_types=1);

namespace Phue\Phii\Generator;

final class PhiiReport
{
    private int $written;
    private int $skipped;
    private int $conflicts;
    private ?string $backupPath;

    /**
     * PhiiReport constructor.
     *
     * @param int         $written
     * @param int         $skipped
     * @param int         $conflicts
     * @param string|null $backupPath
     */
    public function __construct(int $written = 0, int $skipped = 0, int $conflicts = 0, ?string $backupPath = null)
    {
        $this->written = $written;
        $this->skipped = $skipped;
        $this->conflicts = $conflicts;
        $this->backupPath = $backupPath;
    }

    /**
     * Convert the report structure to an associative array.
     *
     * @return array{written: int, skipped: int, conflicts: int, backup: string|null}
     */
    public function toArray(): array
    {
        return [
            'written' => $this->written,
            'skipped' => $this->skipped,
            'conflicts' => $this->conflicts,
            'backup' => $this->backupPath,
        ];
    }
}