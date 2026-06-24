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
        $intent = $this->analyzeMessageIntent($message);

        if (!$intent['needs_availability']) {
            return null;
        }

        $checkin  = $intent['checkin'];
        $checkout = $intent['checkout'];

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

        if ($intent['booking_intent']) {
            return ['booking_intent_only' => true];
        }

        return null;
    }

    private function analyzeMessageIntent(string $message): array
    {
        $today = date('Y-m-d');

        try {
            $response = $this->groq->chat(
                "You are an intent analyzer for a hotel chat assistant. Today is {$today}.
Analyze the customer message and return ONLY a raw JSON object, no markdown, no backticks.

Format:
{
  \"needs_availability\": true or false,
  \"booking_intent\": true or false,
  \"checkin\": \"YYYY-MM-DD\" or null,
  \"checkout\": \"YYYY-MM-DD\" or null
}

RULES:
- needs_availability = true ONLY if the message contains BOTH a specific check-in AND check-out date, OR if customer expresses clear booking intent
- needs_availability = false if customer mentions only a month, season, or year without specific days — in this case the AI should ask for exact dates
- booking_intent = true if customer wants to make a reservation in ANY language
- Dates must be SPECIFIC (day + month) to extract. A month name alone is NOT enough.
- Year not mentioned → use current year, or next year if date already passed

Examples where needs_availability = true (specific dates given):
- \"from July 10 to 15\" → {\"needs_availability\":true,\"booking_intent\":false,\"checkin\":\"2026-07-10\",\"checkout\":\"2026-07-15\"}
- \"dal 1 al 5 agosto\" → {\"needs_availability\":true,\"booking_intent\":false,\"checkin\":\"2026-08-01\",\"checkout\":\"2026-08-05\"}
- \"10/07 - 15/07\" → {\"needs_availability\":true,\"booking_intent\":false,\"checkin\":\"2026-07-10\",\"checkout\":\"2026-07-15\"}
- \"I want to book from July 10 to 15\" → {\"needs_availability\":true,\"booking_intent\":true,\"checkin\":\"2026-07-10\",\"checkout\":\"2026-07-15\"}
- \"vorrei prenotare dal 3 al 7 luglio\" → {\"needs_availability\":true,\"booking_intent\":true,\"checkin\":\"2026-07-03\",\"checkout\":\"2026-07-07\"}

Examples where needs_availability = false (only month/vague — ask for specific dates):
- \"do you have rooms in july?\" → {\"needs_availability\":false,\"booking_intent\":false,\"checkin\":null,\"checkout\":null}
- \"tienen habitaciones disponibles en julio?\" → {\"needs_availability\":false,\"booking_intent\":false,\"checkin\":null,\"checkout\":null}
- \"avete stanze libere in luglio?\" → {\"needs_availability\":false,\"booking_intent\":false,\"checkin\":null,\"checkout\":null}
- \"haben Sie Zimmer im Juli?\" → {\"needs_availability\":false,\"booking_intent\":false,\"checkin\":null,\"checkout\":null}
- \"keni dhoma ne korrik?\" → {\"needs_availability\":false,\"booking_intent\":false,\"checkin\":null,\"checkout\":null}
- \"7月に部屋はありますか\" → {\"needs_availability\":false,\"booking_intent\":false,\"checkin\":null,\"checkout\":null}

Examples where needs_availability = true (booking intent, no dates yet):
- \"vorrei fare una prenotazione\" → {\"needs_availability\":true,\"booking_intent\":true,\"checkin\":null,\"checkout\":null}
- \"quiero hacer una reserva\" → {\"needs_availability\":true,\"booking_intent\":true,\"checkin\":null,\"checkout\":null}
- \"予約したい\" → {\"needs_availability\":true,\"booking_intent\":true,\"checkin\":null,\"checkout\":null}
- \"أريد الحجز\" → {\"needs_availability\":true,\"booking_intent\":true,\"checkin\":null,\"checkout\":null}

Examples where needs_availability = false (unrelated):
- \"do you have a pool?\" → {\"needs_availability\":false,\"booking_intent\":false,\"checkin\":null,\"checkout\":null}
- \"avete il bar?\" → {\"needs_availability\":false,\"booking_intent\":false,\"checkin\":null,\"checkout\":null}",
                [],
                $message
            );

            $cleaned = preg_replace('/```json|```/i', '', $response);
            $cleaned = trim($cleaned);

            if (preg_match('/\{.*\}/s', $cleaned, $match)) {
                $cleaned = $match[0];
            }

            $data = json_decode($cleaned, true);

            if (json_last_error() === JSON_ERROR_NONE && isset($data['needs_availability'])) {
                \Log::info('AI intent analysis (whatsapp)', ['message' => $message, 'result' => $data]);
                return $data;
            }

        } catch (\Exception $e) {
            \Log::error('Intent analysis failed (whatsapp): ' . $e->getMessage());
        }

        return ['needs_availability' => false, 'booking_intent' => false, 'checkin' => null, 'checkout' => null];
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

        $propertyBookingUrl = !empty($property->booking_url)
            ? "Property general booking URL (fallback): {$property->booking_url}"
            : "No general booking URL set.";

        $contactLines = [];
        if (!empty($property->contact_email)) $contactLines[] = "Email: {$property->contact_email}";
        if (!empty($property->contact_phone)) $contactLines[] = "Phone: {$property->contact_phone}";
        $contactInfo = !empty($contactLines)
            ? implode(' | ', $contactLines)
            : "not provided";

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
- Staff contact: {$contactInfo}

ROOMS:
{$roomsInfo}

NEARBY POINTS OF INTEREST:
{$pois}

BEHAVIOR:
- Be concise — WhatsApp messages must be short
- No tables or long lists
- If the customer asks about booking WITHOUT providing dates, ask for check-in and check-out dates

BOOKING LINK BEHAVIOR (IMPORTANT):
- A BOOKING_URL may be listed next to each room above, or as the property general booking URL.
- When availability is confirmed OR when customer expresses clear intent to book:
  * If a BOOKING_URL exists → ask: "Would you like to book directly via the booking website, or would you prefer our staff to assist you?" (in customer's language, keep it short)
  * If NO BOOKING_URL exists → give customer the staff contact details
- If the customer wants to book themselves / says "I'll do it" / "send the link" / "direct booking":
  → provide the BOOKING_URL only; do NOT mention staff
- If the customer wants staff to handle it / says "you do it" / "book for me" / "staff booking" / any equivalent in ANY language:
  → give ONLY the staff contact details (email/phone); do NOT show the booking link again; do NOT ask again
- If the customer already expressed a clear preference earlier in the conversation, respect it — do NOT repeat the question
- NEVER invent booking URLs or contact details. Only use what is listed above.

UNKNOWN INFORMATION BEHAVIOR (IMPORTANT):
- If asked something not in the data above, say you don't have that information and provide staff contact details.
- Example: "I don't have details on that. Contact our staff: {$contactInfo}"
- NEVER make up details.

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
            if (!empty($availability['booking_intent_only'])) {
                $bookingUrl = $property->booking_url ?? null;
                if ($bookingUrl) {
                    $prompt .= "\n\nBOOKING INTENT: Customer wants to make a reservation but has not provided dates yet.";
                    $prompt .= "\nProperty booking URL available: {$bookingUrl}";
                    $prompt .= "\nAsk for check-in and check-out dates, AND follow BOOKING LINK BEHAVIOR.";
                } else {
                    $prompt .= "\n\nBOOKING INTENT: Customer wants to make a reservation. Ask for check-in and check-out dates.";
                }
            } elseif (!empty($availability['available'])) {
                $roomsList = $this->buildRoomsWithBookingUrls($property, $availability);
                $prompt .= "\n\nAVAILABILITY (verified):\nFor {$availability['checkin']} - {$availability['checkout']}:\n{$roomsList}";
                $prompt .= "\nAfter showing availability, follow the BOOKING LINK BEHAVIOR rules above.";
                $prompt .= "\nIf customer asked about a whole month → confirm availability and ask for specific dates.";
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