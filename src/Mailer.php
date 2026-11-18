<?php

declare(strict_types=1);

namespace Trysac\WpEventBooking;

/**
 * Outbound e-mail transport contract.
 */
interface Mailer
{
    /**
     * Dispatches a single e-mail message.
     *
     * @param Message $m the message to send
     */
    public function send(Message $m): void;
}
