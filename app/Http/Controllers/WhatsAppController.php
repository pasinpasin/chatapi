<?php

namespace App\Http\Controllers;

use App\Models\Property;
use App\Models\Conversation;
use App\Models\ChatMessage;
use App\Services\WhatsAppService;
use App\Services\GroqService;
use App\Services\AvailabilityService;
use Illuminate\Http\Request;

class WhatsAppController extends Controller
{
    public function __construct(
        private WhatsAppService $whatsapp,
        private GroqService $groq,
        private AvailabilityService $availability,
    ) {}

    /**
     * Meta e thërret këtë për të verifikuar webhook
     */
    public function verifyold(Request $request)
    {
        $verifyToken = config('services.whatsapp.verify_token');

        if (
            $request->get('hub_mode') === 'subscribe' &&
            $request->get('hub_verify_token') === $verifyToken
        ) {
            return response($request->get('hub_challenge'), 200);
        }

        return response('Unauthorized', 403);
    }
    /**
 * Meta e thërret këtë për të verifikuar webhook
 */
public function verify(Request $request)
{
    // Lexojmë vlerat direkt nga query string e URL-së
    $mode = $request->query('hub_mode');
    $token = $request->query('hub_verify_token');
    $challenge = $request->query('hub_challenge');

    // Këtu vendos TOKENIN TËND REAL si string (p.sh. 'kodi_yt_sekret_123')
    // Zëvendësoje config(...) përkohësisht që të eliminojmë problemet e cache-it
    $verifyToken = config('services.whatsapp.verify_token');

    if ($mode === 'subscribe' && $token === $verifyToken) {
        header('Content-Type: text/plain');
        echo $challenge;
        exit; // Kjo ndalon çdo gjë tjetër të Laravel dhe kthen vetëm numrin
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

        // Verifiko që është mesazh WhatsApp
        $entry   = $data['entry'][0] ?? null;
        $changes = $entry['changes'][0] ?? null;
        $value   = $changes['value'] ?? null;

        if (!isset($value['messages'][0])) {
            return response('ok', 200);
        }

        $message  = $value['messages'][0];
        $from     = $message['from']; // numri i klientit
        $text     = $message['text']['body'] ?? null;
        $phoneId  = $value['metadata']['phone_number_id'];

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

if ($properties->count() === 1) {
    $property     = $properties->first();
    $systemPrompt = $this->buildSystemPrompt($property, null);
} else {
    $systemPrompt = $this->buildSystemPromptMultiProperty($properties, $from);
    $property     = $properties->first(); // prona me ranking me te larte per conversation
}
       
        // Gjej ose krijo conversation
        $conversation = Conversation::firstOrCreate(
            [
                'property_id'         => $property->id,
                'channel'             => 'whatsapp',
                'customer_identifier' => $from,
            ]
        );

        // Ruaj mesazhin e klientit
        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'role'            => 'user',
            'content'         => $text,
        ]);

        // Merr historikun
        $history = $conversation->messages()
            ->orderBy('created_at')
            ->take(10)
            ->get()
            ->map(fn($m) => ['role' => $m->role, 'content' => $m->content])
            ->toArray();

        // Kontrollo disponueshmërinë
        $availabilityContext = $this->extractAvailabilityContext($text, $property);

        // Nderto system prompt
        $systemPrompt = $this->buildSystemPrompt($property, $availabilityContext);

        // Thirr AI
        try {
            $aiResponse = $this->groq->chat($systemPrompt, $history, $text);
        } catch (\Exception $e) {
            $aiResponse = 'Na vjen keq, sistemi është i ngarkuar. Provoni përsëri pas pak.';
        }

