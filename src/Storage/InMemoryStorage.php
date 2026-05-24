<?php

namespace Wawaiguntang\TicketQueue\Storage;

use Wawaiguntang\TicketQueue\Domain\Category;
use Wawaiguntang\TicketQueue\Domain\Ticket;
use Wawaiguntang\TicketQueue\Domain\Status;

class InMemoryStorage implements QueueStorageInterface
{
    /** @var Ticket[] */
    private array $tickets = [];

    /** @var Category[] */
    private array $categories = [];

    public function save(Ticket $ticket): void
    {
        $this->tickets[$ticket->getId()] = $ticket;
    }

    public function findById(string $id): ?Ticket
    {
        return $this->tickets[$id] ?? null;
    }

    public function getNextWaiting(string $branchId, string $categoryCode): ?Ticket
    {
        $waiting = $this->getWaitingTickets($branchId, $categoryCode);
        return !empty($waiting) ? $waiting[0] : null;
    }

    public function getMaxOrderIndex(string $branchId, string $categoryCode): int
    {
        $max = 0;
        foreach ($this->tickets as $ticket) {
            if ($ticket->getBranchId() === $branchId && $ticket->getCategory()->getCode() === $categoryCode) {
                if ($ticket->getOrderIndex() > $max) {
                    $max = $ticket->getOrderIndex();
                }
            }
        }
        return $max;
    }

    public function getWaitingTickets(string $branchId, string $categoryCode): array
    {
        $waiting = [];
        foreach ($this->tickets as $ticket) {
            if ($ticket->getBranchId() === $branchId 
                && $ticket->getCategory()->getCode() === $categoryCode 
                && $ticket->getStatus() === Status::WAITING
            ) {
                $waiting[] = $ticket;
            }
        }

        // Sort by orderIndex ascending
        usort($waiting, function (Ticket $a, Ticket $b) {
            return $a->getOrderIndex() <=> $b->getOrderIndex();
        });

        return $waiting;
    }

    public function shiftWaitingOrderIndexes(string $branchId, string $categoryCode, int $afterIndex, int $amount = 1): void
    {
        foreach ($this->tickets as $ticket) {
            if ($ticket->getBranchId() === $branchId
                && $ticket->getCategory()->getCode() === $categoryCode 
                && $ticket->getStatus() === Status::WAITING 
                && $ticket->getOrderIndex() > $afterIndex
            ) {
                $ticket->setOrderIndex($ticket->getOrderIndex() + $amount);
            }
        }
    }

    public function saveCategory(Category $category): void
    {
        $key = $category->getBranchId() . '_' . $category->getCode();
        $this->categories[$key] = $category;
    }

    public function getCategory(string $branchId, string $code): ?Category
    {
        $key = $branchId . '_' . $code;
        return $this->categories[$key] ?? null;
    }
}
