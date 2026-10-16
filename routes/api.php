<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Central\ChatbotController;

Route::post('/chat/stream', [ChatbotController::class, 'handlePublicStream']);
Route::post('/chatbot/tts', [ChatbotController::class, 'generateVoice']);