        // Ruaj përgjigjen
        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'role'            => 'assistant',
            'content'         => $aiResponse,
        ]);

        // Dërgo përgjigjen te klienti
        $this->whatsapp->sendMessage($from, $aiResponse);

        // Perditeso conversation
        $conversation->touch();

        return response('ok', 200);
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
        ];

        $messageNorm = mb_strtolower($message);
        $year        = date('Y');
        $foundDates  = [];

        foreach ($months as $monthName => $monthNum) {
            if (preg_match_all('/(\d{1,2})\s+' . preg_quote($monthName, '/') . '(?:\s+(\d{4}))?/i', $messageNorm, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $y            = !empty($match[2]) ? $match[2] : $year;
                    $foundDates[] = $y . '-' . $monthNum . '-' . str_pad($match[1], 2, '0', STR_PAD_LEFT);
                }
            }
        }

        if (count($foundDates) >= 2) {
            try {
                return $this->availability->checkAvailability($property, $foundDates[0], $foundDates[1]);
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
Po komunikoni përmes WhatsApp.

INFORMACION I PRONËS:
- Adresa: {$property->address}
- Përshkrimi: {$property->description}
- Pajisjet: {$amenities}
- Rregullat: {$property->rules}

PIKAT E INTERESIT AFËR:
{$pois}

SJELLJA JOTE:
- Përgjigju GJITHMONË në gjuhën që shkruan klienti
- Ji konciz - WhatsApp mesazhet duhet të jenë të shkurtra
- Mos përdor formatim të tepërt (jo tabela, jo lista të gjata)
- Nëse klienti pyet për rezervim, kërko datat check-in dhe check-out
- Për rezervime finale, thuaj që stafi do kontaktojë brenda 24 orësh
PROMPT;

        if ($availability !== null) {
            if ($availability['available']) {
                $roomsList = '';
                foreach ($availability['rooms'] as $room) {
                    $roomsList .= "\n- {$room['name']}: {$room['price_per_night']}€/natë, total {$room['total_price']}€ për {$room['nights']} netë.";
                }
                $prompt .= "\n\nDISPONUESHMËRIA (i verifikuar):\nPër {$availability['checkin']} - {$availability['checkout']}:{$roomsList}";
            } else {
                $prompt .= "\n\nDISPONUESHMËRIA: Për datat e kërkuara NUK ka dhomë të lirë.";
            }
        }

        return $prompt;
    }

    private function buildSystemPromptMultiProperty($properties, string $from): string
{
    $propertiesList = '';

    foreach ($properties as $index => $p) {
        $amenities    = implode(', ', $p->amenities ?? []);
        $rooms        = $p->rooms->map(fn($r) => 
            "  • {$r->name} ({$r->type}): {$r->base_price}€/natë, max {$r->max_occupancy} persona"
        )->join("\n");
        
        $pois = $p->pointsOfInterest->map(fn($poi) => 
            "  • {$poi->name} ({$poi->type}) - {$poi->distance_text}"
        )->join("\n");

        $propertiesList .= "\n---\n";
        $propertiesList .= "PRONA " . ($index + 1) . ": {$p->name} ({$p->type})\n";
        $propertiesList .= "Adresa: {$p->address}\n";
        $propertiesList .= "Përshkrimi: {$p->description}\n";
        $propertiesList .= "Pajisjet: {$amenities}\n";
        $propertiesList .= "Rregullat: {$p->rules}\n";
        $propertiesList .= "Dhomat:\n{$rooms}\n";
        if ($pois) {
            $propertiesList .= "Afër:\n{$pois}\n";
        }
    }

    return <<<PROMPT
Ti je asistenti virtual i një grupi pronash në Shkodër, Shqipëri.
Po komunikoni përmes WhatsApp.

PRONAT E DISPONUESHME (të renditura sipas prioritetit):
{$propertiesList}

SJELLJA JOTE:
- Përgjigju GJITHMONË në gjuhën që shkruan klienti (shqip, anglisht, italisht, etj.)
- Ji konciz - WhatsApp mesazhet duhet të jenë të shkurtra
- Kur klienti pyet për disponueshmëri PA specifikuar pronën:
  * Prezanto shkurtimisht të gjitha pronat
  * Pyet për preferencat (budget, lloji, numri i personave, data)
  * Bazuar në përgjigje, sugjero pronën më të përshtatshme
- Kur klienti specifikon pronën ose dhomën, fokusohu vetëm tek ajo
- GJITHMONË përfshi emrin e pronës dhe dhomës në çdo përgjigje për disponueshmëri
- Prona e parë në listë ka prioritet më të lartë - sugjero atë kur nuk ka preferencë specifike
- Për rezervime finale, thuaj që stafi do kontaktojë brenda 24 orësh
PROMPT;
}
}
