<?php

use App\Jobs\DeliverNotice;
use App\Models\Notice;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('turnolab:alerts', function () {
    $count = 0;
    Notice::where('status', 'pending')->where('scheduled_at', '<=', now())->orderBy('id')->chunkById(100, function ($rows) use (&$count) {
        foreach ($rows as $n) {
            if (Notice::whereKey($n->id)->where('status', 'pending')->update(['status' => 'queued'])) {
                DeliverNotice::dispatch($n->id);
                $count++;
            }
        }
    });
    $this->info("Avisos enviados a la cola: {$count}");
})->purpose('Preparar avisos de recogida y retorno');
Schedule::command('turnolab:alerts')->everyMinute()->withoutOverlapping();
