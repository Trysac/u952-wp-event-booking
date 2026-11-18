<?php

declare(strict_types=1);

namespace Trysac\WpEventBooking\Tests;

use PHPUnit\Framework\TestCase;
use Trysac\WpEventBooking\Attendee;
use Trysac\WpEventBooking\Booking;
use Trysac\WpEventBooking\BookingException;
use Trysac\WpEventBooking\BookingService;
use Trysac\WpEventBooking\Event;
use Trysac\WpEventBooking\FrozenClock;
use Trysac\WpEventBooking\InMemoryStore;
use Trysac\WpEventBooking\PdoStore;
use Trysac\WpEventBooking\RecordingMailer;
use Trysac\WpEventBooking\SequentialIds;

/**
 * Concurrency stress test (20 parallel OS processes) and 1,000-step stateful property test.
 *
 * @internal
 */
final class ConcurrencyAndPropertiesTest extends TestCase
{
    public function testTwentyConcurrentProcessesNeverOversellCapacityTen(): void
    {
        $dbFile = sys_get_temp_dir() . '/wp_event_booking_concurrent_' . uniqid('', true) . '.sqlite';
        $workerScript = __DIR__ . '/fixtures/book_worker.php';

        try {
            $pdo = new \PDO('sqlite:' . $dbFile);
            $store = new PdoStore($pdo);
            $store->migrate();

            $event = new Event(
                'launch',
                'Launch party',
                new \DateTimeImmutable('2025-12-31T23:59:59Z'),
                10,
                100,
            );
            $store->createEvent($event);
            unset($store, $pdo);

            /** @var list<array{proc: resource, stdout: resource, stderr: resource}> $workers */
            $workers = [];
            for ($i = 1; $i <= 20; ++$i) {
                $cmd = [PHP_BINARY, $workerScript, $dbFile, 'launch', (string) $i];
                $descriptors = [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ];
                $proc = proc_open($cmd, $descriptors, $pipes);
                self::assertIsResource($proc);
                self::assertArrayHasKey(0, $pipes);
                self::assertArrayHasKey(1, $pipes);
                self::assertArrayHasKey(2, $pipes);
                fclose($pipes[0]);
                $workers[] = ['proc' => $proc, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
            }

            $statuses = [];
            foreach ($workers as $w) {
                $out = stream_get_contents($w['stdout']);
                $err = stream_get_contents($w['stderr']);
                fclose($w['stdout']);
                fclose($w['stderr']);
                $exitCode = proc_close($w['proc']);
                self::assertSame(0, $exitCode, 'Worker stderr: ' . (string) $err);
                $statuses[] = trim((string) $out);
            }

            $confirmedProcessOutputs = count(array_filter($statuses, static fn(string $s): bool => 'confirmed' === $s));
            $waitlistedProcessOutputs = count(array_filter($statuses, static fn(string $s): bool => 'waitlisted' === $s));

            self::assertSame(10, $confirmedProcessOutputs);
            self::assertSame(10, $waitlistedProcessOutputs);

            $verifyPdo = new \PDO('sqlite:' . $dbFile);
            $verifyStore = new PdoStore($verifyPdo);
            self::assertSame(0, $verifyStore->availability('launch'));

            $queryStmt = $verifyPdo->query("SELECT booked FROM events WHERE id = 'launch'");
            self::assertInstanceOf(\PDOStatement::class, $queryStmt);
            $bookedVal = (int) $queryStmt->fetchColumn();
            self::assertSame(10, $bookedVal);

            $allBookings = $verifyStore->listBookings('launch');
            self::assertCount(20, $allBookings);

            $confirmedStored = 0;
            $waitlistedStored = 0;
            foreach ($allBookings as $b) {
                if (Booking::STATUS_CONFIRMED === $b->status) {
                    ++$confirmedStored;
                } elseif (Booking::STATUS_WAITLISTED === $b->status) {
                    ++$waitlistedStored;
                }
            }
            self::assertSame(10, $confirmedStored);
            self::assertSame(10, $waitlistedStored);
        } finally {
            if (is_file($dbFile)) {
                @unlink($dbFile);
            }
        }
    }

    public function testOneThousandRandomBookAndCancelOperationsMaintainInvariants(): void
    {
        mt_srand(3);

        $capacity = 15;
        $store = new InMemoryStore();
        $mailer = new RecordingMailer();
        $clock = new FrozenClock(new \DateTimeImmutable('2025-03-01T09:00:00Z'));
        $ids = new SequentialIds();
        $service = new BookingService($store, $mailer, $clock, $ids, null, 5);

        $event = new Event(
            'prop-event',
            'Property Test Event',
            new \DateTimeImmutable('2025-03-10T09:00:00Z'),
            $capacity,
            500,
        );
        $store->createEvent($event);

        /** @var list<string> $activeIds */
        $activeIds = [];

        for ($step = 1; $step <= 1_000; ++$step) {
            $op = mt_rand(0, 2);
            if (0 === $op && [] !== $activeIds) {
                $targetId = array_pop($activeIds);
                try {
                    $service->cancel($targetId);
                } catch (BookingException) {
                    // Already canceled or invalid
                }
            } else {
                $seats = mt_rand(1, 4);
                $who = new Attendee(sprintf('User %d', $step), sprintf('u%d@example.test', $step));
                try {
                    $res = $service->book('prop-event', $who, $seats, sprintf('idem-%d', $step));
                    $activeIds[] = $res->booking->id;
                } catch (BookingException) {
                    // Waitlist full or invalid seats
                }
            }

            $booked = $store->bookedSeats('prop-event');
            self::assertLessThanOrEqual($capacity, $booked);
            self::assertGreaterThanOrEqual(0, $booked);

            $confirmedSeatsSum = 0;
            foreach ($store->listBookings('prop-event') as $b) {
                if (Booking::STATUS_CONFIRMED === $b->status) {
                    $confirmedSeatsSum += $b->seats;
                }
            }
            self::assertSame($confirmedSeatsSum, $booked);
            self::assertSame($capacity - $booked, $service->availability('prop-event'));
        }
    }
}
