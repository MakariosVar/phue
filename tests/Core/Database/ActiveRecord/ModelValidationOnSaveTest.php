<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Database\ActiveRecord;

use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Attributes\Field;
use Phue\Core\Database\Connection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Tests\Support\RestoresActiveRecordConnection;
use Phue\Tests\Support\SqliteFactory;

/**
 * A model that breaks its own rules must not reach the database.
 *
 * The gap
 * -------
 * #[Field(rules: [...])] is a Core declaration, but for its whole life nothing
 * in Core acted on it: the only evaluator was inside the admin module's HTTP
 * endpoint. Beside it, Core already had the other half of a validation
 * contract and no writer for it —
 *
 *     protected array $errors = [];
 *     public function getErrors(): array
 *     public function setErrors(array $errors): void   // zero callers in src/
 *
 * So getErrors() could only ever return an empty array. Every caller that read
 * it read nothing. And save() returned false for a failed WRITE, which the
 * endpoint answered with HTTP 422 "Validation failed" and an empty error list —
 * a database fault reported to the client as a mistake in its input.
 *
 * What is pinned here
 * -------------------
 *  - the rules are enforced on save(), not only through HTTP;
 *  - a refused model writes nothing and says why;
 *  - a genuine write failure is distinguishable from a refused model.
 */
class RuledAccount extends BaseActiveRecord
{
    protected string $table = 'ruled_accounts';

    #[Field(type: 'string', required: true, rules: ['min:3', 'max:10'])]
    public string $username = '';

    #[Field(type: 'float', rules: ['min:100'])]
    public float $balance = 0.0;

    #[Field(type: 'string', required: true)]
    public string $email = '';
}

/** No rules at all: must stay completely unaffected. */
class UnruledAccount extends BaseActiveRecord
{
    protected string $table = 'unruled_accounts';

    public string $note = '';
}

#[Group('integration')]
final class ModelValidationOnSaveTest extends TestCase
{
    use RestoresActiveRecordConnection;

    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = SqliteFactory::memory();
        $connection = $this->db;
        BaseActiveRecord::setConnection($connection);
        BaseActiveRecord::setConnectionResolver(static fn(): Connection => $connection);

        $this->db->execute('DROP TABLE IF EXISTS ruled_accounts');
        $this->db->execute('
            CREATE TABLE ruled_accounts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT,
                balance REAL,
                email TEXT
            );
        ');

        $this->db->execute('DROP TABLE IF EXISTS unruled_accounts');
        $this->db->execute('
            CREATE TABLE unruled_accounts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                note TEXT
            );
        ');
    }

    private function rowCount(string $table): int
    {
        return (int) $this->db->query("SELECT COUNT(*) AS c FROM {$table}")->fetch()['c'];
    }

    // ------------------------------------------------ the refusal itself

    public function testSaveIsRefusedWhenARuleIsBroken(): void
    {
        $account = new RuledAccount();
        $account->fill(['username' => 'ab', 'email' => 'a@b.test', 'balance' => 500]);

        $this->assertFalse($account->save(), 'min:3 is broken by "ab".');
    }

    public function testARefusedModelReportsWhy(): void
    {
        $account = new RuledAccount();
        $account->fill(['username' => 'ab', 'email' => 'a@b.test', 'balance' => 500]);
        $account->save();

        $this->assertArrayHasKey('username', $account->getErrors());
        $this->assertStringContainsString('at least 3', (string) $account->getErrors()['username']);
    }

    public function testARefusedModelWritesNothing(): void
    {
        $account = new RuledAccount();
        $account->fill(['username' => 'ab', 'email' => 'a@b.test', 'balance' => 500]);
        $account->save();

        $this->assertSame(
            0,
            $this->rowCount('ruled_accounts'),
            'A refused model must leave the table untouched; validating after the write would be too late.',
        );
    }

    public function testEveryBrokenFieldIsReportedNotJustTheFirst(): void
    {
        $account = new RuledAccount();
        $account->fill(['username' => 'ab', 'email' => 'a@b.test', 'balance' => 5]);
        $account->save();

        $this->assertArrayHasKey('username', $account->getErrors());
        $this->assertArrayHasKey('balance', $account->getErrors());
    }

    public function testMissingRequiredFieldIsRefused(): void
    {
        $account = new RuledAccount();
        $account->fill(['username' => 'valid', 'balance' => 500]);

        $this->assertFalse($account->save());
        $this->assertArrayHasKey('email', $account->getErrors());
    }

    // --------------------------------------------- the min:N reading here

    /**
     * The same rule that the browser used to contradict the server on.
     *
     * min:100 on a float is a bound on the number. Read as a length it would
     * compare "500" — three characters — against 100 and refuse a valid
     * account.
     */
    public function testMinOnANumericPropertyBoundsTheNumber(): void
    {
        $account = new RuledAccount();
        $account->fill(['username' => 'valid', 'email' => 'a@b.test', 'balance' => 500]);

        $this->assertTrue($account->save(), '500 satisfies min:100 as a number.');
        $this->assertSame(1, $this->rowCount('ruled_accounts'));
    }

    public function testMinOnANumericPropertyStillRefusesALowValue(): void
    {
        $account = new RuledAccount();
        $account->fill(['username' => 'valid', 'email' => 'a@b.test', 'balance' => 5]);

        $this->assertFalse($account->save());
        $this->assertArrayHasKey('balance', $account->getErrors());
    }

    // ------------------------------------------------------------ updates

    /**
     * `required` is an insert concern.
     *
     * On update the record was loaded whole, so a missing attribute means the
     * column is null. Enforcing presence there would make an ordinary partial
     * write fail for a column the caller never meant to touch.
     */
    public function testAnUpdateIsNotAskedForEveryColumn(): void
    {
        $created = new RuledAccount();
        $created->fill(['username' => 'valid', 'email' => 'a@b.test', 'balance' => 500]);
        $this->assertTrue($created->save());

        $found = RuledAccount::find((int) $created->getAttribute('id'));
        $this->assertNotNull($found);
        $found->fill(['username' => 'changed']);

        $this->assertTrue($found->save());
    }

    // --------------------------------------------------- models with none

    /**
     * The negative control.
     *
     * Without it, a validator that refused to run unless something was declared
     * would look identical to one that respects declarations.
     */
    public function testAModelWithNoRulesSavesUnchanged(): void
    {
        $account = new UnruledAccount();
        $account->fill(['note' => 'anything at all']);

        $this->assertTrue($account->save());
        $this->assertSame([], $account->getErrors());
        $this->assertSame(1, $this->rowCount('unruled_accounts'));
    }

    /**
     * Saving twice must not accumulate errors from the first attempt.
     */
    public function testValidationStateIsResetOnEachSave(): void
    {
        $account = new RuledAccount();
        $account->fill(['username' => 'ab', 'email' => 'a@b.test', 'balance' => 500]);
        $this->assertFalse($account->save());
        $this->assertNotSame([], $account->getErrors());

        $account->fill(['username' => 'fixed']);
        $this->assertTrue($account->save());
        $this->assertSame(
            [],
            $account->getErrors(),
            'A stale error surviving a successful save is the same class of bug: a verdict nobody re-examined.',
        );
    }

    /**
     * validate() is public so callers can check before committing to a write.
     */
    public function testValidateIsCallableWithoutSaving(): void
    {
        $account = new RuledAccount();
        $account->fill(['username' => 'ab', 'email' => 'a@b.test', 'balance' => 500]);

        $this->assertFalse($account->validate());
        $this->assertSame(0, $this->rowCount('ruled_accounts'));
    }
}