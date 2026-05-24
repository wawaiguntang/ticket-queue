<?php

namespace Config;

use CodeIgniter\Config\BaseService;
use Wawaiguntang\TicketQueue\QueueManager;
use Wawaiguntang\TicketQueue\Storage\DatabaseStorage;

class Services extends BaseService
{
    /**
     * Layanan Ticket Queue Manager
     *
     * @return QueueManager
     */
    public static function ticketQueue($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('ticketQueue');
        }

        $db = \Config\Database::connect();
        $pdo = $db->connID; 
        
        $storage = new DatabaseStorage($pdo, 'queue_tickets', 'queue_categories');
        return new QueueManager($storage);
    }
}
