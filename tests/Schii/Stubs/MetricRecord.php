<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Stubs;

use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Attributes\Field;
use Phue\Modules\Schii\Attribute\Column;

/**
 * Metric stub with writable int/float fields, used to pin the numeric
 * validation rules for normalized 'int'/'float' schema types.
 */
class MetricRecord extends BaseActiveRecord
{
    protected string $table = 'metric_records';

    #[Field(type: 'int')]
    #[Column(label: 'ID')]
    public ?int $id = null;

    #[Field(type: 'text', required: true)]
    #[Column(label: 'Label')]
    public string $label = '';

    #[Field(type: 'int', required: true)]
    #[Column(label: 'Score')]
    public int $score = 0;

    #[Field(type: 'float')]
    #[Column(label: 'Ratio')]
    public ?float $ratio = null;
}