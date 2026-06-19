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

    /**
     * Meta e thërret këtë për të verifikuar webhook
     */
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

    /**
     * Meta dërgon mesazhet këtu
     */
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

        // Gjej ose krijo conversation
        $conversation = Conversation::firstOrCreate(
            [
                'property_id'         => $property->id,
                'channel'             => 'whatsapp',
                'customer_identifier' => $from,
            ]
        );

        // Merr historikun
        try {
            $history = $this->chatMemory->summarizeAndGetHistory($conversation, 12);
        } catch (\Exception $e) {
            \Log::error('Gabim gjatë summarization në WhatsApp: ' . $e->getMessage());
            $history = $conversation->messages()
                ->where('created_at', '>=', now()->subDays(7))
                ->latest()
                ->take(12)
                ->get()
                ->reverse()
                ->map(fn($m) => ['role' => $m->role, 'content' => $m->content])
                ->toArray();
        }

        // Ruaj mesazhin e klientit
        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'role'            => 'user',
            'content'         => $text,
        ]);

        // Kontrollo disponueshmërinë
        $availabilityContext = $this->updateAndGetAvailability($text, $property, $conversation);

        // Nderto system prompt
        if ($properties->count() === 1) {
            $systemPrompt = $this->buildSystemPrompt($property, $availabilityContext, $conversation, $text);
        } else {
            $systemPrompt = $this->buildSystemPromptMultiProperty($properties, $from, $availabilityContext, $text);
        }

        // Thirr AI
        try {
            $aiResponse = $this->groq->chat($systemPrompt, $history, $text);
        } catch (\Exception $e) {
            $aiResponse = 'Sorry, the system is busy. Please try again in a moment.';
        }

        // Ruaj përgjigjen
        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'role'            => 'assistant',
            'content'         => $aiResponse,
        ]);

        // Dërgo përgjigjen te klienti
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
            // Italisht
            'dal', 'al', 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno',
            'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre',
            'notti', 'notte', 'disponibili', 'disponibile', 'prenotare', 'prenotazione',
            // Anglisht
            'from', 'check', 'january', 'february', 'march', 'april', 'may', 'june',
            'july', 'august', 'september', 'october', 'november', 'december',
            'night', 'nights', 'available', 'availability', 'book', 'booking',
            // Shqip
            'nga', 'deri', 'janar', 'shkurt', 'mars', 'prill', 'qershor',
            'korrik', 'gusht', 'shtator', 'tetor', 'nentor', 'dhjetor',
            'net', 'natë', 'lire', 'rezerv',
            // Gjermanisht
            'januar', 'februar', 'märz', 'juni', 'juli', 'oktober', 'dezember',
            'nächte', 'verfügbar', 'buchen',
            // Frëngjisht
            'janvier', 'février', 'avril', 'juin', 'juillet', 'août',
            'nuits', 'disponible', 'réserver',
            // Spanjisht
            'enero', 'febrero', 'junio', 'julio', 'agosto',
            'noches', 'reservar',
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

IMPORTANT RULES:
- Understand ANY language (Italian, English, Albanian, German, French, Spanish, Chinese, Arabic, etc.)
- If customer mentions ONLY a month without specific days:
  → checkin = first day of that month (YYYY-MM-01)
  → checkout = last day of that month (YYYY-MM-28/29/30/31)
- If customer mentions a month + number of nights:
  → checkin = first day of that month
  → checkout = checkin + number of nights
- If year not mentioned, use {$today}'s year unless the date has already passed, then use next year

