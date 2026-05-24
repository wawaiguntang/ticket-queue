<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Wawaiguntang\TicketQueue\QueueManager;
use Wawaiguntang\TicketQueue\Exception\QueueException;

class QueueController extends Controller
{
    private QueueManager $queue;

    public function __construct(QueueManager $queue)
    {
        $this->queue = $queue;
    }

    /**
     * Pasien mengambil tiket antrean baru berdasarkan cabang dan kategori.
     */
    public function takeTicket(Request $request)
    {
        $request->validate([
            'branch_id' => 'required|string',
            'category_code' => 'required|string'
        ]);

        try {
            $ticket = $this->queue->takeTicket($request->branch_id, $request->category_code);

            return response()->json([
                'success' => true,
                'message' => 'Tiket antrean berhasil diambil.',
                'data' => [
                    'id' => $ticket->getId(),
                    'branch_id' => $ticket->getBranchId(),
                    'number' => $ticket->getNumber(),
                    'status' => $ticket->getStatus(),
                    'order_index' => $ticket->getOrderIndex()
                ]
            ]);
        } catch (QueueException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * Operator loket memanggil pasien berikutnya untuk cabang terkait.
     */
    public function callNext(Request $request)
    {
        $request->validate([
            'branch_id' => 'required|string',
            'category_code' => 'required|string',
            'counter_id' => 'required|string'
        ]);

        $ticket = $this->queue->callNext($request->branch_id, $request->category_code, $request->counter_id);

        if (!$ticket) {
            return response()->json([
                'success' => true,
                'message' => 'Tidak ada antrean menunggu di cabang dan kategori ini.',
                'data' => null
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Panggilan antrean berhasil dipicu.',
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
     * Panggil ulang pasien (Recall).
     */
    public function recall($ticketId)
    {
        try {
            $ticket = $this->queue->recall($ticketId);

            return response()->json([
                'success' => true,
                'message' => 'Panggilan ulang berhasil.',
                'data' => [
                    'number' => $ticket->getNumber(),
                    'call_count' => $ticket->getCallCount()
                ]
            ]);
        } catch (QueueException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * Mulai melayani pasien (pasien sudah di depan loket/poli).
     */
    public function serve($ticketId)
    {
        try {
            $ticket = $this->queue->serve($ticketId);

            return response()->json([
                'success' => true,
                'message' => 'Pelayanan dimulai.',
                'data' => ['status' => $ticket->getStatus()]
            ]);
        } catch (QueueException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * Menyelesaikan pelayanan pasien.
     */
    public function complete($ticketId)
    {
        try {
            $ticket = $this->queue->complete($ticketId);

            return response()->json([
                'success' => true,
                'message' => 'Pelayanan selesai.',
                'data' => ['status' => $ticket->getStatus()]
            ]);
        } catch (QueueException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * Pasien tidak merespons (No-Show).
     * Jika panggilan mencapai limit, otomatis masuk reschedule (pindah ke bawah).
     */
    public function noResponse($ticketId)
    {
        try {
            $ticket = $this->queue->registerNoResponse($ticketId);

            $action = ($ticket->getStatus() === 'waiting') 
                ? 'Antrean dijadwalkan ulang ke bawah (rescheduled).' 
                : 'Antrean dilewati (skipped/hangus).';

            return response()->json([
                'success' => true,
                'message' => $action,
                'data' => [
                    'status' => $ticket->getStatus(),
                    'new_order_index' => $ticket->getOrderIndex()
                ]
            ]);
        } catch (QueueException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }
}
