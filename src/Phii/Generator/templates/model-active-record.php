<?php

declare(strict_types=1);

namespace {{ NAMESPACE }};

use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Schii\Attribute\Field;

/**
 * ActiveRecord model for {{ MODEL_NAME }}.
 *
 * @generated Phii Code Generator
 */
class {{ MODEL_NAME }} extends BaseActiveRecord
{
    protected string $table = '{{ TABLE_NAME }}';

{{ PROPERTIES }}
}