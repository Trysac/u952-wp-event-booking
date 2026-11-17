<?php

declare(strict_types=1);

namespace Trysac\WpEventBooking\Tests;

use PHPUnit\Framework\TestCase;
use Trysac\WpEventBooking\Attendee;
use Trysac\WpEventBooking\Booking;
use Trysac\WpEventBooking\BookingException;
use Trysac\WpEventBooking\BookingService;
use Trysac\WpEventBooking\Cli;
use Trysac\WpEventBooking\Event;
use Trysac\WpEventBooking\FrozenClock;
use Trysac\WpEventBooking\InMemoryStore;
use Trysac\WpEventBooking\Message;
use Trysac\WpEventBooking\Messages;
use Trysac\WpEventBooking\PdoStore;
use Trysac\WpEventBooking\RecordingMailer;
use Trysac\WpEventBooking\SequentialIds;

/**
 * Acceptance and edge-case tests for the event booking core.
 *
 * @internal
 */
final class BookingServiceTest extends TestCase
{
    public function testLaunchPartyCapacityWaitlistAndPromotion(): void
    {
        $store = new InMemoryStore();
        $mailer = new RecordingMailer();
        $clock = new FrozenClock(new \DateTimeImmutable('2025-06-01T12:00:00Z'));
        $ids = new SequentialIds();
        $service = new BookingService($store, $mailer, $clock, $ids);

        $event = new Event(
            'launch',
            'Launch party',
            new \DateTimeImmutable('2025-06-02T18:00:00Z'),
            2,
        );
        $store->createEvent($event);

        $r1 = $service->book('launch', new Attendee('Alice', 'alice@example.test'), 1, 'k1');
        $r2 = $service->book('launch', new Attendee('Bob', 'bob@example.test'), 1, 'k2');
        $r3 = $service->book('launch', new Attendee('Carol', 'carol@example.test'), 1, 'k3');

        self::assertSame('confirmed', $r1->booking->status);
        self::assertSame('confirmed', $r2->booking->status);
        self::assertSame('waitlisted', $r3->booking->status);
        self::assertSame(0, $service->availability('launch'));
        self::assertSame(
            [
                'Booking confirmed: Launch party',
                'Booking confirmed: Launch party',
                'Waitlisted: Launch party',
            ],
            $mailer->subjects(),
        );

        $canceled = $service->cancel($r1->booking->id);
        self::assertSame('canceled', $canceled->status);
        self::assertSame(0, $service->availability('launch'));

        $bookings = $store->listBookings('launch');
        self::assertCount(3, $bookings);
        self::assertSame('canceled', $bookings[0]->status);
        self::assertSame('confirmed', $bookings[1]->status);
        self::assertSame('confirmed', $bookings[2]->status);
        self::assertSame(
            [
                'Booking confirmed: Launch party',
                'Booking confirmed: Launch party',
                'Waitlisted: Launch party',
                'Booking confirmed: Launch party',
            ],
            $mailer->subjects(),
        );
    }

    public function testPdoStoreCancelPromotesFirstFittingWaitlistedBooking(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $store = new PdoStore($pdo);
        $store->migrate();
        $mailer = new RecordingMailer();
        $clock = new FrozenClock(new \DateTimeImmutable('2025-06-01T12:00:00Z'));
        $ids = new SequentialIds();
        $service = new BookingService($store, $mailer, $clock, $ids);

        $event = new Event(
            'launch',
            'Launch party',
            new \DateTimeImmutable('2025-06-02T18:00:00Z'),
            2,
        );
        $store->createEvent($event);

        $r1 = $service->book('launch', new Attendee('Alice', 'alice@example.test'), 1, 'k1');
        $service->book('launch', new Attendee('Bob', 'bob@example.test'), 1, 'k2');
        // Waitlisted for 2 seats (won't fit when 1 seat frees up)
        $r3 = $service->book('launch', new Attendee('Carol', 'carol@example.test'), 2, 'k3');
        // Waitlisted for 1 seat (will fit when 1 seat frees up)
        $r4 = $service->book('launch', new Attendee('Dave', 'dave@example.test'), 1, 'k4');

        self::assertSame('waitlisted', $r3->booking->status);
        self::assertSame('waitlisted', $r4->booking->status);

        $canceled = $service->cancel($r1->booking->id);
        self::assertSame('canceled', $canceled->status);
        self::assertSame(0, $service->availability('launch'));

        $all = $store->listBookings('launch');
        $byId = [];
        foreach ($all as $b) {
            $byId[$b->id] = $b;
        }
        self::assertArrayHasKey($r3->booking->id, $byId);
        self::assertArrayHasKey($r4->booking->id, $byId);
        self::assertSame('waitlisted', $byId[$r3->booking->id]->status);
        self::assertSame('confirmed', $byId[$r4->booking->id]->status);
    }

