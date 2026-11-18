<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

/**
 * In-app support chatbot. Retrieves the most relevant chunks of the generated knowledge base
 * (php artisan billiq:build-assistant-docs) and asks Claude to answer strictly from them.
 */
class AssistantService
{
    private const STOP = ['the','a','an','is','are','to','of','in','on','for','and','or','how','do','i','me','my','kaise','kya','hai','ka','ki','ke','ko','se','me','mein','karna','kare','kerna','karu','kar','ho','hota','can','what','where','with','this','that','it','please'];

    public function chunks(): array
    {
        $file = storage_path('app/assistant/knowledge.json');
        if (!is_file($file)) return [];
        return Cache::remember('assistant.chunks.' . filemtime($file), 3600, fn () => json_decode(file_get_contents($file), true) ?: []);
    }

    private function tokens(string $text): array
    {
        $text = strtolower(preg_replace('/([a-z])([A-Z])/', '$1 $2', $text));
        preg_match_all('/[a-z0-9]{2,}/', strtolower($text), $m);
        return array_values(array_diff(array_unique($m[0]), self::STOP));
    }

    public function retrieve(string $question, int $limit = 8): array
    {
        $q = $this->tokens($question);
        if (!$q) return [];
        $scored = [];
        foreach ($this->chunks() as $c) {
            $title = $this->tokens($c['title']);
            $body = strtolower($c['text']);
            $score = 0;
            foreach ($q as $t) {
                if (in_array($t, $title, true)) $score += 5;
                if (str_contains(strtolower($c['title']), $t)) $score += 2;
                $n = substr_count($body, $t);
                if ($n) $score += 1 + min(3, log($n + 1));
            }
            if ($c['kind'] === 'menu') $score *= 1.3;
            if ($score > 0) $scored[] = [$score, $c];
        }
        usort($scored, fn ($a, $b) => $b[0] <=> $a[0]);
        return array_map(fn ($s) => $s[1], array_slice($scored, 0, $limit));
    }

    public function ask(string $question, array $history = [], array $ctx = []): array
    {
        $key = config('services.anthropic.key');
        $hits = $this->retrieve($question . ' ' . implode(' ', array_map(fn ($h) => $h['content'] ?? '', array_slice($history, -2))));
        $sources = array_map(fn ($c) => "[{$c['kind']}] {$c['title']}", $hits);

        if (!$key) {
            return ['answer' => "The AI key is not configured (ANTHROPIC_API_KEY). Related documentation sections found:\n- " . implode("\n- ", $sources ?: ['(nothing found)']), 'sources' => $sources];
        }

        $context = '';
        foreach ($hits as $c) $context .= "### [{$c['kind']}] {$c['title']}\n{$c['text']}\n\n";
        $context = mb_substr($context, 0, 24000);

        $system = "You are the BillIQ in-app support assistant for a Billing + Accounting + Inventory + CRM + Payroll + Fixed Assets SaaS (Laravel + Vue). "
            . "Answer ONLY from the DOCUMENTATION below (routes, controllers, DB models/columns, sidebar menus, internal docs). "
            . "Reply in the same language/style as the user (Hindi, Hinglish or English). Be concise and give step-by-step instructions using exact sidebar menu names and screen URLs when known. "
            . "If the documentation does not contain the answer, say so plainly and suggest the closest screen; never invent features, fields or URLs. "
            . "You cannot read or change the user's actual business data. Never reveal internal implementation details such as secrets, file paths or SQL unless the user is asking a technical question.\n"
            . "Current screen: " . ($ctx['page'] ?? 'unknown') . "\n\nDOCUMENTATION:\n" . $context;

        $messages = [];
        foreach (array_slice($history, -8) as $h) {
            if (in_array($h['role'] ?? '', ['user', 'assistant'], true) && trim((string) ($h['content'] ?? '')) !== '') {
                $messages[] = ['role' => $h['role'], 'content' => mb_substr((string) $h['content'], 0, 2000)];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $question];
        // Anthropic requires the first message to be from the user and roles to alternate.
        while ($messages && $messages[0]['role'] !== 'user') array_shift($messages);

        $res = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])
            ->timeout(60)
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => config('services.anthropic.model'),
                'max_tokens' => 900,
                'system' => $system,
                'messages' => $messages,
            ]);

        if (!$res->successful()) {
            report(new \RuntimeException('Assistant API error ' . $res->status() . ': ' . $res->body()));
            return ['answer' => 'The assistant is unavailable right now. Please try again shortly.', 'sources' => $sources];
        }
        $text = collect($res->json('content', []))->where('type', 'text')->pluck('text')->implode("\n");
        return ['answer' => $text ?: 'No answer found.', 'sources' => $sources];
    }
}
