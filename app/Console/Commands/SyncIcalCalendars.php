<?php

namespace App\Console\Commands;

use App\Services\IcalSyncService;
use Illuminate\Console\Command;

class SyncIcalCalendars extends Command
{
    protected $signature   = 'ical:sync';
    protected $description = 'Sync iCal calendars from Booking.com and Airbnb';

    public function handle(IcalSyncService $service): void
    {
        $this->info('Duke sinkronizuar kalendaret iCal...');

        $results = $service->syncAll();

        foreach ($results as $result) {
            if ($result['status'] === 'ok') {
                $this->info("✓ Link #{$result['link_id']} ({$result['source']}): {$result['synced']} blloqe.");
            } else {
                $this->error("✗ Link #{$result['link_id']} ({$result['source']}): {$result['error']}");
            }
        }

        $this->info('Sinkronizimi përfundoi.');
    }
}