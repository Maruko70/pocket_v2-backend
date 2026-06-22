<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return ['message' => 'Pocket API'];
});

require __DIR__.'/dashboard.php';
