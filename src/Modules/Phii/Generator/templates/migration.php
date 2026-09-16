<?php

declare(strict_types=1);

use Phue\Core\Database\Schema\Blueprint;
use Phue\Core\Database\Migrations\Migration;

/**
 * Migration for creating table '{{ TABLE_NAME }}.
 *
 * @generated Phii Code Generator
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->schema->create('{{ TABLE_NAME }}', function (Blueprint $table) {
            $table->id();
            {{ COLUMNS }}
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $this->schema->dropIfExists('{{ TABLE_NAME }}');
    }
};