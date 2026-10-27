<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Validation;

use Glueful\Validation\Support\RuleParser;
use Glueful\Validation\Validator;
use PHPUnit\Framework\TestCase;

/**
 * `nullable` and `sometimes` must change how the Validator runs a field's rules.
 */
final class NullableSometimesTest extends TestCase
{
    /** @param array<string, string> $rules */
    private function validator(array $rules): Validator
    {
        return new Validator((new RuleParser())->parse($rules));
    }

    public function testNullableNullValueSkipsRemainingRules(): void
    {
        // Required fails on null, so only an honoured nullable makes this pass.
        $errors = $this->validator(['middle_name' => 'nullable|required|email'])
            ->validate(['middle_name' => null]);

        self::assertSame([], $errors);
    }

    public function testNullableEmptyStringSkipsRemainingRules(): void
    {
        // Email and min:5 both reject '' when they run.
        $errors = $this->validator(['website' => 'nullable|email|min:5'])->validate(['website' => '']);

        self::assertSame([], $errors);
    }

    public function testNullableStillValidatesNonNullValues(): void
    {
        $errors = $this->validator(['middle_name' => 'nullable|email'])
            ->validate(['middle_name' => 'not-an-email']);

        self::assertArrayHasKey('middle_name', $errors);
    }

    public function testRulesBeforeNullableStillRun(): void
    {
        $errors = $this->validator(['name' => 'required|nullable|email'])->validate(['name' => null]);

        self::assertArrayHasKey('name', $errors);
    }

    public function testSometimesAbsentFieldSkipsAllRules(): void
    {
        $validator = $this->validator(['avatar' => 'required|sometimes|url']);

        self::assertSame([], $validator->validate([]));
        self::assertArrayNotHasKey('avatar', $validator->filtered());
    }

    public function testSometimesPresentFieldRunsRules(): void
    {
        $errors = $this->validator(['avatar' => 'sometimes|required|url'])->validate(['avatar' => null]);

        self::assertArrayHasKey('avatar', $errors);
    }
}