    public function testIdempotencySeatBoundsEventClosedAndSoldOut(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $store = new PdoStore($pdo);
        $store->migrate();
        $mailer = new RecordingMailer();
        $clock = new FrozenClock(new \DateTimeImmutable('2025-06-01T12:00:00Z'));
        $ids = new SequentialIds();
        $service = new BookingService($store, $mailer, $clock, $ids, null, 10);

        $event = new Event(
            'launch',
            'Launch party',
            new \DateTimeImmutable('2025-06-02T18:00:00Z'),
            1,
            1,
        );
        $store->createEvent($event);

        $first = $service->book('launch', new Attendee('Alice', 'alice@example.test'), 1, 'req-1');
        $second = $service->book('launch', new Attendee('Alice', 'alice@example.test'), 1, 'req-1');

        self::assertSame($first->booking->id, $second->booking->id);
        self::assertSame(0, $service->availability('launch'));
        self::assertCount(1, $mailer->messages);

        // Also verify InMemoryStore idempotency
        $memStore = new InMemoryStore();
        $memStore->createEvent($event);
        $memService = new BookingService($memStore, new RecordingMailer(), $clock, new SequentialIds());
        $m1 = $memService->book('launch', new Attendee('Alice', 'alice@example.test'), 1, 'req-1');
        $m2 = $memService->book('launch', new Attendee('Alice', 'alice@example.test'), 1, 'req-1');
        self::assertSame($m1->booking->id, $m2->booking->id);

        try {
            $service->book('launch', new Attendee('Bob', 'bob@example.test'), 0, 'req-zero');
            self::fail('Expected invalid_seats for 0');
        } catch (BookingException $e) {
            self::assertSame('invalid_seats', $e->errorCode);
            self::assertSame(0, $e->getCode());
        }

        try {
            $service->book('launch', new Attendee('Bob', 'bob@example.test'), 11, 'req-eleven');
            self::fail('Expected invalid_seats for 11');
        } catch (BookingException $e) {
            self::assertSame('invalid_seats', $e->errorCode);
        }

        // Waitlist capacity is 1: first waitlisted succeeds, second throws sold_out
        $w1 = $service->book('launch', new Attendee('Bob', 'bob@example.test'), 1, 'req-w1');
        self::assertSame('waitlisted', $w1->booking->status);

        try {
            $service->book('launch', new Attendee('Carol', 'carol@example.test'), 1, 'req-w2');
            self::fail('Expected sold_out');
        } catch (BookingException $e) {
            self::assertSame('sold_out', $e->errorCode);
        }

        // Advance clock past startsAt
        $clock->set(new \DateTimeImmutable('2025-06-02T18:00:01Z'));
        try {
            $service->book('launch', new Attendee('Dave', 'dave@example.test'), 1, 'req-late');
            self::fail('Expected event_closed');
        } catch (BookingException $e) {
            self::assertSame('event_closed', $e->errorCode);
        }
    }

