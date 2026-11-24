<?php

use App\Http\Controllers\ImageBinaryController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ImageMetadataController;



Route::post('/images/binary', ImageBinaryController::class);

Route::post('/images/metadata', ImageMetadataController::class);