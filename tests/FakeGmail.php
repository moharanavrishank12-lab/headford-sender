<?php
use App\Services\GmailClient;
class FakeGmail extends GmailClient
{
    public array $sent = []; public array $inbox = []; public $failNext = null; public int $n = 0;
    public function __construct() {}
    public function send(string $user, string $raw, ?string $threadId = null): array
    {
        if ($this->failNext) { $e = $this->failNext; $this->failNext = null; throw new RuntimeException($e); }
        $id = 'g'.(++$this->n); $thread = $threadId ?: 't'.$this->n;
        preg_match('/^Message-ID: (.+)$/mi', $raw, $m);
        $this->sent[] = ['user' => $user, 'raw' => $raw, 'id' => $id, 'threadId' => $thread, 'msgid' => trim($m[1] ?? '')];
        return ['id' => $id, 'threadId' => $thread];
    }
    public function get(string $user, string $id, string $format = 'full'): array
    {
        foreach ($this->sent as $s) if ($s['id'] === $id) return ['id' => $id, 'threadId' => $s['threadId'], 'payload' => ['headers' => [['name' => 'Message-ID', 'value' => $s['msgid']]]]];
        foreach ($this->inbox as $m) if ($m['id'] === $id) return $m;
        throw new RuntimeException('not found');
    }
    public function list(string $user, string $q, int $max = 100): array
    {
        if (str_contains($q, 'rfc822msgid') || str_contains($q, 'in:sent')) {
            foreach ($this->sent as $s) if ($s['user'] === $user && (str_contains($q, trim($s['msgid'], '<>')) || str_contains($q, 'in:sent'))) return [['id' => $s['id'], 'threadId' => $s['threadId']]];
            return [];
        }
        return array_values(array_map(fn ($m) => ['id' => $m['id'], 'threadId' => $m['threadId']], array_filter($this->inbox, fn ($m) => $m['user'] === $user)));
    }
    public function token(string $user): string { return 'x'; }
}
