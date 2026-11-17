<?php

declare(strict_types=1);

namespace Trysac\WpEventBooking;

/**
 * Outgoing plain-text e-mail message guaranteed to be free of header injection in its recipient and subject.
 */
final readonly class Message
{
    /**
     * Creates a validated e-mail message.
     *
     * @param string $to recipient e-mail address
     * @param string $subject single-line subject without CR or LF
     * @param string $textBody plain-text message body
     *
     * @throws BookingException if recipient or subject contains CR or LF
     */
    public function __construct(
        public string $to,
        public string $subject,
        public string $textBody,
    ) {
        Attendee::validateEmail($this->to);
        if (str_contains($this->subject, "\r") || str_contains($this->subject, "\n")) {
            throw new BookingException('header_injection', 'Message subject must not contain CR or LF.');
        }
    }
}
