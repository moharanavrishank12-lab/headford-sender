<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

/** Reply ka matlab: Claude se (credits na hon to saaf shabdon se andaza) */
class Classifier
{
    const CLASSES = ['INTERESTED', 'REFERRAL', 'UNCLEAR', 'NOT_NOW', 'NOT_INTERESTED', 'UNSUBSCRIBE', 'OUT_OF_OFFICE', 'WRONG_PERSON'];

    public static function clean(string $body): string
    {
        $t = preg_split('/\n\s*(On .{5,120} wrote:|-{2,}\s*Original Message|From:\s.+\nSent:)/i', $body)[0];
        $t = implode("\n", array_filter(explode("\n", $t), fn ($l) => ! str_starts_with(ltrim($l), '>')));

        return trim(mb_substr($t, 0, 4000));
    }

    /** @return array{class:?string, summary:string, by:string} */
    public function classify(string $body, string $subject = ''): array
    {
        $text = self::clean($body);
        $key = config('sender.claude_key');
        if ($key) {
            try {
                $r = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->timeout(60)
                    ->post('https://api.anthropic.com/v1/messages', [
                        'model' => config('sender.claude_model'), 'max_tokens' => 200,
                        'messages' => [['role' => 'user', 'content' => "A store owner replied to our cold email offering Shopify/website help. Classify the reply.\n".
                            'Classes: INTERESTED (wants to talk, asks price/details/call, says yes), REFERRAL (points to another person or email), UNCLEAR (a human reply whose intent is unclear), '.
                            'NOT_NOW (maybe later), NOT_INTERESTED (no / not interested / already have someone), UNSUBSCRIBE (remove me / stop emailing), OUT_OF_OFFICE (auto-reply/away), WRONG_PERSON.'.
                            "\nReturn ONLY JSON: {\"class\":\"...\",\"summary\":\"one short line\"}\n\nSubject: $subject\nReply:\n$text"]],
                    ]);
                if ($r->successful()) {
                    $txt = $r->json('content.0.text', '');
                    if (preg_match('/\{.*\}/s', $txt, $m) && ($j = json_decode($m[0], true)) && in_array($j['class'] ?? '', self::CLASSES, true)) {
                        return ['class' => $j['class'], 'summary' => mb_substr((string) ($j['summary'] ?? ''), 0, 300), 'by' => 'claude'];
                    }
                }
            } catch (Throwable $e) {
            }
        }
        $c = self::rough($text);

        // Claude key hai par abhi chala nahi (credits/network) aur shabdon se bhi saaf nahi -> baad mein dobara
        return ['class' => $c, 'summary' => '(andaza) '.mb_substr(preg_replace('/\s+/', ' ', $text), 0, 150), 'by' => ($key && ! $c) ? 'retry' : 'rough'];
    }

    /** Claude na ho tab: sirf saaf jawab pehchano; shak ho to null (haath se dekho) */
    public static function rough(string $t): ?string
    {
        $t = strtolower($t);
        if (preg_match("/\b(unsubscribe|remove me|take me off|stop emailing|do not (email|contact)|don'?t (email|contact) me)\b/", $t)) {
            return 'UNSUBSCRIBE';
        }
        if (preg_match('/\b(out of (the )?office|on vacation|auto-?reply|automatic reply|away until|limited access to email)\b/', $t)) {
            return 'OUT_OF_OFFICE';
        }
        if (preg_match("/\b(not interested|no thanks|no thank you|we'?re (good|all set)|not at this time|we already have|pass on this)\b/", $t)) {
            return 'NOT_INTERESTED';
        }
        if (preg_match("/\b(interested|yes|sure|sounds good|tell me more|how much|pricing|price|cost|quote|let'?s (talk|chat)|call|schedule|send (it|me|over)|more info|details)\b/", $t)) {
            return 'INTERESTED';
        }

        return null;
    }
}
