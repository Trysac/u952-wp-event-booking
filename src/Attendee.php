<?php

declare(strict_types=1);

namespace Trysac\WpEventBooking;

/**
 * Immutable attendee contact details validated on construction.
 */
final readonly class Attendee
{
    /**
     * Maximum allowed length for an attendee name.
     */
    public const int MAX_NAME_LENGTH = 200;

    /**
     * Maximum allowed length for an e-mail address.
     */
    public const int MAX_EMAIL_LENGTH = 254;

    /**
     * Creates and validates attendee details.
     *
     * @param string $name attendee full name
     * @param string $email attendee e-mail address
     *
     * @throws BookingException on header injection attempts, invalid name, or invalid e-mail
     */
    public function __construct(
        public string $name,
        public string $email,
    ) {
        self::validateName($this->name);
        self::validateEmail($this->email);
    }

    /**
     * Validates an attendee name against header injection, control characters, and length bounds.
     *
     * @param string $name candidate name
     *
     * @throws BookingException if the name is invalid
     */
    public static function validateName(string $name): void
    {
        if (str_contains($name, "\r") || str_contains($name, "\n")) {
            throw new BookingException('header_injection', 'Attendee name contains forbidden CR or LF characters.');
        }
        $len = mb_strlen($name, 'UTF-8');
        if ($len < 1 || $len > self::MAX_NAME_LENGTH) {
            throw new BookingException('invalid_name', 'Attendee name must be between 1 and 200 characters.');
        }
        if (1 === preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new BookingException('invalid_name', 'Attendee name must not contain control characters.');
        }
    }

    /**
     * Validates an attendee e-mail address.
     *
     * @param string $email candidate e-mail
     *
     * @throws BookingException if the e-mail is invalid or contains CR/LF
     */
    public static function validateEmail(string $email): void
    {
        if (str_contains($email, "\r") || str_contains($email, "\n")) {
            throw new BookingException('header_injection', 'Attendee email contains forbidden CR or LF characters.');
        }
        if ('' === $email || strlen($email) > self::MAX_EMAIL_LENGTH) {
            throw new BookingException('invalid_email', 'Attendee email exceeds length limits or is empty.');
        }
        if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new BookingException('invalid_email', 'Attendee email address is not valid.');
        }
    }
}
