<?php

namespace Wawaiguntang\TicketQueue;

use Wawaiguntang\TicketQueue\Domain\Status;
use Wawaiguntang\TicketQueue\Domain\Ticket;
use Wawaiguntang\TicketQueue\Domain\Category;
use Wawaiguntang\TicketQueue\Storage\QueueStorageInterface;
use Wawaiguntang\TicketQueue\Exception\QueueException;
use Wawaiguntang\TicketQueue\Exception\TicketNotFoundException;
use Wawaiguntang\TicketQueue\Exception\InvalidStatusTransitionException;

class QueueManager
{
    private QueueStorageInterface $storage;

    public function __construct(QueueStorageInterface $storage)
    {
        $this->storage = $storage;
    }

    /**
     * Book/take a new ticket in a specific branch and category.
     *
     * @throws QueueException
     */
    public function takeTicket(string $branchId, string $categoryCode): Ticket
    {
        $category = $this->storage->getCategory($branchId, $categoryCode);
        if (!$category) {
            throw new QueueException("Category with code '{$categoryCode}' not found in branch '{$branchId}'.");
        }

        $maxOrderIndex = $this->storage->getMaxOrderIndex($branchId, $categoryCode);
        $nextIndex = $maxOrderIndex + 1;
        
        // Generate daily/sequential ticket number, e.g., A-001
        $ticketNumber = $categoryCode . '-' . sprintf('%03d', $nextIndex);
        $ticketId = uniqid($categoryCode . '_', true);

        $ticket = new Ticket(
            $ticketId,
            $ticketNumber,
            $category,
            Status::WAITING,
            0,
            $nextIndex
        );

        $this->storage->save($ticket);
        return $ticket;
    }

    /**
     * Call the next waiting ticket in a specific branch and category to a specific counter.
     */
    public function callNext(string $branchId, string $categoryCode, string $counterId): ?Ticket
    {
        $ticket = $this->storage->getNextWaiting($branchId, $categoryCode);
        if (!$ticket) {
            return null;
        }

        $ticket->call($counterId);
        $this->storage->save($ticket);

        return $ticket;
    }

    /**
     * Recall a ticket that is currently being called.
     *
     * @throws TicketNotFoundException
     * @throws InvalidStatusTransitionException
     */
    public function recall(string $ticketId): Ticket
    {
        $ticket = $this->storage->findById($ticketId);
        if (!$ticket) {
            throw new TicketNotFoundException("Ticket with ID '{$ticketId}' not found.");
        }

        if ($ticket->getStatus() !== Status::CALLING) {
            throw new InvalidStatusTransitionException("Cannot recall a ticket that is not in 'calling' state.");
        }

        $ticket->incrementCallCount();
        $this->storage->save($ticket);

        return $ticket;
    }

    /**
     * Start serving a called ticket.
     *
     * @throws TicketNotFoundException
     * @throws InvalidStatusTransitionException
     */
    public function serve(string $ticketId): Ticket
    {
        $ticket = $this->storage->findById($ticketId);
        if (!$ticket) {
            throw new TicketNotFoundException("Ticket with ID '{$ticketId}' not found.");
        }

        if ($ticket->getStatus() !== Status::CALLING) {
            throw new InvalidStatusTransitionException("Cannot start serving a ticket that is not in 'calling' state.");
        }

        $ticket->serve();
        $this->storage->save($ticket);

        return $ticket;
    }

    /**
     * Mark a serving ticket as completed.
     *
     * @throws TicketNotFoundException
     * @throws InvalidStatusTransitionException
     */
    public function complete(string $ticketId): Ticket
    {
        $ticket = $this->storage->findById($ticketId);
        if (!$ticket) {
            throw new TicketNotFoundException("Ticket with ID '{$ticketId}' not found.");
        }

        if ($ticket->getStatus() !== Status::SERVING) {
            throw new InvalidStatusTransitionException("Cannot complete a ticket that is not currently being served.");
        }

        $ticket->complete();
        $this->storage->save($ticket);

        return $ticket;
    }

    /**
     * Register a 'no response' action from the patient.
     * If the ticket's call count has reached or exceeded the category's max recalls,
     * the ticket is automatically rescheduled N spots down the queue.
     * Otherwise, it is marked as skipped permanently.
     *
     * @throws TicketNotFoundException
     * @throws InvalidStatusTransitionException
     */
    public function registerNoResponse(string $ticketId): Ticket
    {
        $ticket = $this->storage->findById($ticketId);
        if (!$ticket) {
            throw new TicketNotFoundException("Ticket with ID '{$ticketId}' not found.");
        }

        if ($ticket->getStatus() !== Status::CALLING) {
            throw new InvalidStatusTransitionException("Only tickets in 'calling' state can register no response.");
        }

        $category = $ticket->getCategory();
        $branchId = $ticket->getBranchId();

        // If the ticket has been called at least the maximum allowed times
        if ($ticket->getCallCount() >= $category->getMaxRecalls()) {
            $postponeOffset = $category->getPostponeOffset();
            $waitingTickets = $this->storage->getWaitingTickets($branchId, $category->getCode());

            if (count($waitingTickets) <= $postponeOffset) {
                // Put the ticket at the very end of the waiting queue
                $newOrderIndex = $this->storage->getMaxOrderIndex($branchId, $category->getCode()) + 1;
            } else {
                // Find the N-th waiting ticket
                $targetTicket = $waitingTickets[$postponeOffset - 1];
                $targetIndex = $targetTicket->getOrderIndex();

                // Shift all subsequent waiting tickets to make room
                $this->storage->shiftWaitingOrderIndexes($branchId, $category->getCode(), $targetIndex, 1);

                // Insert after the target ticket
                $newOrderIndex = $targetIndex + 1;
            }

            $ticket->postpone($newOrderIndex);
        } else {
            // Otherwise, mark it skipped permanently
            $ticket->skip();
        }

        $this->storage->save($ticket);
        return $ticket;
    }

    /**
     * Transfer a ticket to a new category within the same branch (e.g. from Admisi to Farmasi).
     *
     * @throws TicketNotFoundException
     * @throws QueueException
     */
    public function transfer(string $ticketId, string $newCategoryCode): Ticket
    {
        $ticket = $this->storage->findById($ticketId);
        if (!$ticket) {
            throw new TicketNotFoundException("Ticket with ID '{$ticketId}' not found.");
        }

        $branchId = $ticket->getBranchId();

        $newCategory = $this->storage->getCategory($branchId, $newCategoryCode);
        if (!$newCategory) {
            throw new QueueException("Destination category '{$newCategoryCode}' not found in branch '{$branchId}'.");
        }

        // Complete/skip the current ticket step
        if ($ticket->getStatus() === Status::SERVING) {
            $ticket->complete();
        } else {
            $ticket->skip();
        }
        $this->storage->save($ticket);

        // Take a new ticket in the destination category for the same branch
        return $this->takeTicket($branchId, $newCategoryCode);
    }
}
