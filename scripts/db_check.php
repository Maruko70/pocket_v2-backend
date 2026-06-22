<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$checks = [
    "SHOW TABLES LIKE 'media'" => 'media_table',
    "SELECT COUNT(*) AS c FROM doctors" => 'doctors_count',
    "SELECT COUNT(*) AS c FROM complexes" => 'complexes_count',
    "SELECT COUNT(*) AS c FROM users" => 'users_count',
    "SELECT COUNT(*) AS c FROM reservations" => 'reservations_count',
    "SELECT COUNT(*) AS c FROM doctor_complex" => 'doctor_complex_count',
];

$out = [];
foreach ($checks as $sql => $key) {
    try {
        $out[$key] = DB::select($sql);
    } catch (Throwable $e) {
        $out[$key] = ['error' => $e->getMessage()];
    }
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

