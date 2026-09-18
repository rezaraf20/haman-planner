<?php
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'name' => 'Haman Planner',
    'status' => 'ok',
    'author' => 'Reza Rafiei',
]));
