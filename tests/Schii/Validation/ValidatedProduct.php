<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Validation;

use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Attributes\Field;
use Phue\Modules\Schii\Attribute\Column;

/**
 * Carries one rule of every kind the engine implements.
 *
 * Shared by the unit and integration halves of the rule-enforcement suite.
 * It lives in Stubs rather than beside either test class because PHP symbols
 * are global once loaded: declaring it twice would be a redeclaration, and
 * PHPUnit only runs the test class whose name matches its file, so the two
 * halves have to be separate files to be two halves at all.
 *
 * The bounds are chosen to separate the two readings of min:N. price is a float
 * with min:1000, so 1200 satisfies it as a NUMBER and fails it as a LENGTH
 * ("1200" is four characters). That single value is the whole divergence:
 * the admin form used to refuse it, the endpoint used to accept it.
 */
class ValidatedProduct extends BaseActiveRecord
{
    protected string $table = 'validated_products';

    #[Field(type: 'text', rules: ['required', 'min:3', 'max:10'])]
    #[Column(label: 'Name', searchable: true)]
    public string $name = '';

    #[Field(type: 'float', rules: ['min:1000'])]
    #[Column(label: 'Price')]
    public float $price = 0.0;

    #[Field(type: 'int', rules: ['integer'])]
    #[Column(label: 'Quantity')]
    public int $quantity = 0;

    #[Field(type: 'email', rules: ['email'])]
    #[Column(label: 'Contact')]
    public string $contact = '';
}