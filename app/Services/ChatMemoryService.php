<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\ChatMessage;
use Illuminate\Support\Facades\Log;

class ChatMemoryService
{
    public function __construct(
        private GroqService $groq
    ) {}

    /**
     * Përmbledh mesazhet e vjetra nëse kalohet dritarja e lejuar dhe kthen historikun aktiv.
     *
     * @param Conversation $conversation
     * @param int $keepRecentCount
     * @return array
     */
    public function summarizeAndGetHistory(Conversation $conversation, int $keepRecentCount = 8): array
    {
        // 1. Gjej të gjitha mesazhet që nuk janë përmbledhur ende
        $allUnsummarized = $conversation->messages()
            ->where('is_summarized', false)
            ->orderBy('id', 'asc')
            ->get();

        $totalCount = $allUnsummarized->count();

        // Nëse numri i mesazheve të papërmbledhura është më i madh se dritarja e lejuar
        if ($totalCount > $keepRecentCount) {
            // Mesazhet që do të përmblidhen janë të gjitha përveç atyre më të fundit ($keepRecentCount)
            $messagesToSummarize = $allUnsummarized->take($totalCount - $keepRecentCount);

            // Ndërto tekstin për përmbledhje
            $textToSummarize = '';
            foreach ($messagesToSummarize as $msg) {
                $roleName = $msg->role === 'assistant' ? 'Asistenti' : 'Klienti';
                $textToSummarize .= "{$roleName}: {$msg->content}\n";
            }

            try {
                // Thirr AI për të krijuar përmbledhjen e re duke u bazuar te ekzistuesja
                $newSummary = $this->groq->summarize($conversation->summary ?? '', $textToSummarize);

                // Përditëso conversation me përmbledhjen e re
                $conversation->update(['summary' => $newSummary]);

                // Markoi këto mesazhe si të përmbledhura
                $messageIds = $messagesToSummarize->pluck('id')->toArray();
                ChatMessage::whereIn('id', $messageIds)->update(['is_summarized' => true]);

                Log::info("U krye përmbledhja për bisedën {$conversation->id}. Mesazhet e përmbledhura: " . implode(', ', $messageIds));
            } catch (\Exception $e) {
                Log::error("Gabim gjatë përmbledhjes së bisedës {$conversation->id}: " . $e->getMessage());
                // Vazhdojmë për të mos bllokuar bisedën e përdoruesit në rast gabimi të API
            }
        }

        // 2. Kthe historikun aktiv (vetëm ato që nuk janë përmbledhur ende)
        return $conversation->messages()
            ->where('is_summarized', false)
            ->orderBy('id', 'asc')
            ->get()
            ->map(fn($m) => ['role' => $m->role, 'content' => $m->content])
            ->toArray();
    }
}
