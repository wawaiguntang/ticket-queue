<?php

namespace Wawaiguntang\TicketQueue\Storage;

use Wawaiguntang\TicketQueue\Domain\Category;
use Wawaiguntang\TicketQueue\Domain\Ticket;

interface QueueStorageInterface
{
    /**
     * Save/update a ticket.
     */
    public function save(Ticket $ticket): void;

    /**
     * Find a ticket by ID.
     */
    public function findById(string $id): ?Ticket;

    /**
     * Find the next waiting ticket in a specific branch and category, sorted by order_index asc.
     */
    public function getNextWaiting(string $branchId, string $categoryCode): ?Ticket;

    /**
     * Get the maximum order index in a specific branch and category.
     */
    public function getMaxOrderIndex(string $branchId, string $categoryCode): int;

    /**
     * Get all waiting tickets for a specific branch and category, sorted by order_index asc.
     * @return Ticket[]
     */
    public function getWaitingTickets(string $branchId, string $categoryCode): array;

    /**
     * Shift the order_index of waiting tickets in a specific branch and category.
     * Increment order_index by $amount for all tickets with order_index > $afterIndex.
     */
    public function shiftWaitingOrderIndexes(string $branchId, string $categoryCode, int $afterIndex, int $amount = 1): void;

    /**
     * Save/update a category.
     */
    public function saveCategory(Category $category): void;

    /**
     * Find a category by its branch ID and code.
     */
    public function getCategory(string $branchId, string $code): ?Category;
}
