<?php
namespace App\Http\Controllers;

use App\Models\Property;
use App\Models\Conversation;

class ConversationController extends Controller
{
    public function index(Property $property)
    {
       
        if ($property->user_id !== auth()->id()) {
            abort(403);
        }

        $conversations = $property->conversations()
            ->withCount('messages')
            ->orderBy('updated_at', 'desc')
            ->paginate(20);

        return view('conversations.index', compact('property', 'conversations'));
    }

    public function show(Conversation $conversation)
    {
        // Ngarko property me eager loading
        $conversation->load('property');
    

        if ($conversation->property->user_id !== auth()->id()) {
            abort(403);
        }

        $messages = $conversation->messages()->orderBy('created_at')->get();

        return view('conversations.show', compact('conversation', 'messages'));
    }
}