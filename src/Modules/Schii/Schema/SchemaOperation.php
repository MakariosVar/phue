<?php

declare(strict_types=1);

namespace Phue\Modules\Schii\Schema;

/**
 * Enumeration of schema mutation operations supported by the diff engine.
 *
 * @package Phue\Modules\Schii\Schema
 */
enum SchemaOperation: string
{
    case CreateTable  = 'create-table';
    case AddColumn    = 'add-column';
    case AlterColumn  = 'alter-column';
    case DropColumn   = 'drop-column';
    case CreateIndex  = 'create-index';
    case DropIndex    = 'drop-index';
}