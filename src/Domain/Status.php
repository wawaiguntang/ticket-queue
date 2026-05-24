<?php

namespace Wawaiguntang\TicketQueue\Domain;

final class Status
{
    public const WAITING = 'waiting';
    public const CALLING = 'calling';
    public const SERVING = 'serving';
    public const SKIPPED = 'skipped';
    public const COMPLETED = 'completed';

    private function __construct()
    {
        // Prevent instantiation
    }

    /**
     * Validate whether a status string is valid.
     */
    public static function isValid(string $status): bool
    {
        return in_array($status, [
            self::WAITING,
            self::CALLING,
            self::SERVING,
            self::SKIPPED,
            self::COMPLETED,
        ], true);
    }
}
