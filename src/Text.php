<?php

declare(strict_types=1);

namespace Trysac\WpEventBooking;

/**
 * Text helpers of this library.
 */
final class Text
{
    /**
     * Trims surrounding whitespace and lower-cases the ASCII letters of the text.
     *
     * @param string $input the text to normalize
     *
     * @return non-empty-string the trimmed, lower-cased text
     *
     * @throws BlankInputException when nothing is left after trimming, instead of returning an empty string
     */
    public static function normalize(string $input): string
    {
        $trimmed = trim($input);
        if ('' === $trimmed) {
            throw new BlankInputException();
        }

        return strtolower($trimmed);
    }
}
