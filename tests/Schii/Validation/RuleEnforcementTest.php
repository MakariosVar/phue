<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Validation;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Attributes\Field;
use Phue\Modules\Schii\Schema\SchemaRegistry;

/**
 * Carries a rule the engine does not implement.
 */
class UnenforceableProduct extends BaseActiveRecord
{
    protected string $table = 'unenforceable_products';

    #[Field(type: 'text', rules: ['min:3', 'unique'])]
    public string $sku = '';
}

/**
 * RuleEngine is wired into the request path, not merely present.
 *
 * RuleEngineTest pins what a rule MEANS. This file pins that the schema
 * refuses a declaration the engine cannot honour — the part that was actually
 * broken.
 *
 * Before it, `unique` on a field was accepted, matched no branch in either
 * evaluation loop, and stored a row that violated it. The author had written a
 * test for it; that test passed too, because it exercised the same silent
 * skip. A constraint that is neither enforced nor reported is the most
 * expensive kind of bug, and the only cure is for the framework to refuse the
 * declaration it cannot perform.
 *
 * The rules that ARE performed are exercised end-to-end by
 * RuleEnforcementEndpointTest, which is a separate file because PHPUnit runs
 * the class whose name matches the filename — a second test class in the same
 * file is loaded and then never executed.
 *
 * @see \Phue\Tests\Core\Validation\RuleEngineTest
 */
#[Group('unit')]
final class RuleEnforcementTest extends TestCase
{
    public function testSchemaBuildRefusesARuleTheEngineCannotPerform(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/Unknown rule 'unique'/");

        (new SchemaRegistry())->get(UnenforceableProduct::class);
    }

    public function testTheRefusalNamesTheOffendingModelAndProperty(): void
    {
        try {
            (new SchemaRegistry())->get(UnenforceableProduct::class);
            $this->fail('An unimplementable rule must stop the schema from building.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('UnenforceableProduct', $e->getMessage());
            $this->assertStringContainsString('$sku', $e->getMessage());
        }
    }

    public function testKnownRulesStillBuildTheirSchema(): void
    {
        $schema = (new SchemaRegistry())->get(ValidatedProduct::class);

        $this->assertSame(['required', 'min:3', 'max:10'], $schema->fields['name']->rules);
        $this->assertSame(['min:1000'], $schema->fields['price']->rules);
    }

    public function testTheRefusalHappensOnSchemaBuildRatherThanOnFirstRequest(): void
    {
        // Asserted as ordering: a rule refused per request would surface as a
        // 500 in production and as nothing at all in a test that never calls
        // the endpoint. Building the schema is the earliest point at which the
        // declaration can still be attributed to a model.
        $registry = new SchemaRegistry();

        $this->expectException(InvalidArgumentException::class);
        $registry->get(UnenforceableProduct::class);
    }
}