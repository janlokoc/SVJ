<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** app/ext/inbound_mime_lib.php – rozbor e-mailu (MIME, kódování, znakové sady) a odříznutí citace a podpisu. */
final class InboundMimeTest extends TestCase
{
    private function fixture(string $name, string $from = 'petr@example.cz', string $token = 'Z1.dum.0123456789abcdef'): string
    {
        return strtr((string) file_get_contents(__DIR__ . '/fixtures/inbound/' . $name), ['{FROM}' => $from, '{TOKEN}' => $token, '{MID}' => 'x']);
    }

    private function reply(string $name): string
    {
        return inbound_reply_text(inbound_mime_parse($this->fixture($name))['text']);
    }

    public function testGmailPlainQuotedPrintableUtf8(): void
    {
        $m = inbound_mime_parse($this->fixture('gmail-plain-qp.eml'));
        $this->assertSame('petr@example.cz', $m['from']);
        $this->assertSame('Re: Odpověď na Vaše hlášení [#Z1.dum.0123456789abcdef]', $m['subject']);
        $this->assertStringContainsString('kapající kohoutek ve sklepě', $m['text']);
        $this->assertSame("Dobrý den,\n\nkapající kohoutek ve sklepě pořád teče, zkuste prosím poslat technika znovu.\n\nDěkuji, Petr", $this->reply('gmail-plain-qp.eml'),
            'podpis za „-- “ a citace se odříznou');
    }

    public function testHtmlOnlyWithGmailQuote(): void
    {
        $m = inbound_mime_parse($this->fixture('gmail-html-only.eml'));
        $this->assertStringContainsString('posílám doplnění: voda teče i v pátek.', $m['text']);
        $this->assertStringContainsString('fotka zde (https://example.cz/foto)', $m['text'], 'odkaz zůstane čitelný');
        $this->assertStringNotContainsString('citovaný text', $m['text'], 'blok gmail_quote se vynechá');
        $this->assertStringNotContainsString('<', $m['text']);
    }

    public function testOutlookWindows1250QuotedPrintable(): void
    {
        $m = inbound_mime_parse($this->fixture('outlook-cp1250-qp.eml', 'jana@example.cz'));
        $this->assertSame('jana@example.cz', $m['from']);
        $this->assertStringStartsWith('RE: Odpověď na Vaše hlášení', $m['subject']);
        $this->assertSame("Dobrý den,\n\nžluťoučký kůň úpěl ďábelské ódy – ventil v předsíni je opravený.\n\nS pozdravem\nJana Dvořáková", $this->reply('outlook-cp1250-qp.eml'),
            'blok Od:/Odesláno: z Outlooku a vše pod ním se odřízne');
    }

    public function testAppleMailBase64Utf8(): void
    {
        $this->assertSame('Dobrý den, děkuji, zítra budu doma od 8 do 12 hodin.', $this->reply('apple-base64.eml'), '„Odesláno z iPhonu“ a citace pryč');
    }

    public function testMultipartAlternativeWithAttachments(): void
    {
        $m = inbound_mime_parse($this->fixture('multipart-attachments.eml'));
        $this->assertStringStartsWith('Posílám fotku kohoutku a protokol.', $m['text'], 'přednost má text/plain');
        $this->assertStringNotContainsString('HTML verze', $m['text']);
        $this->assertSame('Posílám fotku kohoutku a protokol.', inbound_reply_text($m['text']), '„-----Original Message-----“');
        $this->assertSame(['kohoutek.png', 'protokol č. 1.pdf', 'logo.png'], array_column($m['attachments'], 'name'), 'název podle RFC 2231 se rozkóduje');
        $this->assertSame([false, false, true], array_column($m['attachments'], 'inline'));
        $this->assertStringStartsWith("\x89PNG", $m['attachments'][0]['data']);
        $this->assertStringStartsWith('%PDF-1.4', $m['attachments'][1]['data']);
        $this->assertContains('multipart/alternative', $m['types']);
    }

    public function testSeznamOriginalMessage(): void
    {
        $m = inbound_mime_parse($this->fixture('seznam.eml'));
        $this->assertStringStartsWith('Re: Odpověď', $m['subject']);
        $this->assertSame('Ano, souhlasím.', inbound_reply_text($m['text']));
    }

