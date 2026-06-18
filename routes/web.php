<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\IcalLinkController;
use App\Http\Controllers\PointOfInterestController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\WhatsAppController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PropertyController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\RoomPricingController;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::resource('properties', PropertyController::class);

    Route::resource('properties.rooms', RoomController::class)->shallow();
    Route::resource('rooms.pricing', RoomPricingController::class)->shallow();

    Route::resource('properties.points-of-interest', PointOfInterestController::class)
        ->shallow()
        ->parameters(['points-of-interest' => 'poi'])
        ->names([
            'index'   => 'properties.poi.index',
            'create'  => 'properties.poi.create',
            'store'   => 'properties.poi.store',
            'edit'    => 'poi.edit',
            'update'  => 'poi.update',
            'destroy' => 'poi.destroy',
        ]);

    Route::resource('properties.ical-links', IcalLinkController::class)
        ->shallow()
        ->parameters(['ical-links' => 'icalLink'])
        ->names([
            'index'   => 'properties.ical.index',
            'create'  => 'properties.ical.create',
            'store'   => 'properties.ical.store',
            'destroy' => 'ical.destroy',
        ]);

    Route::get('properties/{property}/conversations', [ConversationController::class, 'index'])
        ->name('properties.conversations.index');
    Route::get('conversations/{conversation}', [ConversationController::class, 'show'])
        ->name('conversations.show');

    Route::post('properties/{property}/ical-sync', [IcalLinkController::class, 'syncNow'])
        ->name('properties.ical.sync');
});

// Chat - publik (pa auth, pa CSRF)
Route::options('/chat/{property}/message', function() {
    return response('', 200)
        ->header('Access-Control-Allow-Origin', '*')
        ->header('Access-Control-Allow-Methods', 'POST, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type');
});

Route::get('/chat/{property:slug}', [ChatController::class, 'widget'])->name('chat.widget');



    Route::post('/chat/{property:slug}/message', [ChatController::class, 'message'])
    ->name('chat.message')
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);

Route::get('/widget/{property:slug}/embed.js', [ChatController::class, 'embedScript'])
    ->name('chat.embed');

Route::get('/test/hotel-website', function() {
    return view('test.hotel-website');
});

// Test routes
Route::get('/test-groq', function() {
    $service = new \App\Services\GroqService();
    $result = $service->chat('Ti je asistent hoteli.', [], 'Pershendetje, si je?');
    return response()->json(['result' => $result]);
});



Route::get('/webhook/whatsapp', [WhatsAppController::class, 'verify'])->name('whatsapp.verify');
Route::post('/webhook/whatsapp', [WhatsAppController::class, 'webhook'])->name('whatsapp.webhook');
require __DIR__.'/auth.php';