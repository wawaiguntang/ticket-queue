<?php

namespace App\Controllers;

use CodeIgniter\API\ResponseTrait;
use Config\Services;
use Wawaiguntang\TicketQueue\Exception\QueueException;

class QueueController extends BaseController
{
    use ResponseTrait;

    private $queue;

    public function __construct()
    {
        $this->queue = Services::ticketQueue();
    }

    /**
     * Mengambil tiket antrean baru untuk cabang tertentu.
     */
    public function takeTicket()
    {
        $branchId = $this->request->getVar('branch_id');
        $categoryCode = $this->request->getVar('category_code');

        if (empty($branchId) || empty($categoryCode)) {
            return $this->fail('branch_id and category_code are required.', 400);
        }

        try {
            $ticket = $this->queue->takeTicket($branchId, $categoryCode);

            return $this->respond([
                'success' => true,
                'message' => 'Ticket created successfully.',
                'data' => [
                    'id' => $ticket->getId(),
                    'branch_id' => $ticket->getBranchId(),
                    'number' => $ticket->getNumber(),
                    'status' => $ticket->getStatus(),
                    'order_index' => $ticket->getOrderIndex()
                ]
            ]);
        } catch (QueueException $e) {
            return $this->fail($e->getMessage(), 400);
        }
    }

    /**
     * Memanggil antrean berikutnya untuk cabang terkait.
     */
    public function callNext()
    {
        $branchId = $this->request->getVar('branch_id');
        $categoryCode = $this->request->getVar('category_code');
        $counterId = $this->request->getVar('counter_id');

        if (empty($branchId) || empty($categoryCode) || empty($counterId)) {
            return $this->fail('branch_id, category_code, and counter_id are required.', 400);
        }

        $ticket = $this->queue->callNext($branchId, $categoryCode, $counterId);

        if (!$ticket) {
            return $this->respond([
                'success' => true,
                'message' => 'No tickets waiting in queue for this branch.',
                'data' => null
            ]);
        }

        return $this->respond([
            'success' => true,
            'message' => 'Ticket called successfully.',
            'data' => [
                'id' => $ticket->getId(),
                'branch_id' => $ticket->getBranchId(),
                'number' => $ticket->getNumber(),
                'status' => $ticket->getStatus(),
                'call_count' => $ticket->getCallCount(),
                'counter_id' => $ticket->getCounterId()
            ]
        ]);
    }

    /**
     * Melakukan panggilan ulang (Recall).
     */
    public function recall($ticketId)
    {
        try {
            $ticket = $this->queue->recall($ticketId);

            return $this->respond([
                'success' => true,
                'message' => 'Ticket recalled.',
                'data' => [
                    'number' => $ticket->getNumber(),
                    'call_count' => $ticket->getCallCount()
                ]
            ]);
        } catch (QueueException $e) {
            return $this->fail($e->getMessage(), 400);
        }
    }

    /**
     * Mulai melayani pasien.
     */
    public function serve($ticketId)
    {
        try {
            $ticket = $this->queue->serve($ticketId);

            return $this->respond([
                'success' => true,
                'message' => 'Serving ticket started.',
                'data' => ['status' => $ticket->getStatus()]
            ]);
        } catch (QueueException $e) {
            return $this->fail($e->getMessage(), 400);
        }
    }

    /**
     * Menyelesaikan pelayanan pasien.
     */
    public function complete($ticketId)
    {
        try {
            $ticket = $this->queue->complete($ticketId);

            return $this->respond([
                'success' => true,
                'message' => 'Serving ticket completed.',
                'data' => ['status' => $ticket->getStatus()]
            ]);
        } catch (QueueException $e) {
            return $this->fail($e->getMessage(), 400);
        }
    }

    /**
     * Menangani pasien tidak hadir (No-Show).
     */
    public function noResponse($ticketId)
    {
        try {
            $ticket = $this->queue->registerNoResponse($ticketId);

            $action = ($ticket->getStatus() === 'waiting') 
                ? 'Ticket has been rescheduled down the queue.' 
                : 'Ticket skipped permanently.';

            return $this->respond([
                'success' => true,
                'message' => $action,
                'data' => [
                    'status' => $ticket->getStatus(),
                    'new_order_index' => $ticket->getOrderIndex()
                ]
            ]);
        } catch (QueueException $e) {
            return $this->fail($e->getMessage(), 400);
        }
    }
}
