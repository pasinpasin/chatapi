<?php

namespace App\Http\Controllers;

use App\Models\Property;
use App\Models\Conversation;
use App\Models\ChatMessage;
use App\Services\GroqService;
use App\Services\AvailabilityService;
use App\Services\ChatMemoryService;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function __construct(
        private GroqService $gemini,
        private AvailabilityService $availability,
        private ChatMemoryService $chatMemory,
    ) {}

    public function widget(Property $property)
    {
        if (!$property->webchat_enabled) {
            abort(404);
        }
        return view('chat.widget', compact('property'));
    }

    public function message(Request $request, Property $property)
    {
        $request->validate([
            'message'         => 'required|string|max:1000',
            'conversation_id' => 'nullable|integer',
        ]);

        try {
            $conversation = $this->getOrCreateConversation($request, $property);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Hapi 1 - Conversation: ' . $e->getMessage()], 500);
        }

        try {
            $history = $this->chatMemory->summarizeAndGetHistory($conversation, 8);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Hapi 2 - History: ' . $e->getMessage()], 500);
        }

        try {
            ChatMessage::create([
                'conversation_id' => $conversation->id,
                'role'            => 'user',
                'content'         => $request->message,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Hapi 3 - ChatMessage: ' . $e->getMessage()], 500);
        }

        try {
            $availabilityContext = $this->updateAndGetAvailability($request->message, $property, $conversation);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Hapi 4 - Availability: ' . $e->getMessage()], 500);
        }

        try {
            $systemPrompt = $this->buildSystemPrompt($property, $availabilityContext, $conversation, $request->message);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Hapi 5 - SystemPrompt: ' . $e->getMessage()], 500);
        }

        try {
            $aiResponse = $this->gemini->chat($systemPrompt, $history, $request->message);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Hapi 6 - AI: ' . $e->getMessage()], 500);
        }

        try {
            ChatMessage::create([
                'conversation_id' => $conversation->id,
                'role'            => 'assistant',
                'content'         => $aiResponse,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Hapi 7 - Save: ' . $e->getMessage()], 500);
        }

        return response()->json([
            'message'         => $aiResponse,
            'conversation_id' => $conversation->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // CONVERSATION
    // -------------------------------------------------------------------------

    private function getOrCreateConversation(Request $request, Property $property): Conversation
    {
        if ($request->conversation_id) {
            $conv = Conversation::where('id', $request->conversation_id)
                ->where('property_id', $property->id)
                ->first();
            if ($conv) return $conv;
        }

        return Conversation::create([
            'property_id'         => $property->id,
            'channel'             => 'web',
            'customer_identifier' => session()->getId(),
        ]);
    }

    // -------------------------------------------------------------------------
    // AVAILABILITY
    // -------------------------------------------------------------------------

    private function updateAndGetAvailability(string $message, Property $property, Conversation $conversation): ?array
    {
        // Thirrje e vetme AI - detekton qellimin dhe ekstrakton datat njekohesisht
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
            // Nuk ka data te reja - merri nga memoria e bisedes
            $checkin  = $conversation->last_checkin;
            $checkout = $conversation->last_checkout;
        }

        if ($checkin && $checkout) {
            try {
                $result = $this->availability->checkAvailability($property, $checkin, $checkout);
                \Log::info('AVAILABILITY RESULT', [
                    'checkin'   => $checkin,
                    'checkout'  => $checkout,
                    'available' => $result['available'] ?? 'unknown',
                    'rooms'     => $result['rooms'] ?? [],
                ]);
                return $result;
            } catch (\Exception $e) {
                \Log::error('Availability check failed: ' . $e->getMessage());
                return null;
            }
        }

        // Ka qellim rezervimi por nuk ka data ende - sinjalo te buildSystemPrompt
        if ($intent['booking_intent']) {
            return ['booking_intent_only' => true];
        }

        return null;
    }

    private function analyzeMessageIntent(string $message): array
    {
        $today = date('Y-m-d');

        try {
            $response = $this->gemini->chat(
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

Examples where needs_availability = false (only month/vague date — ask for specific dates):
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
                \Log::info('AI intent analysis (web)', ['message' => $message, 'result' => $data]);
                return $data;
            }

        } catch (\Exception $e) {
            \Log::error('Intent analysis failed (web): ' . $e->getMessage());
        }

        return ['needs_availability' => false, 'booking_intent' => false, 'checkin' => null, 'checkout' => null];
    }

    // -------------------------------------------------------------------------
    // BOOKING URLS
    // -------------------------------------------------------------------------

    /**
     * Nderto listën e dhomave me booking URL (room > property fallback)
     */
    private function buildRoomsWithBookingUrls(Property $property, ?array $availability): string
    {
        if (!$availability || !$availability['available']) {
            return '';
        }

        $lines = [];
        foreach ($availability['rooms'] as $roomData) {
            // Gjej modelin e dhomës për të marrë booking_url
            $roomModel = $property->rooms->firstWhere('name', $roomData['name']);
            $bookingUrl = !empty($roomModel?->booking_url)
                ? $roomModel->booking_url
                : (!empty($property->booking_url) ? $property->booking_url : null);

            \Log::info('BOOKING URL DEBUG', [
                'roomData_name'      => $roomData['name'],
                'roomModel_found'    => $roomModel ? true : false,
                'roomModel_name'     => $roomModel?->name,
                'room_booking_url'   => $roomModel?->booking_url,
                'property_booking_url' => $property->booking_url,
                'final_bookingUrl'   => $bookingUrl,
                'available_room_names' => $property->rooms->pluck('name')->toArray(),
            ]);

            $line  = "- {$roomData['name']} ({$roomData['type']}): ";
            $line .= "{$roomData['price_per_night']}EUR/night, ";
            $line .= "total {$roomData['total_price']}EUR for {$roomData['nights']} nights. ";
            $line .= "Max {$roomData['max_occupancy']} persons.";

            if ($bookingUrl) {
                $line .= " BOOKING_URL: {$bookingUrl}";
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // SYSTEM PROMPT
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
            "- {$r->name} ({$r->type}): Base price {$r->base_price}EUR/night (Max: {$r->max_occupancy} persons). " .
            ($r->description ? "Description: {$r->description}." : '') .
            ($r->booking_url ? " Booking URL: {$r->booking_url}" : '')
        )->join("\n");

        $propertyBookingUrl = !empty($property->booking_url)
            ? "Property general booking URL (fallback): {$property->booking_url}"
            : "No general booking URL set for this property.";

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

PROPERTY INFORMATION:
- Address: {$property->address}
- Description: {$property->description}
- Amenities: {$amenities}
- Rules: {$property->rules}
- {$propertyBookingUrl}
- Staff contact: {$contactInfo}

AVAILABLE ROOMS (with booking links if set):
{$roomsInfo}

NEARBY POINTS OF INTEREST:
{$pois}

BEHAVIOR:
- Be helpful and friendly, be concise
- If the customer asks about booking WITHOUT providing dates, ask for check-in and check-out dates

BOOKING LINK BEHAVIOR (IMPORTANT):
- A BOOKING_URL may be listed next to each room above, or as the property general booking URL.
- When availability is confirmed OR when customer expresses clear intent to book:
  * If a BOOKING_URL exists → ask: "Would you like to book directly via the booking website, or would you prefer our staff to assist you?" (in the customer's language)
  * If NO BOOKING_URL exists → give customer the staff contact details
- If the customer wants to book themselves / says "I'll do it" / "send the link" / "direct booking":
  → provide the BOOKING_URL only; do NOT mention staff
- If the customer wants staff to handle it / says "you do it" / "book for me" / "staff booking" / any equivalent in ANY language:
  → give ONLY the staff contact details (email/phone); do NOT show the booking link again; do NOT ask again
- If the customer already expressed a clear preference earlier in the conversation, respect it — do NOT repeat the question
- NEVER invent booking URLs or contact details. Only use what is listed above.

UNKNOWN INFORMATION BEHAVIOR (IMPORTANT):
- If asked something not covered by the data above, say you don't have that information and provide the staff contact details so the customer can ask directly.
- Example: "I don't have details on that. You can contact our staff: {$contactInfo}"
- NEVER make up details to fill a gap.

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
                    $prompt .= "\nAsk for check-in and check-out dates, AND follow BOOKING LINK BEHAVIOR (offer staff-handles-it vs direct link).";
                } else {
                    $prompt .= "\n\nBOOKING INTENT: Customer wants to make a reservation. Ask for check-in and check-out dates.";
                }
            } elseif (!empty($availability['available'])) {
                $roomsList = $this->buildRoomsWithBookingUrls($property, $availability);
                $prompt .= "\n\nAVAILABILITY (verified):\nFor {$availability['checkin']} - {$availability['checkout']} ({$availability['nights']} nights):\n{$roomsList}";
                $prompt .= "\nAfter showing availability, follow the BOOKING LINK BEHAVIOR rules above.";
            } else {
                $prompt .= "\n\nAVAILABILITY: No rooms available for the requested dates. Suggest trying other dates.";
            }
        }

        return $prompt;
    }

    // -------------------------------------------------------------------------
    // EMBED SCRIPT
    // -------------------------------------------------------------------------

    public function embedScript(Property $property)
    {
        if (!$property->webchat_enabled) {
            return response('// Chat disabled', 200)
                ->header('Content-Type', 'application/javascript');
        }

        $baseUrl   = 'http://localhost:8000';
        $slug      = $property->slug;
        $name      = addslashes($property->name);
        $csrfToken = csrf_token();

        $js = <<<JS
(function() {
    if (window.__hotelChatLoaded) return;
    window.__hotelChatLoaded = true;

    const BASE_URL   = '{$baseUrl}';
    const SLUG       = '{$slug}';
    const HOTEL_NAME = '{$name}';
    const CHAT_URL   = BASE_URL + '/chat/' + SLUG + '/message';

    let conversationId = null;
    let isOpen         = false;

    const style = document.createElement('style');
    style.innerHTML = `
        #hc-button {
            position: fixed; bottom: 24px; right: 24px;
            width: 56px; height: 56px; border-radius: 50%;
            background: #1a73e8; color: white; border: none;
            cursor: pointer; box-shadow: 0 4px 16px rgba(0,0,0,0.2);
            font-size: 24px; z-index: 99999;
            display: flex; align-items: center; justify-content: center;
            transition: transform 0.2s;
        }
        #hc-button:hover { transform: scale(1.1); }
        #hc-widget {
            position: fixed; bottom: 90px; right: 24px;
            width: 360px; height: 520px; background: white;
            border-radius: 16px; box-shadow: 0 8px 32px rgba(0,0,0,0.15);
            z-index: 99998; display: none; flex-direction: column;
            overflow: hidden; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        #hc-widget.open { display: flex; }
        #hc-header {
            background: #1a73e8; color: white; padding: 14px 16px;
            display: flex; align-items: center; gap: 10px;
        }
        #hc-header .hc-avatar {
            width: 36px; height: 36px; background: rgba(255,255,255,0.2);
            border-radius: 50%; display: flex; align-items: center;
            justify-content: center; font-size: 16px;
        }
        #hc-header h3 { margin: 0; font-size: 14px; font-weight: 600; }
        #hc-header p  { margin: 0; font-size: 11px; opacity: 0.85; }
        #hc-messages {
            flex: 1; overflow-y: auto; padding: 14px;
            display: flex; flex-direction: column; gap: 10px; background: #f8f9fa;
        }
        .hc-msg {
            max-width: 80%; padding: 9px 13px; border-radius: 12px;
            font-size: 13px; line-height: 1.5;
        }
        .hc-msg.user {
            background: #1a73e8; color: white;
            align-self: flex-end; border-bottom-right-radius: 3px;
        }
        .hc-msg.assistant {
            background: white; color: #333; align-self: flex-start;
            border-bottom-left-radius: 3px; box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        .hc-typing {
            background: white; align-self: flex-start; padding: 10px 14px;
            border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        .hc-dots span {
            display: inline-block; width: 7px; height: 7px;
            background: #aaa; border-radius: 50%; margin: 0 2px;
            animation: hcBounce 1.2s infinite;
        }
        .hc-dots span:nth-child(2) { animation-delay: 0.2s; }
        .hc-dots span:nth-child(3) { animation-delay: 0.4s; }
        @keyframes hcBounce {
            0%, 80%, 100% { transform: translateY(0); }
            40% { transform: translateY(-5px); }
        }
        #hc-input-area {
            padding: 10px 12px; border-top: 1px solid #eee;
            display: flex; gap: 8px; background: white;
        }
        #hc-input {
            flex: 1; padding: 9px 13px; border: 1px solid #ddd;
            border-radius: 20px; font-size: 13px; outline: none;
        }
        #hc-input:focus { border-color: #1a73e8; }
        #hc-send {
            width: 36px; height: 36px; background: #1a73e8;
            border: none; border-radius: 50%; color: white;
            cursor: pointer; display: flex; align-items: center; justify-content: center;
        }
        #hc-send:disabled { background: #ccc; cursor: not-allowed; }
        @media (max-width: 480px) {
            #hc-widget { width: calc(100vw - 16px); height: 70vh; right: 8px; bottom: 80px; }
        }
    `;
    document.head.appendChild(style);

    const btn = document.createElement('button');
    btn.id = 'hc-button';
    btn.innerHTML = '💬';
    btn.title = 'Chat with ' + HOTEL_NAME;

    const widget = document.createElement('div');
    widget.id = 'hc-widget';
    widget.innerHTML =
        '<div id="hc-header">' +
            '<div class="hc-avatar">🏨</div>' +
            '<div><h3>' + HOTEL_NAME + '</h3><p>Virtual Assistant • Online</p></div>' +
        '</div>' +
        '<div id="hc-messages">' +
            '<div class="hc-msg assistant">Welcome! 👋 How can I help you today?</div>' +
        '</div>' +
        '<div id="hc-input-area">' +
            '<input id="hc-input" type="text" placeholder="Type your message..." autocomplete="off">' +
            '<button id="hc-send">' +
                '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
                    '<line x1="22" y1="2" x2="11" y2="13"></line>' +
                    '<polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>' +
                '</svg>' +
            '</button>' +
        '</div>';

    document.body.appendChild(btn);
    document.body.appendChild(widget);

    btn.addEventListener('click', function() {
        isOpen = !isOpen;
        widget.classList.toggle('open', isOpen);
        btn.innerHTML = isOpen ? '✕' : '💬';
        if (isOpen) document.getElementById('hc-input').focus();
    });

    function linkify(text) {
        const urlRegex = /(https?:\/\/[^\s]+)/g;
        const escaped = text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
        return escaped.replace(urlRegex, function(url) {
            return '<a href="' + url + '" target="_blank" rel="noopener noreferrer" style="color: inherit; text-decoration: underline;">' + url + '</a>';
        });
    }

    function appendMsg(content, role) {
        const div = document.createElement('div');
        div.className = 'hc-msg ' + role;
        div.innerHTML = linkify(content);
        const msgs = document.getElementById('hc-messages');
        msgs.appendChild(div);
        msgs.scrollTop = msgs.scrollHeight;
    }

    function showTyping() {
        const div = document.createElement('div');
        div.className = 'hc-typing'; div.id = 'hc-typing';
        div.innerHTML = '<div class="hc-dots"><span></span><span></span><span></span></div>';
        const msgs = document.getElementById('hc-messages');
        msgs.appendChild(div);
        msgs.scrollTop = msgs.scrollHeight;
    }

    function hideTyping() {
        const el = document.getElementById('hc-typing');
        if (el) el.remove();
    }

    async function sendMessage() {
        const input = document.getElementById('hc-input');
        const send  = document.getElementById('hc-send');
        const text  = input.value.trim();
        if (!text) return;

        input.value   = '';
        send.disabled = true;
        appendMsg(text, 'user');
        showTyping();

        try {
            const res = await fetch(CHAT_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    message: text,
                    conversation_id: conversationId,
                    _token: '{$csrfToken}'
                })
            });

            const data = await res.json();
            hideTyping();

            if (data.message) {
                conversationId = data.conversation_id;
                appendMsg(data.message, 'assistant');
            } else {
                appendMsg('Sorry, an error occurred. Please try again.', 'assistant');
            }
        } catch(e) {
            hideTyping();
            appendMsg('Cannot connect to server.', 'assistant');
        } finally {
            send.disabled = false;
            input.focus();
        }
    }

    document.getElementById('hc-send').addEventListener('click', sendMessage);
    document.getElementById('hc-input').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') sendMessage();
    });

})();
JS;

        return response($js, 200)
            ->header('Content-Type', 'application/javascript')
            ->header('Cache-Control', 'no-cache');
    }
}