    public function testAttendeeAndHeaderInjectionValidation(): void
    {
        $store = new InMemoryStore();
        $mailer = new RecordingMailer();
        $clock = new FrozenClock(new \DateTimeImmutable('2025-06-01T12:00:00Z'));
        $ids = new SequentialIds();
        $service = new BookingService($store, $mailer, $clock, $ids);
        $event = new Event('launch', 'Launch party', new \DateTimeImmutable('2025-06-02T18:00:00Z'), 5);
        $store->createEvent($event);

        try {
            $who = new Attendee("Eve\r\nBcc: x@example.test", 'eve@example.test');
            $service->book('launch', $who, 1, 'req-inj');
            self::fail('Expected header_injection');
        } catch (BookingException $e) {
            self::assertSame('header_injection', $e->errorCode);
        }

        try {
            new Attendee('Eve', 'not-an-email');
            self::fail('Expected invalid_email');
        } catch (BookingException $e) {
            self::assertSame('invalid_email', $e->errorCode);
        }

        try {
            new Attendee(str_repeat('a', 201), 'eve@example.test');
            self::fail('Expected invalid_name');
        } catch (BookingException $e) {
            self::assertSame('invalid_name', $e->errorCode);
        }

        try {
            new Attendee("Eve\x07Bell", 'eve@example.test');
            self::fail('Expected invalid_name for control char');
        } catch (BookingException $e) {
            self::assertSame('invalid_name', $e->errorCode);
        }

        try {
            new Attendee('Eve', "eve@example.test\r\n");
            self::fail('Expected header_injection in email');
        } catch (BookingException $e) {
            self::assertSame('header_injection', $e->errorCode);
        }

        try {
            new Message('eve@example.test', "Bad\r\nSubject", 'Body');
            self::fail('Expected header_injection in Message subject');
        } catch (BookingException $e) {
            self::assertSame('header_injection', $e->errorCode);
        }

        $booking = new Booking('bk_000001', 'launch', 1, 'confirmed', $clock->now());
        $attendee = new Attendee('Eve', 'eve@example.test');
        $msg1 = Messages::confirmation($event, $booking, $attendee);
        $msg2 = Messages::waitlisted($event, $booking, $attendee);
        $msg3 = Messages::canceled($event, $booking, $attendee);

        foreach ([$msg1, $msg2, $msg3] as $m) {
            self::assertStringNotContainsString("\r", $m->subject);
            self::assertStringNotContainsString("\n", $m->subject);
        }
    }

