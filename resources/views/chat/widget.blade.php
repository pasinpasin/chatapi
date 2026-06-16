<!DOCTYPE html>
<html lang="sq">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat - {{ $property->name }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f0f2f5;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }

        .chat-container {
            width: 400px;
            height: 600px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.12);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .chat-header {
            background: #1a73e8;
            color: white;
            padding: 16px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .chat-header .avatar {
            width: 40px;
            height: 40px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        .chat-header h3 { font-size: 15px; font-weight: 600; }
        .chat-header p  { font-size: 12px; opacity: 0.85; }

        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .message {
            max-width: 80%;
            padding: 10px 14px;
            border-radius: 12px;
            font-size: 14px;
            line-height: 1.5;
        }

        .message.user {
            background: #1a73e8;
            color: white;
            align-self: flex-end;
            border-bottom-right-radius: 4px;
        }

        .message.assistant {
            background: #f0f2f5;
            color: #333;
            align-self: flex-start;
            border-bottom-left-radius: 4px;
        }

        .message.typing {
            background: #f0f2f5;
            align-self: flex-start;
            padding: 12px 16px;
        }

        .typing-dots span {
            display: inline-block;
            width: 8px;
            height: 8px;
            background: #999;
            border-radius: 50%;
            margin: 0 2px;
            animation: bounce 1.2s infinite;
        }

        .typing-dots span:nth-child(2) { animation-delay: 0.2s; }
        .typing-dots span:nth-child(3) { animation-delay: 0.4s; }

        @keyframes bounce {
            0%, 80%, 100% { transform: translateY(0); }
            40% { transform: translateY(-6px); }
        }

        .chat-input {
            padding: 12px 16px;
            border-top: 1px solid #eee;
            display: flex;
            gap: 8px;
        }

        .chat-input input {
            flex: 1;
            padding: 10px 14px;
            border: 1px solid #ddd;
            border-radius: 24px;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s;
        }

        .chat-input input:focus { border-color: #1a73e8; }

        .chat-input button {
            width: 40px;
            height: 40px;
            background: #1a73e8;
            border: none;
            border-radius: 50%;
            color: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s;
        }

        .chat-input button:hover    { background: #1557b0; }
        .chat-input button:disabled { background: #ccc; cursor: not-allowed; }
    </style>
</head>
<body>

<div class="chat-container">
    <div class="chat-header">
        <div class="avatar">🏨</div>
        <div>
            <h3>{{ $property->name }}</h3>
            <p>Asistenti Virtual • Online</p>
        </div>
    </div>

    <div class="chat-messages" id="messages">
        <div class="message assistant">
            Mirë se vini te {{ $property->name }}! 👋 Si mund t'ju ndihmoj sot?
            Mund të më pyesni për disponueshmërinë e dhomave, çmimet, ose vendet turistike afër nesh.
        </div>
    </div>

    <div class="chat-input">
        <input type="text" id="userInput" placeholder="Shkruani mesazhin..." autocomplete="off">
        <button id="sendBtn" onclick="sendMessage()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="22" y1="2" x2="11" y2="13"></line>
                <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
            </svg>
        </button>
    </div>
</div>

<script>
    const CHAT_URL       = "{{ route('chat.message', $property->slug) }}";
    const CSRF_TOKEN     = "{{ csrf_token() }}";
    let conversationId   = null;

    const messagesEl = document.getElementById('messages');
    const inputEl    = document.getElementById('userInput');
    const sendBtn    = document.getElementById('sendBtn');

    // Enter per dergim
    inputEl.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') sendMessage();
    });

    function appendMessage(content, role) {
        const div = document.createElement('div');
        div.className = `message ${role}`;
        div.textContent = content;
        messagesEl.appendChild(div);
        messagesEl.scrollTop = messagesEl.scrollHeight;
        return div;
    }

    function showTyping() {
        const div = document.createElement('div');
        div.className = 'message typing';
        div.id = 'typing-indicator';
        div.innerHTML = '<div class="typing-dots"><span></span><span></span><span></span></div>';
        messagesEl.appendChild(div);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function hideTyping() {
        const el = document.getElementById('typing-indicator');
        if (el) el.remove();
    }

    async function sendMessage() {
        const text = inputEl.value.trim();
        if (!text) return;

        inputEl.value = '';
        sendBtn.disabled = true;

        appendMessage(text, 'user');
        showTyping();

        try {
            const res = await fetch(CHAT_URL, {
                method:  'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': CSRF_TOKEN,
                },
                body: JSON.stringify({
                    message:         text,
                    conversation_id: conversationId,
                }),
            });

            const data = await res.json();
            hideTyping();

            if (data.error) {
                appendMessage('Na vjen keq, ndodhi një gabim. Provoni përsëri.', 'assistant');
            } else {
                conversationId = data.conversation_id;
                appendMessage(data.message, 'assistant');
            }
        } catch (err) {
            hideTyping();
            appendMessage('Nuk mund të lidhemi me serverin. Kontrolloni lidhjen.', 'assistant');
        } finally {
            sendBtn.disabled = false;
            inputEl.focus();
        }
    }
</script>

</body>
</html>