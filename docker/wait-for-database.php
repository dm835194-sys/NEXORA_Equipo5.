<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
for ($attempt = 1; $attempt <= 30; $attempt++) {
    try {
        DB::connection()->getPdo();
        fwrite(STDOUT, "Database ready.\n");
        exit(0);
    } catch (Throwable $e) {
        if ($attempt === 30) {
            fwrite(STDERR, "Database unavailable after 30 attempts. Check private network and database variables.\n");
            exit(1);
        }sleep(2);
    }
}
