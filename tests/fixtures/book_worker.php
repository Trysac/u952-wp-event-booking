<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Trysac\WpEventBooking\Attendee;
use Trysac\WpEventBooking\BookingService;
use Trysac\WpEventBooking\FrozenClock;
use Trysac\WpEventBooking\PdoStore;
use Trysac\WpEventBooking\RecordingMailer;
use Trysac\WpEventBooking\SequentialIds;

$dbFile = $argv[1] ?? '';
$eventId = $argv[2] ?? '';
$workerIndex = (int) ($argv[3] ?? 0);

$pdo = new \PDO('sqlite:' . $dbFile);
$store = new PdoStore($pdo);
$mailer = new RecordingMailer();
$clock = new FrozenClock(new \DateTimeImmutable('2025-05-01T10:00:00Z'));
$ids = new SequentialIds($workerIndex * 100, sprintf('w%02d_', $workerIndex));
$service = new BookingService($store, $mailer, $clock, $ids);

$attendee = new Attendee(sprintf('Attendee %d', $workerIndex), sprintf('user%d@example.test', $workerIndex));
$result = $service->book($eventId, $attendee, 1, sprintf('req-worker-%d', $workerIndex));

fwrite(STDOUT, $result->booking->status . "\n");
