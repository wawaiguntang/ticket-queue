<?php

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

    public function boot(): void
    {
        //
    }
}
