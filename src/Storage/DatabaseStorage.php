<?php

namespace Wawaiguntang\TicketQueue\Storage;

use DateTime;
use PDO;
use Wawaiguntang\TicketQueue\Domain\Category;
use Wawaiguntang\TicketQueue\Domain\Ticket;
use Wawaiguntang\TicketQueue\Domain\Status;

class DatabaseStorage implements QueueStorageInterface
{
    private PDO $pdo;
    private string $ticketsTable;
    private string $categoriesTable;

    public function __construct(PDO $pdo, string $ticketsTable = 'queue_tickets', string $categoriesTable = 'queue_categories')
    {
        $this->pdo = $pdo;
        $this->ticketsTable = $ticketsTable;
        $this->categoriesTable = $categoriesTable;
    }

    public function save(Ticket $ticket): void
    {
        // Check if ticket exists by ID
        $stmt = $this->pdo->prepare("SELECT id FROM {$this->ticketsTable} WHERE id = :id");
        $stmt->execute(['id' => $ticket->getId()]);
        $exists = $stmt->fetchColumn();

        $params = [
            'id' => $ticket->getId(),
            'branch_id' => $ticket->getBranchId(),
            'number' => $ticket->getNumber(),
            'category_code' => $ticket->getCategory()->getCode(),
            'status' => $ticket->getStatus(),
            'call_count' => $ticket->getCallCount(),
            'order_index' => $ticket->getOrderIndex(),
            'counter_id' => $ticket->getCounterId(),
            'created_at' => $ticket->getCreatedAt()->format('Y-m-d H:i:s'),
            'called_at' => $ticket->getCalledAt() ? $ticket->getCalledAt()->format('Y-m-d H:i:s') : null,
            'served_at' => $ticket->getServedAt() ? $ticket->getServedAt()->format('Y-m-d H:i:s') : null,
            'completed_at' => $ticket->getCompletedAt() ? $ticket->getCompletedAt()->format('Y-m-d H:i:s') : null,
        ];

        if ($exists) {
            $sql = "UPDATE {$this->ticketsTable} SET 
                        branch_id = :branch_id,
                        number = :number,
                        category_code = :category_code,
                        status = :status,
                        call_count = :call_count,
                        order_index = :order_index,
                        counter_id = :counter_id,
                        created_at = :created_at,
                        called_at = :called_at,
                        served_at = :served_at,
                        completed_at = :completed_at
                    WHERE id = :id";
        } else {
            $sql = "INSERT INTO {$this->ticketsTable} 
                    (id, branch_id, number, category_code, status, call_count, order_index, counter_id, created_at, called_at, served_at, completed_at)
                    VALUES 
                    (:id, :branch_id, :number, :category_code, :status, :call_count, :order_index, :counter_id, :created_at, :called_at, :served_at, :completed_at)";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    public function findById(string $id): ?Ticket
    {
        $sql = "SELECT t.*, c.name as category_name, c.max_recalls, c.postpone_offset 
                FROM {$this->ticketsTable} t
                JOIN {$this->categoriesTable} c ON t.category_code = c.code AND t.branch_id = c.branch_id
                WHERE t.id = :id";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return $this->hydrateTicket($row);
    }

    public function getNextWaiting(string $branchId, string $categoryCode): ?Ticket
    {
        $sql = "SELECT t.*, c.name as category_name, c.max_recalls, c.postpone_offset 
                FROM {$this->ticketsTable} t
                JOIN {$this->categoriesTable} c ON t.category_code = c.code AND t.branch_id = c.branch_id
                WHERE t.branch_id = :branch_id AND t.category_code = :category_code AND t.status = :status
                ORDER BY t.order_index ASC LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'branch_id' => $branchId,
            'category_code' => $categoryCode,
            'status' => Status::WAITING
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return $this->hydrateTicket($row);
    }

    public function getMaxOrderIndex(string $branchId, string $categoryCode): int
    {
        $sql = "SELECT MAX(order_index) FROM {$this->ticketsTable} WHERE branch_id = :branch_id AND category_code = :category_code";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'branch_id' => $branchId,
            'category_code' => $categoryCode
        ]);
        $max = $stmt->fetchColumn();

        return $max ? (int) $max : 0;
    }

    public function getWaitingTickets(string $branchId, string $categoryCode): array
    {
        $sql = "SELECT t.*, c.name as category_name, c.max_recalls, c.postpone_offset 
                FROM {$this->ticketsTable} t
                JOIN {$this->categoriesTable} c ON t.category_code = c.code AND t.branch_id = c.branch_id
                WHERE t.branch_id = :branch_id AND t.category_code = :category_code AND t.status = :status
                ORDER BY t.order_index ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'branch_id' => $branchId,
            'category_code' => $categoryCode,
            'status' => Status::WAITING
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $tickets = [];
        foreach ($rows as $row) {
            $tickets[] = $this->hydrateTicket($row);
        }

        return $tickets;
    }

    public function shiftWaitingOrderIndexes(string $branchId, string $categoryCode, int $afterIndex, int $amount = 1): void
    {
        $sql = "UPDATE {$this->ticketsTable} 
                SET order_index = order_index + :amount 
                WHERE branch_id = :branch_id AND category_code = :category_code AND status = :status AND order_index > :after_index";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'amount' => $amount,
            'branch_id' => $branchId,
            'category_code' => $categoryCode,
            'status' => Status::WAITING,
            'after_index' => $afterIndex
        ]);
    }

    public function saveCategory(Category $category): void
    {
        $stmt = $this->pdo->prepare("SELECT code FROM {$this->categoriesTable} WHERE branch_id = :branch_id AND code = :code");
        $stmt->execute([
            'branch_id' => $category->getBranchId(),
            'code' => $category->getCode()
        ]);
        $exists = $stmt->fetchColumn();

        $params = [
            'branch_id' => $category->getBranchId(),
            'code' => $category->getCode(),
            'name' => $category->getName(),
            'max_recalls' => $category->getMaxRecalls(),
            'postpone_offset' => $category->getPostponeOffset(),
        ];

        if ($exists) {
            $sql = "UPDATE {$this->categoriesTable} SET 
                        name = :name,
                        max_recalls = :max_recalls,
                        postpone_offset = :postpone_offset
                    WHERE branch_id = :branch_id AND code = :code";
        } else {
            $sql = "INSERT INTO {$this->categoriesTable} (branch_id, code, name, max_recalls, postpone_offset)
                    VALUES (:branch_id, :code, :name, :max_recalls, :postpone_offset)";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    public function getCategory(string $branchId, string $code): ?Category
    {
        $sql = "SELECT * FROM {$this->categoriesTable} WHERE branch_id = :branch_id AND code = :code";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'branch_id' => $branchId,
            'code' => $code
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return new Category(
            $row['branch_id'],
            $row['code'],
            $row['name'],
            (int) $row['max_recalls'],
            (int) $row['postpone_offset']
        );
    }

    private function hydrateTicket(array $row): Ticket
    {
        $category = new Category(
            $row['branch_id'],
            $row['category_code'],
            $row['category_name'],
            (int) $row['max_recalls'],
            (int) $row['postpone_offset']
        );

        return new Ticket(
            $row['id'],
            $row['number'],
            $category,
            $row['status'],
            (int) $row['call_count'],
            (int) $row['order_index'],
            $row['counter_id'],
            new DateTime($row['created_at']),
            $row['called_at'] ? new DateTime($row['called_at']) : null,
            $row['served_at'] ? new DateTime($row['served_at']) : null,
            $row['completed_at'] ? new DateTime($row['completed_at']) : null
        );
    }
}
