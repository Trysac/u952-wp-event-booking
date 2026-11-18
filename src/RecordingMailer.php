<?php

declare(strict_types=1);

namespace Trysac\WpEventBooking;

/**
 * In-memory mailer that records sent messages for inspection in tests and dry runs.
 */
final class RecordingMailer implements Mailer
{
    /**
     * Maximum number of messages retained in memory.
     */
    public const int MAX_MESSAGES = 10000;

    /**
     * Recorded messages in send order.
     *
     * @var list<Message>
     */
    public array $messages = [];

    /**
     * Creates a recording mailer.
     *
     * @param ?\Throwable $failWith optional exception to throw on every send attempt
     */
    public function __construct(
        private readonly ?\Throwable $failWith = null,
    ) {}

    /**
     * Records the message or throws the configured failure exception.
     *
     * @param Message $m the message to record
     */
    public function send(Message $m): void
    {
        if (null !== $this->failWith) {
            throw $this->failWith;
        }
        if (count($this->messages) >= self::MAX_MESSAGES) {
            array_shift($this->messages);
        }
        $this->messages[] = $m;
    }

    /**
     * Returns the subjects of all recorded messages.
     *
     * @return list<string>
     */
    public function subjects(): array
    {
        return array_map(static fn(Message $m): string => $m->subject, $this->messages);
    }
}
