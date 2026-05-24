# CodeIgniter 4 Integration Guide: `wawaiguntang/ticket-queue` (Multi-Branch)

Panduan ini mendeskripsikan secara lengkap langkah-langkah mengintegrasikan library `wawaiguntang/ticket-queue` dengan dukungan **Multi-Cabang** ke dalam proyek **CodeIgniter 4**.

---

## 1. Migrasi Database (Composite Keys)

Buat file migrasi baru menggunakan Spark CLI:
```bash
php spark make:migration CreateQueueTables
```

Isi file migrasi di direktori `app/Database/Migrations/` dengan skema database di bawah ini:

```php
namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateQueueTables extends Migration
{
    public function up()
    {
        // 1. Tabel Kategori Antrean (Composite Primary Key)
        $this->forge->addField([
            'branch_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
            ],
            'code' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'max_recalls' => [
                'type'       => 'INT',
                'default'    => 3,
            ],
            'postpone_offset' => [
                'type'       => 'INT',
                'default'    => 5,
            ],
            'created_at' => [
                'type'       => 'DATETIME',
                'null'       => true,
            ],
            'updated_at' => [
                'type'       => 'DATETIME',
                'null'       => true,
            ],
        ]);
        // Set composite primary key
        $this->forge->addKey(['branch_id', 'code'], true);
        $this->forge->createTable('queue_categories');

        // 2. Tabel Tiket Antrean
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
            ],
            'branch_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
            ],
            'number' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
            ],
            'category_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
            ],
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
            ],
            'call_count' => [
                'type'       => 'INT',
                'default'    => 0,
            ],
            'order_index' => [
                'type'       => 'INT',
            ],
            'counter_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
            ],
            'created_at' => [
                'type'    => 'DATETIME',
            ],
            'called_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'served_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'completed_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addForeignKey(['branch_id', 'category_code'], 'queue_categories', ['branch_id', 'code'], 'CASCADE', 'CASCADE');
        $this->forge->createTable('queue_tickets');
    }

    public function down()
    {
        $this->forge->dropTable('queue_tickets');
        $this->forge->dropTable('queue_categories');
    }
}
```

Jalankan migrasi:
```bash
php spark migrate
```

---

## 2. Pendaftaran Layanan di Config/Services

Method pendaftaran layanan tetap sama pada `app/Config/Services.php`:

```php
namespace Config;

use CodeIgniter\Config\BaseService;
use Wawaiguntang\TicketQueue\QueueManager;
use Wawaiguntang\TicketQueue\Storage\DatabaseStorage;

class Services extends BaseService
{
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
```

---

## 3. Pembuatan Controller Antrean

Terapkan controller multi-cabang berikut di file [QueueController.php](QueueController.php) di folder ini, atau pasang kode di bawah pada proyek CI4 Anda:

```php
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
                'call_count' => $ticket->getCallCount()
            ]
        ]);
    }
}
```

---

## 4. Definisi Rute

Tambahkan rute di file `app/Config/Routes.php`:

```php
$routes->group('queue', function($routes) {
    $routes->post('take', 'QueueController::takeTicket');
    $routes->post('call-next', 'QueueController::callNext');
    $routes->post('(:segment)/recall', 'QueueController::recall/$1');
    $routes->post('(:segment)/serve', 'QueueController::serve/$1');
    $routes->post('(:segment)/complete', 'QueueController::complete/$1');
    $routes->post('(:segment)/no-response', 'QueueController::noResponse/$1');
});
```