    /** Další běžné vzory citace a podpisů (anglické klienty, Thunderbird, Outlook na webu). */
    public function testQuotePatterns(): void
    {
        $cases = [
            "Thanks, fixed.\n\nOn Wed, Nov 26, 2026 at 10:15 AM SVJ <vybor@example.cz> wrote:\n> old" => 'Thanks, fixed.',
            "Díky.\n\nOn Wed, Nov 26, 2026 at 10:15 AM SVJ Zahradní 12 <vybor@example.cz>\nwrote:\n\n> old" => 'Díky.',
            "OK\n\n-------- Původní zpráva --------\nPředmět: x\n" => 'OK',
            "Hotovo\n________________________________\nFrom: SVJ <vybor@example.cz>\nSent: Wednesday, November 26, 2026 10:15\nTo: me\n" => 'Hotovo',
            "Hotovo\n\n*From:* SVJ <vybor@example.cz>\n*Sent:* Wednesday\n" => 'Hotovo',
            "Ano\n\n26. 11. 2026 v 10:15, SVJ <vybor@example.cz>:\n> původní\n" => 'Ano',
            "Ano\n\nSent from my iPhone" => 'Ano',
            "Ano\nGet Outlook for Android" => 'Ano',
            "Odpověď mezi citací:\n> otázka?\nmoje odpověď\n> další" => "Odpověď mezi citací:\nmoje odpověď",
            "Text\n--\nPodpis" => 'Text',
        ];
        foreach ($cases as $in => $out) {
            $this->assertSame($out, inbound_reply_text($in), json_encode($in, JSON_UNESCAPED_UNICODE));
        }
    }

    public function testHeaderAndCharsetHelpers(): void
    {
        $this->assertSame('Žluťoučký kůň', inbound_header_decode('=?UTF-8?B?xb1sdcWlb3XEjWvDvQ==?= =?UTF-8?Q?_k=C5=AF=C5=88?='));
        $this->assertSame('Příliš', inbound_header_decode('=?iso-8859-2?Q?P=F8=EDli=B9?='));
        $this->assertSame('čšž', inbound_utf8("\xE8\x9A\x9E", 'windows-1250'));
        $this->assertSame('příliš', inbound_utf8("p\xF8\xEDli\x9A", ''), 'bez znakové sady a neplatné UTF-8 → windows-1250');
        $this->assertSame('příliš', inbound_utf8('příliš', ''), 'platné UTF-8 zůstane');
        $this->assertSame('ok', inbound_utf8('ok', 'x-neznama-sada'), 'neznámá sada nezpůsobí chybu');
        $this->assertSame('jan.novak@example.cz', inbound_address('"Novák, Jan" <Jan.Novak@Example.CZ>'));
        $this->assertSame('a@b.cz', inbound_address('a@b.cz'));
        $this->assertSame('', inbound_address('Nikdo'));
        [$type, $p] = inbound_header_params('text/plain; charset="windows-1250"; format=flowed');
        $this->assertSame(['text/plain', 'windows-1250', 'flowed'], [$type, $p['charset'], $p['format']]);
        [, $p] = inbound_header_params("attachment; filename*0*=UTF-8''%C5%BEl; filename*1*=u%C5%A5.pdf");
        $this->assertSame('žluť.pdf', $p['filename']);
    }

    public function testBrokenInputDoesNotFail(): void
    {
        $m = inbound_mime_parse("From: x@y.cz\nContent-Type: multipart/mixed\n\nbez hranice");
        $this->assertSame('', $m['text']);
        $m = inbound_mime_parse('');
        $this->assertSame('', $m['from']);
        // Hluboké vnoření: zpracuje se jen omezený počet částí.
        $raw = "From: x@y.cz\nContent-Type: multipart/mixed; boundary=b0\n\n";
        for ($i = 0; $i < 20; $i++) {
            $raw .= "--b$i\nContent-Type: multipart/mixed; boundary=b" . ($i + 1) . "\n\n";
        }
        $raw .= "--b20\nContent-Type: text/plain\n\nhluboko\n";
        $this->assertIsArray(inbound_mime_parse($raw));
    }
}