    public function testMailerFailureKeepsBookingConfirmedAndEmitsPiiFreeEvent(): void
    {
        $store = new InMemoryStore();
        $mailer = new RecordingMailer(new \RuntimeException('SMTP connection refused'));
        $clock = new FrozenClock(new \DateTimeImmutable('2025-06-01T12:00:00Z'));
        $ids = new SequentialIds();
        /** @var list<array<string, mixed>> $events */
        $events = [];
        $onEvent = static function (array $payload) use (&$events): void {
            $events[] = $payload;
        };

        $service = new BookingService($store, $mailer, $clock, $ids, $onEvent);
        $event = new Event('launch', 'Launch party', new \DateTimeImmutable('2025-06-02T18:00:00Z'), 2);
        $store->createEvent($event);

        $result = $service->book('launch', new Attendee('Secret Name', 'secret@example.test'), 1, 'req-mail-fail');

        self::assertSame('confirmed', $result->booking->status);
        self::assertFalse($result->emailSent);
        self::assertSame(1, $service->availability('launch'));
        self::assertCount(1, $events);
        $id = $result->booking->id;
        self::assertSame(['type' => 'mail_failed', 'bookingId' => $id], $events[0]);
        $serialized = json_encode($events[0], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('secret@example.test', $serialized);
        self::assertStringNotContainsString('Secret Name', $serialized);
    }

    public function testMigrateIdempotenceAndCliCommands(): void
    {
        $dbFile = sys_get_temp_dir() . '/events_' . uniqid('', true) . '.sqlite';
        try {
            $pdo = new \PDO('sqlite:' . $dbFile);
            $store = new PdoStore($pdo);
            $store->migrate();
            $store->migrate();

            $tablesStmt = $pdo->query(
                "SELECT name FROM sqlite_master WHERE type='table' AND name IN ('events', 'bookings', 'idempotency_keys') ORDER BY name",
            );
            self::assertNotFalse($tablesStmt);
            $tables = $tablesStmt->fetchAll(\PDO::FETCH_COLUMN);
            self::assertSame(['bookings', 'events', 'idempotency_keys'], $tables);

            $clock = new FrozenClock(new \DateTimeImmutable('2025-06-01T12:00:00Z'));
            $event = new Event('launch', 'Launch party', new \DateTimeImmutable('2025-06-02T18:00:00Z'), 1);
            $store->createEvent($event);
            // Update event via createEvent conflict path
            $store->createEvent($event);

            $service = new BookingService($store, new RecordingMailer(), $clock, new SequentialIds());
            $service->book('launch', new Attendee('Alice', 'alice@example.test'), 1, 'req-cli-1');

            $stdout = fopen('php://memory', 'r+');
            $stderr = fopen('php://memory', 'r+');
            self::assertIsResource($stdout);
            self::assertIsResource($stderr);

            $code = Cli::run(['availability', $dbFile, 'launch'], $stdout, $stderr);
            rewind($stdout);
            $output = stream_get_contents($stdout);
            self::assertSame(0, $code);
            self::assertSame('0', trim((string) $output));

            // Test 'list' command
            $stdoutList = fopen('php://memory', 'r+');
            self::assertIsResource($stdoutList);
            $codeList = Cli::run(['bin/cli', 'list', $dbFile, 'launch'], $stdoutList, $stderr);
            rewind($stdoutList);
            $listOut = (string) stream_get_contents($stdoutList);
            self::assertSame(0, $codeList);
            self::assertStringContainsString("bk_000001\tconfirmed\t1", $listOut);

            // Test unknown event domain error (exit code 1)
            $codeErr = Cli::run(['availability', $dbFile, 'nonexistent'], $stdout, $stderr);
            self::assertSame(1, $codeErr);

            // Test usage errors (exit code 2)
            self::assertSame(2, Cli::run(['availability'], $stdout, $stderr));
            self::assertSame(2, Cli::run(['unknown', $dbFile, 'launch'], $stdout, $stderr));
            self::assertSame(2, Cli::run(['availability', '/nonexistent/path/events.sqlite', 'launch'], $stdout, $stderr));

            fclose($stdout);
            fclose($stdoutList);
            fclose($stderr);
        } finally {
            if (is_file($dbFile)) {
                @unlink($dbFile);
            }
        }
    }

    public function testErrorPathsForUnknownEventBookingAndCancel(): void
    {
        $store = new InMemoryStore();
        $service = new BookingService(
            $store,
            new RecordingMailer(),
            new FrozenClock(),
            new SequentialIds(),
        );

        try {
            $service->availability('missing');
            self::fail('Expected unknown_event');
        } catch (BookingException $e) {
            self::assertSame('unknown_event', $e->errorCode);
        }

        try {
            $service->cancel('bk_missing');
            self::fail('Expected unknown_booking');
        } catch (BookingException $e) {
            self::assertSame('unknown_booking', $e->errorCode);
        }

        $event = new Event('ev1', 'Workshop', new \DateTimeImmutable('2026-01-01T00:00:00Z'), 2);
        $store->createEvent($event);
        $res = $service->book('ev1', new Attendee('Ada', 'ada@example.test'), 1, 'k-ada');
        $service->cancel($res->booking->id);

        try {
            $service->cancel($res->booking->id);
            self::fail('Expected already_canceled');
        } catch (BookingException $e) {
            self::assertSame('already_canceled', $e->errorCode);
        }
    }
}
