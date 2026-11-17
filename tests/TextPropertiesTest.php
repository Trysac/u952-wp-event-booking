<?php

declare(strict_types=1);

namespace Trysac\WpEventBooking\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Trysac\WpEventBooking\Text;

/**
 * Property tests: invariants of Text::normalize() over generated inputs (a fixed seed keeps them reproducible).
 *
 * @internal
 */
final class TextPropertiesTest extends TestCase
{
    private const int SEED = 20_260_101;
    private const int CASES = 200;
    private const string ALPHABET = " \t\n\rabcXYZ019-_.~é";

    /**
     * Non-blank strings of 1 to 40 characters drawn from a mixed alphabet.
     *
     * @return iterable<string, array{string}>
     */
    public static function inputs(): iterable
    {
        $random = new Randomizer(new Mt19937(self::SEED));
        for ($case = 0; $case < self::CASES; ++$case) {
            $input = $random->getBytesFromString(self::ALPHABET, $random->getInt(1, 40));
            if ('' !== trim($input)) {
                yield "case {$case}" => [$input];
            }
        }
    }

    #[DataProvider('inputs')]
    public function testNormalizingTwiceChangesNothing(string $input): void
    {
        $once = Text::normalize($input);
        self::assertSame($once, Text::normalize($once));
    }

    #[DataProvider('inputs')]
    public function testOutputHasNoSurroundingWhitespace(string $input): void
    {
        $output = Text::normalize($input);
        self::assertSame(trim($output), $output);
    }
}
