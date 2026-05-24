<?php

namespace Wawaiguntang\TicketQueue\Domain;

class Category
{
    private string $branchId;
    private string $code;
    private string $name;
    private int $maxRecalls;
    private int $postponeOffset;

    public function __construct(string $branchId, string $code, string $name, int $maxRecalls = 3, int $postponeOffset = 5)
    {
        $this->branchId = $branchId;
        $this->code = $code;
        $this->name = $name;
        $this->maxRecalls = $maxRecalls;
        $this->postponeOffset = $postponeOffset;
    }

    public function getBranchId(): string
    {
        return $this->branchId;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getMaxRecalls(): int
    {
        return $this->maxRecalls;
    }

    public function getPostponeOffset(): int
    {
        return $this->postponeOffset;
    }
}
