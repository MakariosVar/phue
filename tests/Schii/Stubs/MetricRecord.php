<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Stubs;

use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Modules\Schii\Attribute\Field;

/**
 * Metric stub with writable int/float fields, used to pin the numeric
 * validation rules for normalized 'int'/'float' schema types.
 */
class MetricRecord extends BaseActiveRecord
{
    protected string $table = 'metric_records';

    #[Field(type: 'int', label: 'ID')]
    public ?int $id = null;

    #[Field(type: 'text', label: 'Label', required: true)]
    public string $label = '';

    #[Field(type: 'int', label: 'Score', required: true)]
    public int $score = 0;

    #[Field(type: 'float', label: 'Ratio')]
    public ?float $ratio = null;
}