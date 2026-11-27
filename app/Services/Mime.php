<?php

namespace App\Services;

/** Saada text email (koi link/tracking nahi), thread ke headers aur unsubscribe ke saath */
class Mime
{
    public static function msgId(string $fromEmail): string
    {
        $dom = substr(strrchr($fromEmail, '@'), 1) ?: 'headford.local';

        return '<'.bin2hex(random_bytes(12)).'.'.time().'@'.$dom.'>';
    }

    private static function enc(string $s): string
    {
        return preg_match('/[^\x20-\x7e]/', $s) ? '=?UTF-8?B?'.base64_encode($s).'?=' : $s;
    }

    private static function addr(string $email, ?string $name = null): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return $email;
        }
        $n = preg_match('/[^\x20-\x7e]/', $name) ? self::enc($name) : '"'.str_replace('"', '', $name).'"';

        return "$n <$email>";
    }

    /**
     * @param  array  $o  from, from_name, to, to_name, subject, body, msgid, in_reply_to, references, cc, unsub_url
     */
    public static function build(array $o): string
    {
        $h = [
            'From: '.self::addr($o['from'], $o['from_name'] ?? null),
            'To: '.self::addr($o['to'], $o['to_name'] ?? null),
        ];
        if (! empty($o['cc'])) {
            $h[] = 'Cc: '.$o['cc'];
        }
        $h[] = 'Subject: '.self::enc($o['subject']);
        $h[] = 'Date: '.date(DATE_RFC2822);
        $h[] = 'Message-ID: '.$o['msgid'];
        if (! empty($o['in_reply_to'])) {
            $h[] = 'In-Reply-To: '.$o['in_reply_to'];
            $h[] = 'References: '.($o['references'] ?? $o['in_reply_to']);
        }
        if (! empty($o['unsub_url'])) {
            $h[] = 'List-Unsubscribe: <'.$o['unsub_url'].'>, <mailto:'.$o['from'].'?subject=unsubscribe>';
            $h[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
        }
        $h[] = 'MIME-Version: 1.0';
        $h[] = 'Content-Type: text/plain; charset=UTF-8';
        $h[] = 'Content-Transfer-Encoding: quoted-printable';
        $body = str_replace(["\r\n", "\r"], "\n", $o['body']);

        return implode("\r\n", $h)."\r\n\r\n".quoted_printable_encode(str_replace("\n", "\r\n", $body));
    }
}
