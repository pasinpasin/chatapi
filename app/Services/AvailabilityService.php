<?php

namespace App\Services;

use App\Models\Property;
use App\Models\Room;
use Carbon\Carbon;

class AvailabilityService
{
    /**
     * Kontrollo disponueshmërinë për të gjitha dhomat e një prone
     */
    public function checkAvailability(Property $property, string $checkin, string $checkout): array
    {
        $checkinDate  = Carbon::parse($checkin);
        $checkoutDate = Carbon::parse($checkout);
        $nights       = $checkinDate->diffInDays($checkoutDate);

        if ($nights <= 0) {
            return ['available' => false, 'message' => 'Datat janë të pavlefshme.'];
        }

        $availableRooms = [];

        foreach ($property->rooms as $room) {
            if ($this->isRoomAvailable($room, $checkinDate, $checkoutDate)) {
                $price              = $this->getPriceForPeriod($room, $checkinDate, $checkoutDate);
                $availableRooms[]   = [
                    'name'          => $room->name,
                    'type'          => $room->type,
                    'max_occupancy' => $room->max_occupancy,
                    'price_per_night' => $price,
                    'total_price'   => $price * $nights,
                    'nights'        => $nights,
                    'description'   => $room->description,
                ];
            }
        }

        return [
            'available' => count($availableRooms) > 0,
            'rooms'     => $availableRooms,
            'checkin'   => $checkinDate->format('d/m/Y'),
            'checkout'  => $checkoutDate->format('d/m/Y'),
            'nights'    => $nights,
        ];
    }

    private function isRoomAvailable(Room $room, Carbon $checkin, Carbon $checkout): bool
{
    return !$room->availabilityBlocks()
        ->where(function ($q) use ($checkin, $checkout) {
            $q->whereDate('start_date', '<', $checkout->format('Y-m-d'))
              ->whereDate('end_date', '>', $checkin->format('Y-m-d'));
        })
        ->exists();
}

    private function getPriceForPeriod(Room $room, Carbon $checkin, Carbon $checkout): float
    {
        // Kontrollo nese ka çmim sezonal per kete periudhe
        $specialPricing = $room->pricing()
            ->where('start_date', '<=', $checkin->format('Y-m-d'))
            ->where('end_date', '>=', $checkout->format('Y-m-d'))
            ->orderBy('price', 'desc')
            ->first();

        return $specialPricing ? (float) $specialPricing->price : (float) $room->base_price;
    }
}
