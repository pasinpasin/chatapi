<?php

namespace App\Http\Controllers;

use App\Models\Property;
use App\Models\Conversation;
use App\Models\ChatMessage;
use App\Services\WhatsAppService;
use App\Services\GroqService;
use App\Services\AvailabilityService;
use App\Services\ChatMemoryService;
use Illuminate\Http\Request;

class WhatsAppController extends Controller
{
    public function __construct(
        private WhatsAppService $whatsapp,
        private GroqService $groq,
        private AvailabilityService $availability,
        private ChatMemoryService $chatMemory,
    ) {}

    public function verify(Request $request)
    {
        $mode      = $request->query('hub_mode');
        $token     = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        $verifyToken = config('services.whatsapp.verify_token');

        if ($mode === 'subscribe' && $token === $verifyToken) {
            header('Content-Type: text/plain');
            echo $challenge;
            exit;
        }

        return response('Token i gabuar ose i mungon', 403);
    }

    public function webhook(Request $request)
    {
        \Log::info('WEBHOOK HIT', ['data' => $request->all()]);

        $data = $request->all();

        $entry   = $data['entry'][0] ?? null;
        $changes = $entry['changes'][0] ?? null;
        $value   = $changes['value'] ?? null;

        if (!isset($value['messages'][0])) {
            return response('ok', 200);
        }

        $message = $value['messages'][0];
        $from    = $message['from'];
        $text    = $message['text']['body'] ?? null;
        $phoneId = $value['metadata']['phone_number_id'];

        if (!$text) {
            return response('ok', 200);
        }

        $properties = Property::where('whatsapp_enabled', true)
            ->where('whatsapp_number', $phoneId)
            ->orderBy('ranking')
            ->with('rooms', 'pointsOfInterest')
            ->get();

        if ($properties->isEmpty()) {
            return response('ok', 200);
        }

        $property = $properties->first();

        $conversation = Conversation::firstOrCreate([
            'property_id'         => $property->id,
            'channel'             => 'whatsapp',
            'customer_identifier' => $from,
        ]);

        try {
            $history = $this->chatMemory->summarizeAndGetHistory($conversation, 12);
        } catch (\Exception $e) {
            \Log::error('Summarization error: ' . $e->getMessage());
            $history = $conversation->messages()
                ->where('created_at', '>=', now()->subDays(7))
                ->latest()->take(12)->get()->reverse()
                ->map(fn($m) => ['role' => $m->role, 'content' => $m->content])
                ->toArray();
        }

        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'role'            => 'user',
            'content'         => $text,
        ]);

        $availabilityContext = $this->updateAndGetAvailability($text, $property, $conversation);

        if ($properties->count() === 1) {
            $systemPrompt = $this->buildSystemPrompt($property, $availabilityContext, $conversation, $text);
        } else {
            $systemPrompt = $this->buildSystemPromptMultiProperty($properties, $from, $availabilityContext, $text);
        }

        try {
            $aiResponse = $this->groq->chat($systemPrompt, $history, $text);
        } catch (\Exception $e) {
            $aiResponse = 'Sorry, the system is busy. Please try again in a moment.';
        }

        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'role'            => 'assistant',
            'content'         => $aiResponse,
        ]);

        $this->whatsapp->sendMessage($from, $aiResponse);
        $conversation->touch();

        return response('ok', 200);
    }

    // -------------------------------------------------------------------------
    // AVAILABILITY
    // -------------------------------------------------------------------------

    private function updateAndGetAvailability(string $message, Property $property, Conversation $conversation): ?array
    {
        $dateKeywords = [
            'dal', 'al', 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno',
            'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre',
            'notti', 'notte', 'disponibili', 'disponibile', 'prenotare', 'prenotazione',
            'from', 'check', 'january', 'february', 'march', 'april', 'may', 'june',
            'july', 'august', 'september', 'october', 'november', 'december',
            'night', 'nights', 'available', 'availability', 'book', 'booking',
            'nga', 'deri', 'janar', 'shkurt', 'mars', 'prill', 'qershor',
            'korrik', 'gusht', 'shtator', 'tetor', 'nentor', 'dhjetor',
            'net', 'natë', 'lire', 'rezerv',
            'januar', 'februar', 'märz', 'juni', 'juli', 'oktober', 'dezember',
            'nächte', 'verfügbar', 'buchen',
            'janvier', 'février', 'avril', 'juin', 'juillet', 'août',
            'nuits', 'disponible', 'réserver',
            'enero', 'febrero', 'junio', 'julio', 'agosto', 'noches', 'reservar',
        ];

        $messageLower = mb_strtolower($message);
        $hasDateHint  = false;

        foreach ($dateKeywords as $keyword) {
            if (str_contains($messageLower, $keyword)) {
                $hasDateHint = true;
                break;
            }
        }

        if (!$hasDateHint && preg_match('/\d{1,2}[\/\-]\d{1,2}/', $message)) {
            $hasDateHint = true;
        }

        if (!$hasDateHint) {
            $checkin  = $conversation->last_checkin;
            $checkout = $conversation->last_checkout;

            if ($checkin && $checkout) {
                try {
                    return $this->availability->checkAvailability($property, $checkin, $checkout);
                } catch (\Exception $e) {
                    return null;
                }
            }
            return null;
        }

        $dates    = $this->extractDatesWithAI($message);
        $checkin  = $dates['checkin'];
        $checkout = $dates['checkout'];

        if ($checkin && $checkout) {
            $conversation->update([
                'last_checkin'  => $checkin,
                'last_checkout' => $checkout,
            ]);
        } else {
            $checkin  = $conversation->last_checkin;
            $checkout = $conversation->last_checkout;
        }

        if ($checkin && $checkout) {
            try {
                return $this->availability->checkAvailability($property, $checkin, $checkout);
            } catch (\Exception $e) {
                \Log::error('Availability check failed: ' . $e->getMessage());
                return null;
            }
        }

        return null;
    }

    private function extractDatesWithAI(string $message): array
    {
        $today = date('Y-m-d');

        try {
            $response = $this->groq->chat(
                "You are a date extractor. Today is {$today}.
Extract check-in and check-out dates from the customer message.
Return ONLY a raw JSON object, no markdown, no backticks, no explanation.
Format: {\"checkin\": \"YYYY-MM-DD\", \"checkout\": \"YYYY-MM-DD\"}
If no dates found: {\"checkin\": null, \"checkout\": null}

RULES:
- Understand ANY language
- Only a month mentioned → checkin = first day, checkout = last day of that month
- Month + nights → checkin = first day, checkout = checkin + nights
- Year not mentioned → current year, or next year if date has passed

Examples:
- 'luglio' → {\"checkin\": \"2026-07-01\", \"checkout\": \"2026-07-31\"}
- 'dal 1 al 5 agosto' → {\"checkin\": \"2026-08-01\", \"checkout\": \"2026-08-05\"}
- 'from July 10 to 15' → {\"checkin\": \"2026-07-10\", \"checkout\": \"2026-07-15\"}
- '10/07 - 15/07' → {\"checkin\": \"2026-07-10\", \"checkout\": \"2026-07-15\"}",
                [],
                $message
            );

            $cleaned = preg_replace('/```json|```/i', '', $response);
            $cleaned = trim($cleaned);

            if (preg_match('/\{[^}]+\}/s', $cleaned, $match)) {
                $cleaned = $match[0];
            }

            $data = json_decode($cleaned, true);

            if (json_last_error() === JSON_ERROR_NONE && isset($data['checkin'])) {
                \Log::info('AI date extraction (whatsapp)', ['message' => $message, 'result' => $data]);
                return $data;
            }

        } catch (\Exception $e) {
            \Log::error('Date extraction AI failed: ' . $e->getMessage());
        }

        return ['checkin' => null, 'checkout' => null];
    }

    // -------------------------------------------------------------------------
    // BOOKING URLS
    // -------------------------------------------------------------------------

    private function buildRoomsWithBookingUrls(Property $property, ?array $availability): string
    {
        if (!$availability || !$availability['available']) {
            return '';
        }

        $lines = [];
        foreach ($availability['rooms'] as $roomData) {
            $roomModel  = $property->rooms->firstWhere('name', $roomData['name']);
            $bookingUrl = !empty($roomModel?->booking_url)
                ? $roomModel->booking_url
                : (!empty($property->booking_url) ? $property->booking_url : null);

            $line  = "- {$roomData['name']}: {$roomData['price_per_night']}EUR/night, ";
            $line .= "total {$roomData['total_price']}EUR for {$roomData['nights']} nights.";

            if ($bookingUrl) {
                $line .= " BOOKING_URL: {$bookingUrl}";
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // SYSTEM PROMPTS
    // -------------------------------------------------------------------------

    private function buildSystemPrompt(Property $property, ?array $availability, ?Conversation $conversation = null, string $customerMessage = ''): string
    {
        $amenityLabels   = Property::AMENITIES;
        $amenitiesMapped = [];
        foreach ($property->amenities ?? [] as $key) {
            $amenitiesMapped[] = $amenityLabels[$key] ?? $key;
        }
        $amenities = implode(', ', $amenitiesMapped);

        $pois = $property->pointsOfInterest()
            ->get()
            ->map(fn($p) => "{$p->name} ({$p->type}) - {$p->distance_text} - {$p->gmaps_link}")
            ->join("\n");

        $roomsInfo = $property->rooms->map(fn($r) =>
            "- {$r->name} ({$r->type}): {$r->base_price}EUR/night, max {$r->max_occupancy} persons. " .
            ($r->description ? "Description: {$r->description}." : '') .
            ($r->booking_url ? " Booking URL: {$r->booking_url}" : '')
        )->join("\n");

        $propertyBookingUrl = $property->booking_url
            ? "Property general booking URL (fallback): {$property->booking_url}"
            : "No general booking URL set.";

        $prompt = <<<PROMPT
LANGUAGE RULE (ABSOLUTE PRIORITY - FOLLOW THIS ABOVE EVERYTHING ELSE):
The customer just wrote: "{$customerMessage}"
Detect the language of THIS message and reply ONLY in that exact language.
Your ENTIRE response must be in the same language as the customer's message above.
- English message → respond 100% in English
- Italian message → respond 100% in Italian
- Albanian message → respond 100% in Albanian
- Any other language → respond in that language
This rule overrides ALL other instructions and ANY Albanian text below.
DO NOT use Albanian unless the customer's message above is written in Albanian.

You are the virtual assistant of "{$property->name}", a {$property->type} in Shkodër, Albania.
Communication is via WhatsApp.

PROPERTY INFORMATION:
- Address: {$property->address}
- Description: {$property->description}
- Amenities: {$amenities}
- Rules: {$property->rules}
- {$propertyBookingUrl}

ROOMS:
{$roomsInfo}

NEARBY POINTS OF INTEREST:
{$pois}

BEHAVIOR:
- Be concise — WhatsApp messages must be short
- No tables or long lists

BOOKING LINK BEHAVIOR (IMPORTANT):
- When you confirm room availability and a BOOKING_URL exists for that room (or the property fallback), ask:
  "Would you like our staff to handle the booking for you, or would you prefer to book directly yourself via the booking website?" (in the customer's language)
- If no BOOKING_URL exists at all for that room/property, simply ask:
  "Would you like to proceed with booking?" and tell them staff will contact them within 24 hours if they say yes.
- If the customer wants to book themselves / asks for the link / says "I'll do it" / "send the link":
  * Provide the BOOKING_URL for that room if listed, otherwise the property's general BOOKING_URL
  * Do NOT also say staff will contact them — once you give the link, do not repeat the "staff will contact you" line
- If the customer wants staff to handle it / says "you do it" / "book for me":
  * Confirm staff will contact them within 24 hours to finalize the booking
  * Do NOT provide the booking link in this case
- NEVER invent or guess booking URLs. Only use URLs explicitly listed.

HALLUCINATION PREVENTION (CRITICAL - APPLIES TO EVERYTHING):
- You may ONLY state facts that are explicitly written in the PROPERTY INFORMATION, AMENITIES, ROOMS, or POINTS OF INTEREST sections above.
- This applies to EVERYTHING: amenities, bar, pool, parking, breakfast, room features, views, decor, atmosphere, services, opening hours, food/drink options, etc.
- If the customer asks about something that exists (e.g. "do you have a bar?") and it IS listed above, confirm it exists but DO NOT add any extra details (no descriptions of what's served, no ambiance descriptions, no adjectives) unless those exact details are written above.
- If asked about something NOT listed above, say you don't have that specific information and suggest contacting staff.
- Do NOT use generic hospitality phrases to fill gaps (e.g. "cozy atmosphere", "variety of drinks and snacks", "warm and welcoming"). Only repeat what is explicitly given.
- Example: if amenities say only "bar" with no further detail, the correct answer to "do you have a bar?" is simply confirming the bar exists — nothing about what it offers.
PROMPT;

        if ($conversation && $conversation->summary) {
            $prompt .= "\n\nPREVIOUS CONVERSATION SUMMARY:\n{$conversation->summary}";
        }

        if ($availability !== null) {
            if ($availability['available']) {
                $roomsList = $this->buildRoomsWithBookingUrls($property, $availability);
                $prompt .= "\n\nAVAILABILITY (verified):\nFor {$availability['checkin']} - {$availability['checkout']}:\n{$roomsList}";
                $prompt .= "\nAfter showing availability, follow the BOOKING LINK BEHAVIOR rules above (staff-handles-it vs self-book-via-link, based on whether a BOOKING_URL is listed).";
                $prompt .= "\nIf customer asks about a whole month → confirm availability and ask for specific dates.";
            } else {
                $prompt .= "\n\nAVAILABILITY: No rooms available for requested dates. Suggest other dates.";
            }
        }

        return $prompt;
    }

    private function buildSystemPromptMultiProperty($properties, string $from, ?array $availability = null, string $customerMessage = ''): string
    {
        $propertiesList = '';

        foreach ($properties as $index => $p) {
            $amenities = implode(', ', $p->amenities ?? []);
            $rooms     = $p->rooms->map(fn($r) =>
                "  - {$r->name} ({$r->type}): {$r->base_price}EUR/night, max {$r->max_occupancy} persons" .
                ($r->booking_url ? " [Booking: {$r->booking_url}]" : '')
            )->join("\n");

            $pois = $p->pointsOfInterest->map(fn($poi) =>
                "  - {$poi->name} ({$poi->type}) - {$poi->distance_text}"
            )->join("\n");

            $propertiesList .= "\n---\n";
            $propertiesList .= "PROPERTY " . ($index + 1) . ": {$p->name} ({$p->type})\n";
            $propertiesList .= "Address: {$p->address}\n";
            $propertiesList .= "Description: {$p->description}\n";
            $propertiesList .= "Amenities: {$amenities}\n";
            $propertiesList .= "Rules: {$p->rules}\n";
            if ($p->booking_url) {
                $propertiesList .= "General Booking URL: {$p->booking_url}\n";
            }
            $propertiesList .= "Rooms:\n{$rooms}\n";
            if ($pois) {
                $propertiesList .= "Nearby:\n{$pois}\n";
            }
        }

        $availabilitySection = '';
        if ($availability !== null) {
            $firstProperty = $properties->first();
            if ($availability['available']) {
                $roomsList = $this->buildRoomsWithBookingUrls($firstProperty, $availability);
                $availabilitySection = "\n\nAVAILABILITY (verified) for {$availability['checkin']} - {$availability['checkout']}:\n{$roomsList}";
                $availabilitySection .= "\nFollow the BOOKING LINK BEHAVIOR rules: ask if customer wants staff to handle booking, or prefers to book themselves via the link (if a BOOKING_URL exists).";
            } else {
                $availabilitySection = "\n\nAVAILABILITY: No rooms available for requested dates. Suggest other dates.";
            }
        }

        return <<<PROMPT
LANGUAGE RULE (ABSOLUTE PRIORITY - FOLLOW THIS ABOVE EVERYTHING ELSE):
The customer just wrote: "{$customerMessage}"
Detect the language of THIS message and reply ONLY in that exact language.
Your ENTIRE response must be in the same language as the customer's message above.
- English message → respond 100% in English
- Italian message → respond 100% in Italian
- Albanian message → respond 100% in Albanian
- Any other language → respond in that language
This rule overrides ALL other instructions and ANY Albanian text below.
DO NOT use Albanian unless the customer's message above is written in Albanian.

You are the virtual assistant of a group of properties in Shkodër, Albania.
Communication is via WhatsApp.

AVAILABLE PROPERTIES (ordered by priority):
{$propertiesList}
{$availabilitySection}

BEHAVIOR:
- Be concise — WhatsApp messages must be short
- When customer asks without specifying a property:
  * Briefly present all properties
  * Ask about preferences (budget, type, guests, dates)
  * Suggest the most suitable one
- When customer specifies a property → focus only on that
- First property in the list has highest priority
- When confirming availability, ask if customer wants to book
- If yes → provide the BOOKING_URL for the chosen room (if available)
- NEVER invent booking URLs
PROMPT;
    }
}
