<?php

declare(strict_types=1);

namespace Trysac\WpEventBooking;

/**
 * Command-line interface for inspecting SQLite booking databases.
 */
final class Cli
{
    /**
     * Executes a CLI command against a SQLite database file.
     *
     * Supported commands:
     * - availability <db> <eventId>
     * - list <db> <eventId>
     *
     * @param list<string> $argv command arguments (either starting with command name or script name)
     * @param resource $stdout output stream
     * @param resource $stderr error stream
     *
     * @return int exit code (0 on success, 1 on domain error, 2 on usage or I/O error)
     */
    public static function run(array $argv, $stdout, $stderr): int
    {
        $args = $argv;
        if (isset($args[0]) && !in_array($args[0], ['availability', 'list'], true)) {
            array_shift($args);
        }

        if (3 !== count($args)) {
            fwrite($stderr, "Usage: <availability|list> <db> <eventId>\n");

            return 2;
        }

        [$command, $dbPath, $eventId] = $args;
        if (!in_array($command, ['availability', 'list'], true)) {
            fwrite($stderr, "Usage: <availability|list> <db> <eventId>\n");

            return 2;
        }

        if ('' === trim($dbPath) || !is_file($dbPath) || !is_readable($dbPath)) {
            fwrite($stderr, "Database file not found or not readable.\n");

            return 2;
        }

        try {
            $pdo = new \PDO('sqlite:' . $dbPath);
            $store = new PdoStore($pdo);

            if ('availability' === $command) {
                $available = $store->availability($eventId);
                fwrite($stdout, (string) $available . "\n");

                return 0;
            }

            $bookings = $store->listBookings($eventId);
            foreach ($bookings as $b) {
                fwrite($stdout, sprintf("%s\t%s\t%d\n", $b->id, $b->status, $b->seats));
            }

            return 0;
        } catch (BookingException $e) {
            fwrite($stderr, $e->errorCode . ': ' . $e->getMessage() . "\n");

            return 1;
        } catch (\Throwable $e) {
            fwrite($stderr, 'io_error: ' . $e->getMessage() . "\n");

            return 2;
        }
    }
}
