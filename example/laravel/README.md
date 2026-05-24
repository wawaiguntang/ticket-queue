# Laravel Integration Guide: `wawaiguntang/ticket-queue` (Multi-Branch)

Panduan ini menjelaskan cara mengintegrasikan library `wawaiguntang/ticket-queue` dengan dukungan **Multi-Cabang** ke dalam proyek **Laravel**.

---

## 1. Migrasi Database (Composite Keys)

Buat migrasi database untuk tabel kategori dan tiket antrean. Jalankan perintah:
```bash
php artisan make:migration create_queue_tables
```

Kemudian, isi file migrasi dengan struktur berikut yang memanfaatkan **Composite Keys** untuk isolasi data cabang:

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('queue_categories', function (Blueprint $table) {
            $table->string('branch_id', 50); // ID Cabang Rumah Sakit
            $table->string('code', 50);      // e.g. 'A', 'B'
            $table->string('name', 255);
            $table->integer('max_recalls')->default(3);
            $table->integer('postpone_offset')->default(5);
            $table->timestamps();

            // Kategori unik berdasarkan kombinasi Cabang + Kode Kategori
            $table->primary(['branch_id', 'code']);
        });

        Schema::create('queue_tickets', function (Blueprint $table) {
            $table->string('id', 50)->primary();
            $table->string('branch_id', 50);
            $table->string('number', 50);
            $table->string('category_code', 50);
            $table->string('status', 50);
            $table->integer('call_count')->default(0);
            $table->integer('order_index');
            $table->string('counter_id', 50)->nullable();
            
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('called_at')->nullable();
            $table->timestamp('served_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Hubungkan foreign key ke composite primary key di tabel kategori
            $table->foreign(['branch_id', 'category_code'])
                  ->references(['branch_id', 'code'])
                  ->on('queue_categories')
                  ->onDelete('cascade');

            $table->index(['branch_id', 'category_code', 'status', 'order_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_tickets');
        Schema::dropIfExists('queue_categories');
    }
};
```

---

## 2. Pendaftaran Service Provider

Daftarkan `QueueManager` ke dalam Service Container Laravel di `app/Providers/AppServiceProvider.php`:

```php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Wawaiguntang\TicketQueue\QueueManager;
use Wawaiguntang\TicketQueue\Storage\DatabaseStorage;
use Illuminate\Support\Facades\DB;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(QueueManager::class, function ($app) {
            $pdo = DB::connection()->getPdo();
            $storage = new DatabaseStorage($pdo, 'queue_tickets', 'queue_categories');
            return new QueueManager($storage);
        });
    }
}
```

---

## 3. Implementasi Controller

Gunakan file [QueueController.php](QueueController.php) di folder ini, atau pasang kode controller berikut pada proyek Anda:

```php
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
                'message' => 'Tidak ada antrean menunggu di cabang ini.',
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
                'call_count' => $ticket->getCallCount()
            ]
        ]);
    }

    // recall(), serve(), complete(), noResponse() dapat dicari langsung menggunakan UUID ticket ID
}
```

---

## 4. Rute API

Tambahkan rute API berikut di dalam `routes/api.php` Anda:

```php
use App\Http\Controllers\QueueController;
use Illuminate\Support\Facades\Route;

Route::prefix('queue')->group(function () {
    Route::post('take', [QueueController::class, 'takeTicket']);
    Route::post('call-next', [QueueController::class, 'callNext']);
    Route::post('{id}/recall', [QueueController::class, 'recall']);
    Route::post('{id}/serve', [QueueController::class, 'serve']);
    Route::post('{id}/complete', [QueueController::class, 'complete']);
    Route::post('{id}/no-response', [QueueController::class, 'noResponse']);
});
```
