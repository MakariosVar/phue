<?php

declare(strict_types=1);

use Phue\Core\Database\Migration;

/**
 * Migration for creating table '{{ TABLE_NAME }}'.
 *
 * @generated Phii Code Generator
 */
final class {{ CLASS_NAME }} extends Migration
{
    public function up(): void
    {
        $this->execute("{{ UP_SQL }}");
    }

    public function down(): void
    {
        $this->execute("{{ DOWN_SQL }}");
    }
}