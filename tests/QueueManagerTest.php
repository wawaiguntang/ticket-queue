<?php

namespace Wawaiguntang\TicketQueue\Tests;

use PHPUnit\Framework\TestCase;
use Wawaiguntang\TicketQueue\Domain\Category;
use Wawaiguntang\TicketQueue\Domain\Status;
use Wawaiguntang\TicketQueue\Exception\InvalidStatusTransitionException;
use Wawaiguntang\TicketQueue\QueueManager;
use Wawaiguntang\TicketQueue\Storage\InMemoryStorage;

class QueueManagerTest extends TestCase
{
    private InMemoryStorage $storage;
    private QueueManager $manager;

    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $this->manager = new QueueManager($this->storage);

        // Setup mock categories for BRANCH_1
        $this->storage->saveCategory(new Category('BRANCH_1', 'A', 'Admisi', 3, 2));
        $this->storage->saveCategory(new Category('BRANCH_1', 'B', 'Poli Anak', 2, 5));

        // Setup same mock categories for BRANCH_2 (proving duplicate category codes across branches work perfectly!)
        $this->storage->saveCategory(new Category('BRANCH_2', 'A', 'Admisi JKT', 3, 2));
    }

    public function testTakeTicketIncrementsSequenceNumberByBranch()
    {
        // Branch 1
        $t1 = $this->manager->takeTicket('BRANCH_1', 'A');
        $this->assertEquals('A-001', $t1->getNumber());
        $this->assertEquals(1, $t1->getOrderIndex());
        $this->assertEquals('BRANCH_1', $t1->getBranchId());

        $t2 = $this->manager->takeTicket('BRANCH_1', 'A');
        $this->assertEquals('A-002', $t2->getNumber());
        $this->assertEquals(2, $t2->getOrderIndex());

        // Branch 2 (should start from 1 independently!)
        $t3 = $this->manager->takeTicket('BRANCH_2', 'A');
        $this->assertEquals('A-001', $t3->getNumber());
        $this->assertEquals(1, $t3->getOrderIndex());
        $this->assertEquals('BRANCH_2', $t3->getBranchId());
    }

    public function testCallNextChangesStatusToCalling()
    {
        $this->manager->takeTicket('BRANCH_1', 'A');
        $called = $this->manager->callNext('BRANCH_1', 'A', 'LOKET_1');

        $this->assertNotNull($called);
        $this->assertEquals(Status::CALLING, $called->getStatus());
        $this->assertEquals('LOKET_1', $called->getCounterId());
        $this->assertEquals(1, $called->getCallCount());
        $this->assertNotNull($called->getCalledAt());
    }

    public function testRecallIncrementsCallCount()
    {
        $t = $this->manager->takeTicket('BRANCH_1', 'A');
        $called = $this->manager->callNext('BRANCH_1', 'A', 'LOKET_1');

        $recalled = $this->manager->recall($called->getId());
        $this->assertEquals(2, $recalled->getCallCount());
        $this->assertEquals(Status::CALLING, $recalled->getStatus());
    }

    public function testServeAndCompleteTransitions()
    {
        $t = $this->manager->takeTicket('BRANCH_1', 'A');
        $called = $this->manager->callNext('BRANCH_1', 'A', 'LOKET_1');

        $served = $this->manager->serve($called->getId());
        $this->assertEquals(Status::SERVING, $served->getStatus());
        $this->assertNotNull($served->getServedAt());

        $completed = $this->manager->complete($served->getId());
        $this->assertEquals(Status::COMPLETED, $completed->getStatus());
        $this->assertNotNull($completed->getCompletedAt());
    }

    public function testRegisterNoResponsePermanentlySkipsIfBelowMaxRecalls()
    {
        $t = $this->manager->takeTicket('BRANCH_1', 'A');
        $called = $this->manager->callNext('BRANCH_1', 'A', 'LOKET_1'); // call_count = 1

        // maxRecalls for A is 3, so call_count (1) < maxRecalls (3) -> should be skipped permanently
        $processed = $this->manager->registerNoResponse($called->getId());

        $this->assertEquals(Status::SKIPPED, $processed->getStatus());
    }

    public function testRegisterNoResponseAppendsToEndIfWaitingQueueIsFewerThanOffset()
    {
        $t = $this->manager->takeTicket('BRANCH_1', 'A'); // A-001, order_index = 1
        $called = $this->manager->callNext('BRANCH_1', 'A', 'LOKET_1'); // call_count = 1
        
        $this->manager->recall($called->getId()); // call_count = 2
        $this->manager->recall($called->getId()); // call_count = 3 (reached maxRecalls)

        // Only A-001 is booked, so waiting list is currently empty.
        // Postpone offset is 2. Since 0 <= 2, it should append to the end.
        $processed = $this->manager->registerNoResponse($called->getId());

        $this->assertEquals(Status::WAITING, $processed->getStatus());
        $this->assertEquals(2, $processed->getOrderIndex());
        $this->assertEquals(0, $processed->getCallCount());
    }

    public function testMultiBranchDataIsolationOnPostponeShifting()
    {
        // Setup two parallel branch queues for Category 'A':
        // Branch 1:
        // B1-T1 (being called)
        // B1-T2 (waiting, index 2)
        // B1-T3 (waiting, index 3)
        // B1-T4 (waiting, index 4)

        $b1_t1 = $this->manager->takeTicket('BRANCH_1', 'A'); // idx 1
        $b1_t2 = $this->manager->takeTicket('BRANCH_1', 'A'); // idx 2
        $b1_t3 = $this->manager->takeTicket('BRANCH_1', 'A'); // idx 3
        $b1_t4 = $this->manager->takeTicket('BRANCH_1', 'A'); // idx 4

        // Branch 2:
        // B2-T1 (waiting, index 1)
        // B2-T2 (waiting, index 2)
        $b2_t1 = $this->manager->takeTicket('BRANCH_2', 'A'); // idx 1
        $b2_t2 = $this->manager->takeTicket('BRANCH_2', 'A'); // idx 2

        $called = $this->manager->callNext('BRANCH_1', 'A', 'LOKET_1'); // B1-T1
        $this->manager->recall($called->getId()); // call_count = 2
        $this->manager->recall($called->getId()); // call_count = 3

        // Trigger no response on Branch 1.
        // Postpone offset is 2.
        // Waiting tickets in Branch 1: B1-T2 (idx 2), B1-T3 (idx 3), B1-T4 (idx 4)
        // B1-T1 should be rescheduled after the 2nd waiting ticket B1-T3 (idx 3) -> new index = 4.
        // B1-T4 (idx 4) should be shifted to idx 5.
        // BUT Branch 2 tickets (B2-T1, B2-T2) must remain absolutely untouched!
        
        $processed = $this->manager->registerNoResponse($called->getId());

        $this->assertEquals(Status::WAITING, $processed->getStatus());
        $this->assertEquals(4, $processed->getOrderIndex());

        // Assert Branch 1 shifts correctly
        $this->assertEquals(5, $this->storage->findById($b1_t4->getId())->getOrderIndex());
        $this->assertEquals(2, $this->storage->findById($b1_t2->getId())->getOrderIndex());
        $this->assertEquals(3, $this->storage->findById($b1_t3->getId())->getOrderIndex());

        // Assert Branch 2 remains completely untouched!
        $this->assertEquals(1, $this->storage->findById($b2_t1->getId())->getOrderIndex());
        $this->assertEquals(2, $this->storage->findById($b2_t2->getId())->getOrderIndex());
    }

    public function testTransferCreatesNewTicketAndCompletesOld()
    {
        $t1 = $this->manager->takeTicket('BRANCH_1', 'A');
        $called = $this->manager->callNext('BRANCH_1', 'A', 'LOKET_1');
        $served = $this->manager->serve($called->getId());

        // Transfers to category 'B' in same branch
        $newTicket = $this->manager->transfer($served->getId(), 'B');

        $this->assertEquals(Status::COMPLETED, $this->storage->findById($t1->getId())->getStatus());
        $this->assertEquals('B-001', $newTicket->getNumber());
        $this->assertEquals(Status::WAITING, $newTicket->getStatus());
        $this->assertEquals('BRANCH_1', $newTicket->getBranchId());
    }
}