Examples:
- 'luglio' → {\"checkin\": \"2026-07-01\", \"checkout\": \"2026-07-31\"}
- 'in luglio' → {\"checkin\": \"2026-07-01\", \"checkout\": \"2026-07-31\"}
- 'dal 1 al 5 agosto' → {\"checkin\": \"2026-08-01\", \"checkout\": \"2026-08-05\"}
- 'from July 10 to 15' → {\"checkin\": \"2026-07-10\", \"checkout\": \"2026-07-15\"}
- 'july' → {\"checkin\": \"2026-07-01\", \"checkout\": \"2026-07-31\"}
- '10/07 - 15/07' → {\"checkin\": \"2026-07-10\", \"checkout\": \"2026-07-15\"}
- '8月1日到5日' → {\"checkin\": \"2026-08-01\", \"checkout\": \"2026-08-05\"}
- 'nga 10 korriku deri 15' → {\"checkin\": \"2026-07-10\", \"checkout\": \"2026-07-15\"}",
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
            \Log::error('Date extraction AI failed (whatsapp): ' . $e->getMessage());
        }

        return ['checkin' => null, 'checkout' => null];
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
            "- {$r->name} ({$r->type}): Base price {$r->base_price}EUR/night (Max occupancy: {$r->max_occupancy} persons). Description: " . ($r->description ?: 'No additional description.')
        )->join("\n");

        $prompt = <<<PROMPT
LANGUAGE RULE (ABSOLUTE PRIORITY - FOLLOW THIS ABOVE EVERYTHING ELSE):
The customer just wrote: "{$customerMessage}"
Detect the language of THIS message and reply ONLY in that exact language.
Your ENTIRE response must be in the same language as the customer's message above.
- If the message is in English → respond 100% in English
- If the message is in Italian → respond 100% in Italian
- If the message is in Albanian → respond 100% in Albanian
- If the message is in any other language → respond in that language
This rule overrides ALL other instructions and ANY Albanian text you see below.
DO NOT use Albanian unless the customer's message above is written in Albanian.

You are the virtual assistant of "{$property->name}", a {$property->type} in Shkodër, Albania.
Communication is via WhatsApp.

PROPERTY INFORMATION:
- Address: {$property->address}
- Description: {$property->description}
- Amenities: {$amenities}
- Rules: {$property->rules}

AVAILABLE ROOMS (base prices and descriptions):
{$roomsInfo}

NEARBY POINTS OF INTEREST:
{$pois}

BEHAVIOR:
- Be concise — WhatsApp messages must be short
- Avoid excessive formatting (no tables, no long lists)
- If the customer asks about booking, ask for check-in and check-out dates
- For final bookings, say that staff will contact them within 24 hours

HALLUCINATION PREVENTION:
- NEVER invent or assume details about rooms (sea/mountain view, balcony, bed type, etc.) if not explicitly written in the description above.
- If the customer asks for a detail not in the description, politely say you do not have that information and suggest they contact staff or ask upon arrival.
PROMPT;

        if ($conversation && $conversation->summary) {
            $prompt .= "\n\nPREVIOUS CONVERSATION SUMMARY (for reference):\n{$conversation->summary}";
        }

        if ($availability !== null) {
            if ($availability['available']) {
                $roomsList = '';
                foreach ($availability['rooms'] as $room) {
                    $roomsList .= "\n- {$room['name']}: {$room['price_per_night']}EUR/night, total {$room['total_price']}EUR for {$room['nights']} nights.";
                }
                $prompt .= "\n\nAVAILABILITY (verified):\nFor {$availability['checkin']} - {$availability['checkout']}:{$roomsList}";
                $prompt .= "\nNOTE: If the customer asked about a whole month, confirm rooms are available and ask for their specific dates.";
            } else {
                $prompt .= "\n\nAVAILABILITY: No rooms available for the requested dates.";
                $prompt .= "\nNOTE: If the customer asked about a whole month, mention availability varies and suggest they provide specific dates.";
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
                "  - {$r->name} ({$r->type}): {$r->base_price}EUR/night, max {$r->max_occupancy} persons"
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
            $propertiesList .= "Rooms:\n{$rooms}\n";
            if ($pois) {
                $propertiesList .= "Nearby:\n{$pois}\n";
            }
        }

        $availabilitySection = '';
        if ($availability !== null) {
            if ($availability['available']) {
                $roomsList = '';
                foreach ($availability['rooms'] as $room) {
                    $roomsList .= "\n- {$room['name']}: {$room['price_per_night']}EUR/night, total {$room['total_price']}EUR for {$room['nights']} nights.";
                }
                $availabilitySection = "\n\nAVAILABILITY (verified) for {$availability['checkin']} - {$availability['checkout']}:{$roomsList}";
                $availabilitySection .= "\nNOTE: If the customer asked about a whole month, confirm availability and ask for specific dates.";
            } else {
                $availabilitySection = "\n\nAVAILABILITY: No rooms available for the requested dates. Suggest other dates.";
            }
        }

        return <<<PROMPT
LANGUAGE RULE (ABSOLUTE PRIORITY - FOLLOW THIS ABOVE EVERYTHING ELSE):
The customer just wrote: "{$customerMessage}"
Detect the language of THIS message and reply ONLY in that exact language.
Your ENTIRE response must be in the same language as the customer's message above.
- If the message is in English → respond 100% in English
- If the message is in Italian → respond 100% in Italian
- If the message is in Albanian → respond 100% in Albanian
- If the message is in any other language → respond in that language
This rule overrides ALL other instructions and ANY Albanian text you see below.
DO NOT use Albanian unless the customer's message above is written in Albanian.

You are the virtual assistant of a group of properties in Shkodër, Albania.
Communication is via WhatsApp.

AVAILABLE PROPERTIES (ordered by priority):
{$propertiesList}
{$availabilitySection}

BEHAVIOR:
- Be concise — WhatsApp messages must be short
- When customer asks about availability WITHOUT specifying a property:
  * Briefly present all properties
  * Ask about preferences (budget, type, number of guests, dates)
  * Based on reply, suggest the most suitable property
- When customer specifies a property or room, focus only on that one
- ALWAYS include property name and room name in availability replies
- First property in the list has highest priority
- For final bookings, say that staff will contact them within 24 hours
PROMPT;
    }
}