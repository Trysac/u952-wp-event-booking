<?php

declare(strict_types=1);

namespace Trysac\WpEventBooking\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trysac\WpEventBooking\BlankInputException;
use Trysac\WpEventBooking\Text;

/**
 * Tests of the public API.
 *
 * @internal
 */
final class TextTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function examples(): iterable
    {
        yield 'surrounding spaces' => ['  Hello World ', 'hello world'];

        yield 'upper case' => ['ABC', 'abc'];

        yield 'tabs and newlines' => ["\tx\n", 'x'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blanks(): iterable
    {
        yield 'empty' => [''];

        yield 'spaces' => ['   '];

        yield 'tab and newline' => ["\t\n"];
    }

    #[DataProvider('examples')]
    public function testTrimsAndLowercases(string $input, string $want): void
    {
        self::assertSame($want, Text::normalize($input));
    }

    #[DataProvider('blanks')]
    public function testRejectsBlankInput(string $input): void
    {
        $this->expectException(BlankInputException::class);
        $this->expectExceptionMessage('input is blank');
        Text::normalize($input);
    }

    public function testTheExceptionKeepsItsMessageAndCause(): void
    {
        $cause = new \RuntimeException('cause');
        $error = new BlankInputException('custom', $cause);
        self::assertSame('custom', $error->getMessage());
        self::assertSame($cause, $error->getPrevious());
        self::assertSame(0, $error->getCode());
    }
}
