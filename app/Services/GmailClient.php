<?php

namespace App\Services;

use App\Models\Account;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Gmail API - Google Workspace service account (domain-wide delegation).
 * Sirf "accounts" table ki IDs ke naam par kaam karta hai (allowlist) - koi aur mailbox chhua hi nahi ja sakta.
 */
class GmailClient
{
    const SCOPES = 'https://www.googleapis.com/auth/gmail.modify https://www.googleapis.com/auth/gmail.send';

    const BASE = 'https://gmail.googleapis.com/gmail/v1/users/me';

    public function __construct(private ?array $sa = null) {}

    private function sa(): array
    {
        if ($this->sa) {
            return $this->sa;
        }
        $path = config('sender.google_sa_json');
        if (! $path || ! is_file($path)) {
            throw new RuntimeException('Google service account JSON nahi mila: '.$path);
        }
        $this->sa = json_decode(file_get_contents($path), true) ?: throw new RuntimeException('Service account JSON kharab hai');

        return $this->sa;
    }

    private function allowed(string $user): void
    {
        if (! Account::where('email', strtolower($user))->exists()) {
            throw new RuntimeException("$user sending list mein nahi hai - access mana");
        }
    }

    public function token(string $user): string
    {
        $this->allowed($user);

        return Cache::remember('gtok:'.strtolower($user), 3000, function () use ($user) {
            $sa = $this->sa();
            $now = time();
            $b64 = fn ($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
            $head = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claim = $b64(json_encode([
                'iss' => $sa['client_email'], 'scope' => self::SCOPES, 'aud' => $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token',
                'iat' => $now, 'exp' => $now + 3600, 'sub' => $user,
            ]));
            $sig = '';
            if (! openssl_sign("$head.$claim", $sig, $sa['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('JWT sign nahi hua (private key?)');
            }
            $r = Http::asForm()->timeout(30)->post($sa['token_uri'] ?? 'https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => "$head.$claim.".$b64($sig),
            ]);
            if (! $r->ok()) {
                throw new RuntimeException("Google token nahi mila ($user): ".substr($r->body(), 0, 300));
            }

            return $r->json('access_token');
        });
    }

    private function req(string $user, string $method, string $path, array $data = [], array $query = [])
    {
        $r = Http::withToken($this->token($user))->timeout(60)->retry(2, 2000, throw: false)
            ->{$method}(self::BASE.$path.($query ? '?'.http_build_query($query) : ''), $data ?: null);
        if ($r->status() === 401) {
            Cache::forget('gtok:'.strtolower($user));
        }
        if (! $r->successful()) {
            throw new RuntimeException("Gmail $method $path ($user): HTTP {$r->status()} ".substr($r->body(), 0, 300));
        }

        return $r->json();
    }

    /** Email bhejo. threadId do to usi thread mein jaata hai. */
    public function send(string $user, string $raw, ?string $threadId = null): array
    {
        $b = ['raw' => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=')];
        if ($threadId) {
            $b['threadId'] = $threadId;
        }

        return $this->req($user, 'post', '/messages/send', $b);
    }

    public function list(string $user, string $q, int $max = 100): array
    {
        $out = [];
        $page = null;
        do {
            $j = $this->req($user, 'get', '/messages', [], array_filter(['q' => $q, 'maxResults' => min(100, $max), 'pageToken' => $page]));
            foreach ($j['messages'] ?? [] as $m) {
                $out[] = $m;
            }
            $page = $j['nextPageToken'] ?? null;
        } while ($page && count($out) < $max);

        return $out;
    }

    public function get(string $user, string $id, string $format = 'full'): array
    {
        $q = ['format' => $format];
        if ($format === 'metadata') {
            $q = 'format=metadata&metadataHeaders=Message-ID&metadataHeaders=Subject&metadataHeaders=From&metadataHeaders=To';

            return $this->req($user, 'get', "/messages/$id?$q");
        }

        return $this->req($user, 'get', "/messages/$id", [], $q);
    }

    /** Sent mein hamara email mila? (crash ke baad double-send rokne ke liye) */
    public function findSent(string $user, string $rfcMsgId, string $to, int $afterTs): ?array
    {
        $id = trim($rfcMsgId, '<>');
        $hit = $this->list($user, "in:anywhere rfc822msgid:$id", 1)[0] ?? null;
        if (! $hit) {
            $hit = $this->list($user, 'in:sent to:'.$to.' after:'.max(0, $afterTs - 120), 1)[0] ?? null;
        }

        return $hit;
    }

    public function profile(string $user): array
    {
        return $this->req($user, 'get', '/profile');
    }

    /** Gmail message ka header nikalo */
    public static function header(array $msg, string $name): ?string
    {
        foreach ($msg['payload']['headers'] ?? [] as $h) {
            if (strcasecmp($h['name'], $name) === 0) {
                return $h['value'];
            }
        }

        return null;
    }

    /** Gmail message ka plain text (text/plain, warna HTML se text) */
    public static function text(array $msg): string
    {
        $plain = '';
        $html = '';
        $walk = function ($p) use (&$walk, &$plain, &$html) {
            $mime = $p['mimeType'] ?? '';
            $data = $p['body']['data'] ?? null;
            if ($data) {
                $txt = base64_decode(strtr($data, '-_', '+/'));
                if ($mime === 'text/plain' && $plain === '') {
                    $plain = $txt;
                } elseif ($mime === 'text/html' && $html === '') {
                    $html = $txt;
                }
            }
            foreach ($p['parts'] ?? [] as $c) {
                $walk($c);
            }
        };
        $walk($msg['payload'] ?? []);
        if ($plain === '' && $html !== '') {
            $plain = html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/div)[^>]*>/i', "\n", $html)));
        }

        return trim(preg_replace("/\r\n?/", "\n", $plain));
    }
}
