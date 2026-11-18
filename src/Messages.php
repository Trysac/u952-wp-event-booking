<?php

declare(strict_types=1);

namespace Trysac\WpEventBooking;

/**
 * Factory for standard booking notification messages.
 */
final class Messages
{
    /**
     * Builds a booking confirmation message.
     *
     * @param Event $event the booked event
     * @param Booking $booking the confirmed booking
     * @param Attendee $who the attendee receiving the e-mail
     */
    public static function confirmation(Event $event, Booking $booking, Attendee $who): Message
    {
        $subject = 'Booking confirmed: ' . self::sanitizeHeader($event->title);
        $body = sprintf(
            "Hello %s,\n\nYour booking (%s) for %s (%d seat(s)) is confirmed.\n",
            $who->name,
            $booking->id,
            $event->title,
            $booking->seats,
        );

        return new Message($who->email, $subject, $body);
    }

    /**
     * Builds a waitlist notification message.
     *
     * @param Event $event the event
     * @param Booking $booking the waitlisted booking
     * @param Attendee $who the attendee receiving the e-mail
     */
    public static function waitlisted(Event $event, Booking $booking, Attendee $who): Message
    {
        $subject = 'Waitlisted: ' . self::sanitizeHeader($event->title);
        $body = sprintf(
            "Hello %s,\n\nYour booking (%s) for %s (%d seat(s)) is on the waitlist.\n",
            $who->name,
            $booking->id,
            $event->title,
            $booking->seats,
        );

        return new Message($who->email, $subject, $body);
    }

    /**
     * Builds a booking cancellation message.
     *
     * @param Event $event the event
     * @param Booking $booking the canceled booking
     * @param Attendee $who the attendee receiving the e-mail
     */
    public static function canceled(Event $event, Booking $booking, Attendee $who): Message
    {
        $subject = 'Booking canceled: ' . self::sanitizeHeader($event->title);
        $body = sprintf(
            "Hello %s,\n\nYour booking (%s) for %s (%d seat(s)) has been canceled.\n",
            $who->name,
            $booking->id,
            $event->title,
            $booking->seats,
        );

        return new Message($who->email, $subject, $body);
    }

    /**
     * Strips any CR or LF from a header fragment.
     */
    private static function sanitizeHeader(string $value): string
    {
        return str_replace(["\r", "\n"], ' ', $value);
    }
}
