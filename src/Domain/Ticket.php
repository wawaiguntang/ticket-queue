<?php

namespace Wawaiguntang\TicketQueue\Domain;

use DateTime;
use InvalidArgumentException;

class Ticket
{
    private string $id;
    private string $number;
    private Category $category;
    private string $status;
    private int $callCount;
    private int $orderIndex;
    private ?string $counterId;
    private DateTime $createdAt;
    private ?DateTime $calledAt;
    private ?DateTime $servedAt;
    private ?DateTime $completedAt;

    public function __construct(
        string $id,
        string $number,
        Category $category,
        string $status = Status::WAITING,
        int $callCount = 0,
        int $orderIndex = 0,
        ?string $counterId = null,
        ?DateTime $createdAt = null,
        ?DateTime $calledAt = null,
        ?DateTime $servedAt = null,
        ?DateTime $completedAt = null
    ) {
        if (!Status::isValid($status)) {
            throw new InvalidArgumentException("Invalid ticket status: $status");
        }

        $this->id = $id;
        $this->number = $number;
        $this->category = $category;
        $this->status = $status;
        $this->callCount = $callCount;
        $this->orderIndex = $orderIndex;
        $this->counterId = $counterId;
        $this->createdAt = $createdAt ?? new DateTime();
        $this->calledAt = $calledAt;
        $this->servedAt = $servedAt;
        $this->completedAt = $completedAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getBranchId(): string
    {
        return $this->category->getBranchId();
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function getCategory(): Category
    {
        return $this->category;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCallCount(): int
    {
        return $this->callCount;
    }

    public function getOrderIndex(): int
    {
        return $this->orderIndex;
    }

    public function getCounterId(): ?string
    {
        return $this->counterId;
    }

    public function getCreatedAt(): DateTime
    {
        return $this->createdAt;
    }

    public function getCalledAt(): ?DateTime
    {
        return $this->calledAt;
    }

    public function getServedAt(): ?DateTime
    {
        return $this->servedAt;
    }

    public function getCompletedAt(): ?DateTime
    {
        return $this->completedAt;
    }

    // State Mutators

    public function incrementCallCount(): void
    {
        $this->callCount++;
    }

    public function resetCallCount(): void
    {
        $this->callCount = 0;
    }

    public function setOrderIndex(int $orderIndex): void
    {
        $this->orderIndex = $orderIndex;
    }

    public function call(string $counterId): void
    {
        $this->status = Status::CALLING;
        $this->counterId = $counterId;
        $this->calledAt = new DateTime();
        $this->incrementCallCount();
    }

    public function serve(): void
    {
        $this->status = Status::SERVING;
        $this->servedAt = new DateTime();
    }

    public function complete(): void
    {
        $this->status = Status::COMPLETED;
        $this->completedAt = new DateTime();
    }

    public function skip(): void
    {
        $this->status = Status::SKIPPED;
    }

    public function postpone(int $newOrderIndex): void
    {
        $this->status = Status::WAITING;
        $this->orderIndex = $newOrderIndex;
        $this->resetCallCount();
        $this->calledAt = null;
        $this->counterId = null;
    }
}
