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
        private GroqService $gemini, // e mbajme emrin per te mos ndryshuar gje tjeter
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

        // Hapi 1 - Conversation
        try {
            $conversation = $this->getOrCreateConversation($request, $property);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Hapi 1 - Conversation: ' . $e->getMessage()], 500);
        }

        // Hapi 2 - Përmbledhja dhe Marrja e Historikut
        try {
            $history = $this->chatMemory->summarizeAndGetHistory($conversation, 8);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Hapi 2 - History & Summarization: ' . $e->getMessage()], 500);
        }

        // Hapi 3 - Ruaj mesazhin e ri të përdoruesit
        try {
            ChatMessage::create([
                'conversation_id' => $conversation->id,
                'role'            => 'user',
                'content'         => $request->message,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Hapi 3 - ChatMessage: ' . $e->getMessage()], 500);
        }

        // Hapi 4 - Availability
        try {
            $availabilityContext = $this->updateAndGetAvailability($request->message, $property, $conversation);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Hapi 4 - Availability: ' . $e->getMessage()], 500);
        }

        // Hapi 5 - System Prompt
        try {
            $systemPrompt = $this->buildSystemPrompt($property, $availabilityContext, $conversation, $request->message);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Hapi 5 - SystemPrompt: ' . $e->getMessage()], 500);
        }

        // Hapi 6 - AI Call
        try {
            $aiResponse = $this->gemini->chat($systemPrompt, $history, $request->message);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Hapi 6 - AI: ' . $e->getMessage()], 500);
        }

        // Hapi 7 - Ruaj pergjigjen
        try {
            ChatMessage::create([
                'conversation_id' => $conversation->id,
                'role'            => 'assistant',
                'content'         => $aiResponse,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Hapi 7 - Save response: ' . $e->getMessage()], 500);
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
            $response = $this->gemini->chat(
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
                \Log::info('AI date extraction (web)', ['message' => $message, 'result' => $data]);
                return $data;
            }

        } catch (\Exception $e) {
            \Log::error('Date extraction AI failed (web): ' . $e->getMessage());
        }

        return ['checkin' => null, 'checkout' => null];
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

PROPERTY INFORMATION:
- Address: {$property->address}
- General Description: {$property->description}
- Amenities: {$amenities}
- Hotel Rules: {$property->rules}

AVAILABLE ROOMS (base prices and descriptions):
{$roomsInfo}

NEARBY POINTS OF INTEREST:
{$pois}

BEHAVIOR:
- Be helpful and friendly
- Be concise
- If the customer asks about booking, ask for check-in and check-out dates
- For final bookings, say that staff will contact them within 24 hours

HALLUCINATION PREVENTION:
- NEVER invent or assume details about rooms (sea/mountain view, balcony, bed type, etc.) if not explicitly written in the description above.
- If the customer asks for a detail not in the description, politely say you do not have that information and suggest they contact staff.
PROMPT;

        if ($conversation && $conversation->summary) {
            $prompt .= "\n\nPREVIOUS CONVERSATION SUMMARY (for reference):\n{$conversation->summary}";
        }

        if ($availability !== null) {
            if ($availability['available']) {
                $roomsList = '';
                foreach ($availability['rooms'] as $room) {
                    $roomsList .= "\n- {$room['name']} ({$room['type']}): {$room['price_per_night']}EUR/night, total {$room['total_price']}EUR for {$room['nights']} nights. Max {$room['max_occupancy']} persons.";
                }
                $prompt .= "\n\nAVAILABILITY (verified by system):\nFor dates {$availability['checkin']} - {$availability['checkout']} ({$availability['nights']} nights), available rooms:{$roomsList}";
                $prompt .= "\nNOTE: If the customer asked about a whole month, confirm rooms are available and ask for their specific dates.";
            } else {
                $prompt .= "\n\nAVAILABILITY: No rooms available for the requested dates. Advise the customer to try other dates.";
                $prompt .= "\nNOTE: If the customer asked about a whole month, mention availability varies and suggest they provide specific dates.";
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
            '<div>' +
                '<h3>' + HOTEL_NAME + '</h3>' +
                '<p>Virtual Assistant • Online</p>' +
            '</div>' +
        '</div>' +
        '<div id="hc-messages">' +
            '<div class="hc-msg assistant">' +
                'Welcome! 👋 How can I help you today?' +
            '</div>' +
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

    function appendMsg(content, role) {
        const div = document.createElement('div');
        div.className = 'hc-msg ' + role;
        div.textContent = content;
        const msgs = document.getElementById('hc-messages');
        msgs.appendChild(div);
        msgs.scrollTop = msgs.scrollHeight;
    }

    function showTyping() {
        const div = document.createElement('div');
        div.className = 'hc-typing';
        div.id = 'hc-typing';
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