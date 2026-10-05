<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Pengingat harian untuk Dishub (jalankan `php artisan schedule:work` atau cron `schedule:run`).
Illuminate\Support\Facades\Schedule::command('usulan:pengingat')->dailyAt('08:00');
