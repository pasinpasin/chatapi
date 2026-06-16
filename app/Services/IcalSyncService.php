<?php

namespace App\Services;

use App\Models\PropertyIcalLink;
use App\Models\RoomAvailabilityBlock;
use Sabre\VObject\Reader;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IcalSyncService
{
    public function syncAll(): array
    {
        $links   = PropertyIcalLink::with(['property', 'room'])->get();
        $results = [];

        foreach ($links as $link) {
            try {
                $count     = $this->syncLink($link);
                $results[] = [
                    'link_id'  => $link->id,
                    'source'   => $link->source,
                    'synced'   => $count,
                    'status'   => 'ok',
                ];
            } catch (\Exception $e) {
                Log::error("iCal sync failed for link {$link->id}: " . $e->getMessage());
                $results[] = [
                    'link_id' => $link->id,
                    'source'  => $link->source,
                    'status'  => 'error',
                    'error'   => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    public function syncLink(PropertyIcalLink $link): int
    {
        // Shkarko .ics file
        $response = Http::timeout(30)
            ->withoutVerifying()
            ->get($link->ical_url);

        if (!$response->successful()) {
            throw new \Exception("HTTP error: " . $response->status());
        }

        $calendar = Reader::read($response->body(), Reader::OPTION_FORGIVING);

        if (!isset($calendar->VEVENT)) {
            return 0;
        }

        $synced = 0;
        $uids   = [];

        // Merr room_id - ose nga link direkt ose dhoma e pare e prones
        $roomId = $link->room_id ?? $this->getFirstRoomId($link);

        if (!$roomId) {
            throw new \Exception("Prona nuk ka dhoma. Shto dhoma para se te sync-osh.");
        }

        foreach ($calendar->VEVENT as $event) {
            $uid = (string) $event->UID;

            // Merr datat - trajto si DATE ose DATETIME
            $startDate = $event->DTSTART->getDateTime()->format('Y-m-d');
            $endDate   = $event->DTEND->getDateTime()->format('Y-m-d');

            $uids[] = $uid;

            RoomAvailabilityBlock::updateOrCreate(
                [
                    'uid'     => $uid,
                    'room_id' => $roomId,
                ],
                [
                    'start_date' => $startDate,
                    'end_date'   => $endDate,
                    'source'     => 'ical',
                ]
            );

            $synced++;
        }

        // Fshi bllokat e vjetra (rezervime te kancelluara ne Booking/Airbnb)
        if (!empty($uids)) {
            $deleted = RoomAvailabilityBlock::where('room_id', $roomId)
                ->where('source', 'ical')
                ->whereNotIn('uid', $uids)
                ->delete();

            if ($deleted > 0) {
                Log::info("iCal sync: fshi {$deleted} blloqe te vjetra per room {$roomId}");
            }
        }

        $link->update(['last_synced_at' => now()]);

        return $synced;
    }

    private function getFirstRoomId(PropertyIcalLink $link): ?int
    {
        return $link->property->rooms()->first()?->id;
    }
}