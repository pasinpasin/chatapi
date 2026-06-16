<?php

namespace App\Http\Controllers;

use App\Models\Property;
use App\Models\Conversation;
use App\Models\ChatMessage;
use App\Services\GeminiService;
use App\Services\AvailabilityService;
use App\Services\GroqService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ChatController extends Controller
{
     public function __construct(
        private GroqService $gemini, // e mbajme emrin per te mos ndryshuar gje tjeter
        private AvailabilityService $availability,
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

    // Hapi 2 - Ruaj mesazhin
    try {
        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'role'            => 'user',
            'content'         => $request->message,
        ]);
    } catch (\Exception $e) {
        return response()->json(['error' => 'Hapi 2 - ChatMessage: ' . $e->getMessage()], 500);
    }

    // Hapi 3 - History
    try {
        $history = $conversation->messages()
            ->orderBy('created_at')
            ->take(10)
            ->get()
            ->map(fn($m) => ['role' => $m->role, 'content' => $m->content])
            ->toArray();
    } catch (\Exception $e) {
        return response()->json(['error' => 'Hapi 3 - History: ' . $e->getMessage()], 500);
    }

    // Hapi 4 - Availability
    try {
        $availabilityContext = $this->extractAvailabilityContext($request->message, $property);
    } catch (\Exception $e) {
        return response()->json(['error' => 'Hapi 4 - Availability: ' . $e->getMessage()], 500);
    }

    // Hapi 5 - System Prompt
    try {
        $systemPrompt = $this->buildSystemPrompt($property, $availabilityContext);
    } catch (\Exception $e) {
        return response()->json(['error' => 'Hapi 5 - SystemPrompt: ' . $e->getMessage()], 500);
    }

    // Hapi 6 - Gemini
    try {
        $aiResponse = $this->gemini->chat($systemPrompt, $history, $request->message);
    } catch (\Exception $e) {
        return response()->json(['error' => 'Hapi 6 - Gemini: ' . $e->getMessage()], 500);
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

    private function getOrCreateConversation(Request $request, Property $property): Conversation
    {
        if ($request->conversation_id) {
            $conv = Conversation::where('id', $request->conversation_id)
                ->where('property_id', $property->id)
                ->first();
            if ($conv) return $conv;
        }

        return Conversation::create([
            'property_id'          => $property->id,
            'channel'              => 'web',
            'customer_identifier'  => session()->getId(),
        ]);
    }
private function extractAvailabilityContext(string $message, Property $property): ?array
{
    $months = [
        'janar' => '01', 'shkurt' => '02', 'mars' => '03',
        'prill' => '04', 'maj' => '05', 'qershor' => '06',
        'korrik' => '07', 'gusht' => '08', 'shtator' => '09',
        'tetor' => '10', 'nëntor' => '11', 'dhjetor' => '12',
        'january' => '01', 'february' => '02', 'march' => '03',
        'april' => '04', 'may' => '05', 'june' => '06',
        'july' => '07', 'august' => '08', 'september' => '09',
        'october' => '10', 'november' => '11', 'december' => '12',
        'gennaio' => '01', 'febbraio' => '02', 'marzo' => '03',
        'aprile' => '04', 'maggio' => '05', 'giugno' => '06',
        'luglio' => '07', 'agosto' => '08', 'settembre' => '09',
        'ottobre' => '10', 'novembre' => '11', 'dicembre' => '12',
    ];

    $messageNorm = mb_strtolower($message);
    $year = date('Y');
    $foundDates = [];

    // Pattern: "12 korrik" ose "12 july" ose "12 luglio"
    foreach ($months as $monthName => $monthNum) {
        if (preg_match_all('/(\d{1,2})\s+' . preg_quote($monthName, '/') . '(?:\s+(\d{4}))?/i', $messageNorm, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $y = !empty($match[2]) ? $match[2] : $year;
                $foundDates[] = $y . '-' . $monthNum . '-' . str_pad($match[1], 2, '0', STR_PAD_LEFT);
            }
        }
    }

    // Pattern: "12/07" ose "12-07-2026"
    if (preg_match_all('/(\d{1,2})[\/\-](\d{1,2})(?:[\/\-](\d{4}))?/', $message, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $y = !empty($match[3]) ? $match[3] : $year;
            $foundDates[] = $y . '-' . str_pad($match[2], 2, '0', STR_PAD_LEFT) . '-' . str_pad($match[1], 2, '0', STR_PAD_LEFT);
        }
    }

    $foundDates = array_unique($foundDates);

    if (count($foundDates) >= 2) {
        try {
            $result = $this->availability->checkAvailability(
                $property,
                $foundDates[0],
                $foundDates[1]
            );
            return $result;
        } catch (\Exception $e) {
            return null;
        }
    }

    return null;
}
    private function buildSystemPrompt(Property $property, ?array $availability): string
    {
        $amenities = implode(', ', $property->amenities ?? []);
        $pois      = $property->pointsOfInterest()
            ->get()
            ->map(fn($p) => "{$p->name} ({$p->type}) - {$p->distance_text} - {$p->gmaps_link}")
            ->join("\n");

       $prompt = <<<PROMPT
Ti je asistenti virtual i "{$property->name}", një {$property->type} në Shkodër, Shqipëri.

RREGULL ABSOLUTISHT I DETYRUESHËM PËR GJUHËN:
- Përgjigju GJITHMONË në të njëjtën gjuhë që shkruan klienti
- Nëse klienti shkruan SHQIP → përgjigju në SHQIP STANDARD (jo dialekt, jo fjalë sllave)
- Nëse klienti shkruan ANGLISHT → përgjigju në anglisht
- Nëse klienti shkruan ITALISHT → përgjigju në italisht
- KURRË mos përziej gjuhë të ndryshme në të njëjtën përgjigje
- KURRË mos përdor fjalë sllave (jo: "kamër", "zdravo", etj.)
- Për shqipen: përdor GJITHMONË "dhomë" (jo "kamër"), "mirëmëngjes" (jo "dobro jutro"), etj.
PROMPT;

        // Shto kontekstin e disponueshmërisë nëse e kemi
        if ($availability !== null) {
            if ($availability['available']) {
                $roomsList = '';
                foreach ($availability['rooms'] as $room) {
                    $roomsList .= "\n- {$room['name']} ({$room['type']}): {$room['price_per_night']}€/natë, total {$room['total_price']}€ për {$room['nights']} netë. Max {$room['max_occupancy']} persona.";
                }
                $prompt .= "\n\nINFORMACION DISPONUESHMËRIE (i verifikuar nga sistemi):\n";
                $prompt .= "Për datat {$availability['checkin']} - {$availability['checkout']} ({$availability['nights']} netë), dhomat e disponueshme janë:{$roomsList}";
            } else {
                $prompt .= "\n\nINFORMACION DISPONUESHMËRIE: Për datat e kërkuara NUK ka dhomë të lirë. Këshillo klientin të provojë data të tjera.";
            }
        }

        return $prompt;
    }

    public function embedScript(Property $property)
{
    if (!$property->webchat_enabled) {
        return response('// Chat disabled', 200)
            ->header('Content-Type', 'application/javascript');
    }

    $baseUrl = 'http://localhost:8000';
    $slug       = $property->slug;
    $name       = addslashes($property->name);
    $csrfToken  = csrf_token();

    $js = <<<JS
(function() {
    // Mos shto dy here
    if (window.__hotelChatLoaded) return;
    window.__hotelChatLoaded = true;

    const BASE_URL   = '{$baseUrl}';
    const SLUG       = '{$slug}';
    const HOTEL_NAME = '{$name}';
    const CHAT_URL   = BASE_URL + '/chat/' + SLUG + '/message';

    let conversationId = null;
    let isOpen         = false;

    // Inject CSS
    const style = document.createElement('style');
    style.innerHTML = `
        #hc-button {
            position: fixed;
            bottom: 24px;
            right: 24px;
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: #1a73e8;
            color: white;
            border: none;
            cursor: pointer;
            box-shadow: 0 4px 16px rgba(0,0,0,0.2);
            font-size: 24px;
            z-index: 99999;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.2s;
        }
        #hc-button:hover { transform: scale(1.1); }

        #hc-widget {
            position: fixed;
            bottom: 90px;
            right: 24px;
            width: 360px;
            height: 520px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.15);
            z-index: 99998;
            display: none;
            flex-direction: column;
            overflow: hidden;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        #hc-widget.open { display: flex; }

        #hc-header {
            background: #1a73e8;
            color: white;
            padding: 14px 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        #hc-header .hc-avatar {
            width: 36px; height: 36px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 16px;
        }
        #hc-header h3 { margin: 0; font-size: 14px; font-weight: 600; }
        #hc-header p  { margin: 0; font-size: 11px; opacity: 0.85; }

        #hc-messages {
            flex: 1;
            overflow-y: auto;
            padding: 14px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            background: #f8f9fa;
        }

        .hc-msg {
            max-width: 80%;
            padding: 9px 13px;
            border-radius: 12px;
            font-size: 13px;
            line-height: 1.5;
        }
        .hc-msg.user {
            background: #1a73e8;
            color: white;
            align-self: flex-end;
            border-bottom-right-radius: 3px;
        }
        .hc-msg.assistant {
            background: white;
            color: #333;
            align-self: flex-start;
            border-bottom-left-radius: 3px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        .hc-typing {
            background: white;
            align-self: flex-start;
            padding: 10px 14px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        .hc-dots span {
            display: inline-block;
            width: 7px; height: 7px;
            background: #aaa;
            border-radius: 50%;
            margin: 0 2px;
            animation: hcBounce 1.2s infinite;
        }
        .hc-dots span:nth-child(2) { animation-delay: 0.2s; }
        .hc-dots span:nth-child(3) { animation-delay: 0.4s; }
        @keyframes hcBounce {
            0%, 80%, 100% { transform: translateY(0); }
            40% { transform: translateY(-5px); }
        }

        #hc-input-area {
            padding: 10px 12px;
            border-top: 1px solid #eee;
            display: flex;
            gap: 8px;
            background: white;
        }
        #hc-input {
            flex: 1;
            padding: 9px 13px;
            border: 1px solid #ddd;
            border-radius: 20px;
            font-size: 13px;
            outline: none;
        }
        #hc-input:focus { border-color: #1a73e8; }
        #hc-send {
            width: 36px; height: 36px;
            background: #1a73e8;
            border: none;
            border-radius: 50%;
            color: white;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center;
        }
        #hc-send:disabled { background: #ccc; cursor: not-allowed; }

        @media (max-width: 480px) {
            #hc-widget {
                width: calc(100vw - 16px);
                height: 70vh;
                right: 8px;
                bottom: 80px;
            }
        }
    `;
    document.head.appendChild(style);

    // Inject HTML
    const btn = document.createElement('button');
    btn.id = 'hc-button';
    btn.innerHTML = '💬';
    btn.title = 'Chat me ' + HOTEL_NAME;

    const widget = document.createElement('div');
    widget.id = 'hc-widget';
  widget.innerHTML =
    '<div id="hc-header">' +
        '<div class="hc-avatar">🏨</div>' +
        '<div>' +
            '<h3>' + HOTEL_NAME + '</h3>' +
            '<p>Asistenti Virtual • Online</p>' +
        '</div>' +
    '</div>' +
    '<div id="hc-messages">' +
        '<div class="hc-msg assistant">' +
            'Mirë se vini! 👋 Si mund t\'ju ndihmoj sot? Mund të më pyesni për disponueshmërinë e dhomave ose vendet turistike afër nesh.' +
        '</div>' +
    '</div>' +
    '<div id="hc-input-area">' +
        '<input id="hc-input" type="text" placeholder="Shkruani mesazhin..." autocomplete="off">' +
        '<button id="hc-send">' +
            '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
                '<line x1="22" y1="2" x2="11" y2="13"></line>' +
                '<polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>' +
            '</svg>' +
        '</button>' +
    '</div>';

    document.body.appendChild(btn);
    document.body.appendChild(widget);

    // Toggle
    btn.addEventListener('click', function() {
        isOpen = !isOpen;
        widget.classList.toggle('open', isOpen);
        btn.innerHTML = isOpen ? '✕' : '💬';
        if (isOpen) document.getElementById('hc-input').focus();
    });

    // Send
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
                appendMsg('Na vjen keq, ndodhi një gabim.', 'assistant');
            }
        } catch(e) {
            hideTyping();
            appendMsg('Nuk mund të lidhemi me serverin.', 'assistant');
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