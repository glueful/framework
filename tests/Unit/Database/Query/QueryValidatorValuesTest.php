<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Query;

use Glueful\Database\Features\QueryValidator;
use PHPUnit\Framework\TestCase;

/**
 * Values reach the database as bound parameters, never as SQL text, so a value cannot inject
 * anything whatever it says. The validator nevertheless refused any value reading like
 * "; delete …" (a CMS import failed on the sentence below) and raised a warning for any value over
 * 64 KB, which the framework's error handler turns into an exception: a long article could not be
 * saved. Values are data; they are no longer inspected as SQL.
 */
final class QueryValidatorValuesTest extends TestCase
{
    public function testProseThatReadsLikeSqlIsAcceptedAsAValue(): void
    {
        $validator = new QueryValidator();
        $values = [
            'body' => 'The entries that would be deleted; delete nothing until you have checked them.',
            'note' => "Run it again; DROP the old table only after the backup; update the docs.",
            'tags' => ['a; insert b', 'c'],
        ];

        $validator->validateInsert('pages', $values);
        $validator->validateUpdate('pages', $values, ['uuid' => 'abc']);

        $this->addToAssertionCount(1);
    }

    public function testALongValueRaisesNoWarning(): void
    {
        $warnings = [];
        set_error_handler(static function (int $no, string $message) use (&$warnings): bool {
            $warnings[] = $message;
            return true;
        });
        try {
            (new QueryValidator())->validateInsert('pages', ['body' => str_repeat('x', 70000)]);
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $warnings);
    }

    public function testAnUpdateOrDeleteWithoutConditionsIsStillRefused(): void
    {
        $validator = new QueryValidator();

        $this->expectException(\InvalidArgumentException::class);
        $validator->validateUpdate('pages', ['body' => 'x'], []);
    }
}
