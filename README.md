# wawaiguntang/ticket-queue

[![PHP Version](https://img.shields.io/badge/php-%3E%3D%208.0-blue.svg)](https://packagist.org/packages/wawaiguntang/ticket-queue)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)
[![Build Status](https://img.shields.io/badge/tests-passing-brightgreen.svg)]()

Library PHP *framework-agnostic* (independen) untuk mengelola antrean loket/poliklinik pada Sistem Informasi Manajemen Rumah Sakit (SIMRS) dengan dukungan **Multi-Cabang (Multi-Branch/Multi-Tenancy)** dan fitur cerdas **penjadwalan ulang otomatis (*auto-reschedule*)** saat pasien tidak merespons panggilan loket.

---

## Fitur Utama

1. **Dukungan Multi-Cabang Penuh (*Multi-Branch Support*):** Mengisolasi antrean, kategori, dan urutan secara terjamin per cabang rumah sakit menggunakan sistem *Composite Keys* pada database tunggal maupun terpisah.
2. **Siklus Hidup Tiket Lengkap (*Ticket Lifecycle State Machine*):** Mengatur perpindahan status antrean secara aman (`WAITING` -> `CALLING` -> `SERVING` -> `COMPLETED`/`SKIPPED`).
3. **Aturan Pemanggilan Ulang Cerdas (*Smart Auto-Skip & Reschedule*):** Jika pasien dipanggil sebanyak `X` kali (misal: 3x) dan tidak ada respons, sistem akan otomatis menjadwal ulang nomor tiket tersebut sebanyak `Y` urutan ke bawah (misal: mundur 5 antrean) di cabang terkait tanpa tabrakan indeks.
4. **Independen Penyimpanan (*Storage-Agnostic*):** Dilengkapi dengan adapter siap pakai untuk **Database Relasional (SQL/PDO)** dan **In-Memory Storage** (sangat berguna untuk unit testing).
5. **Rujukan/Transfer Antrean Internal:** Memudahkan pemindahan pasien antar-unit di cabang yang sama (contoh: dari Pendaftaran ke Poliklinik, atau dari Poliklinik ke Apotek) tanpa membuat antrean baru dari awal secara manual.

---

## Struktur Direktori

```text
├── example/
│   ├── laravel/          # Panduan & file integrasi lengkap untuk Laravel
│   └── codeigniter4/     # Panduan & file integrasi lengkap untuk CodeIgniter 4
├── src/
│   ├── Domain/           # Entitas inti (Ticket, Category, Status)
│   ├── Exception/        # Penanganan error pustaka (Exception)
│   ├── Storage/          # Driver penyimpanan (PDO, In-Memory)
│   └── QueueManager.php  # Service utama pengendali logika bisnis antrean
└── tests/                # Unit testing PHPUnit
```

---

## Penggunaan Cepat (Quickstart)

```php
use Wawaiguntang\TicketQueue\QueueManager;
use Wawaiguntang\TicketQueue\Storage\InMemoryStorage;
use Wawaiguntang\TicketQueue\Domain\Category;

// 1. Inisialisasi Storage & Manager (Contoh menggunakan In-Memory)
$storage = new InMemoryStorage();
$manager = new QueueManager($storage);

// 2. Daftarkan Kategori Antrean untuk CABANG_1 (max_recalls = 3 kali, reschedule_offset = 5)
$category = new Category('CABANG_1', 'A', 'Admisi Pendaftaran', 3, 5);
$storage->saveCategory($category);

// 3. Pasien Mengambil Tiket Baru di CABANG_1
$ticket = $manager->takeTicket('CABANG_1', 'A');
echo $ticket->getNumber(); // Output: A-001
echo $ticket->getBranchId(); // Output: CABANG_1

// 4. Operator Loket 1 Memanggil Antrean Berikutnya di CABANG_1
$calledTicket = $manager->callNext('CABANG_1', 'A', 'LOKET_1');
echo $calledTicket->getNumber(); // Output: A-001
echo $calledTicket->getStatus(); // Output: calling
echo $calledTicket->getCallCount(); // Output: 1 (Panggilan ke-1)

// 5. Jika Pasien Tidak Datang setelah Panggilan ke-3
$manager->recall($calledTicket->getId()); // Panggilan ke-2
$manager->recall($calledTicket->getId()); // Panggilan ke-3

// Operator melaporkan "Tidak Ada Respon" (No-Show)
// Karena call_count (3) >= max_recalls (3), tiket otomatis dijadwal ulang (postponed) mundur 5 antrean ke bawah
$processedTicket = $manager->registerNoResponse($calledTicket->getId());
echo $processedTicket->getStatus(); // Output: waiting
echo $processedTicket->getOrderIndex(); // Output: 7 (Mundur ke posisi baru di CABANG_1)
```

---

## Panduan Integrasi Framework

Untuk kemudahan implementasi pada sistem SIMRS Anda, kami telah menyediakan dokumentasi integrasi lengkap beserta kode migrasi dan controller:

- [Panduan Integrasi Laravel](example/laravel/README.md)
- [Panduan Integrasi CodeIgniter 4](example/codeigniter4/README.md)

---

## Lisensi

Library ini dirilis di bawah lisensi [MIT License](LICENSE).
