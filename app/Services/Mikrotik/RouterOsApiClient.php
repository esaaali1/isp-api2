<?php

namespace App\Services\Mikrotik;

use App\Exceptions\MikrotikConnectionException;

/**
 * Minimal RouterOS API client (the binary protocol MikroTik routers speak
 * on port 8728/8729), implemented directly over a TCP socket so the project
 * doesn't need an extra composer dependency for a handful of read-only
 * queries. Supports the "plain" login used by RouterOS >= 6.43.
 *
 * Protocol reference: https://help.mikrotik.com/docs/display/ROS/API
 */
class RouterOsApiClient
{
    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly float $timeout,
    ) {
    }

    public function connect(string $user, string $password): void
    {
        $errno = 0;
        $errstr = '';

        $socket = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);

        if ($socket === false) {
            throw new MikrotikConnectionException("تعذّر الاتصال بالراوتر {$this->host}:{$this->port} — {$errstr}");
        }

        stream_set_timeout($socket, (int) ceil($this->timeout));
        $this->socket = $socket;

        $this->writeSentence(['/login', "=name={$user}", "=password={$password}"]);
        $reply = $this->readSentence();

        if (($reply[0] ?? null) !== '!done') {
            $this->close();

            throw new MikrotikConnectionException('فشل تسجيل الدخول إلى واجهة MikroTik API (تحقق من user/pass).');
        }
    }

    /**
     * Runs a RouterOS API command (e.g. "/ppp/active/print") with optional
     * "?key=value" query words, and returns the list of "!re" rows as
     * associative arrays (attribute name => value, without the leading "=").
     *
     * @param  list<string>  $queryWords
     * @return list<array<string, string>>
     */
    public function query(string $command, array $queryWords = []): array
    {
        if ($this->socket === null) {
            throw new MikrotikConnectionException('RouterOS API: not connected.');
        }

        $this->writeSentence([$command, ...$queryWords]);

        $rows = [];

        while (true) {
            $sentence = $this->readSentence();

            if ($sentence === [] || $sentence[0] === '!done') {
                break;
            }

            if ($sentence[0] === '!trap') {
                continue;
            }

            if ($sentence[0] === '!re') {
                $row = [];

                foreach (array_slice($sentence, 1) as $word) {
                    if (preg_match('/^=([^=]+)=(.*)$/s', $word, $m)) {
                        $row[$m[1]] = $m[2];
                    }
                }

                $rows[] = $row;
            }
        }

        return $rows;
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }

        $this->socket = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    /** @param list<string> $words */
    private function writeSentence(array $words): void
    {
        foreach ($words as $word) {
            $this->writeWord($word);
        }

        // Zero-length word terminates the sentence.
        fwrite($this->socket, chr(0));
    }

    private function writeWord(string $word): void
    {
        fwrite($this->socket, $this->encodeLength(strlen($word)).$word);
    }

    private function encodeLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        if ($length < 0x4000) {
            $length |= 0x8000;

            return chr(($length >> 8) & 0xFF).chr($length & 0xFF);
        }

        if ($length < 0x200000) {
            $length |= 0xC00000;

            return chr(($length >> 16) & 0xFF).chr(($length >> 8) & 0xFF).chr($length & 0xFF);
        }

        if ($length < 0x10000000) {
            $length |= 0xE0000000;

            return chr(($length >> 24) & 0xFF).chr(($length >> 16) & 0xFF).chr(($length >> 8) & 0xFF).chr($length & 0xFF);
        }

        return chr(0xF0).pack('N', $length);
    }

    /** @return list<string> */
    private function readSentence(): array
    {
        $words = [];

        while (true) {
            $word = $this->readWord();

            if ($word === '') {
                break;
            }

            $words[] = $word;
        }

        return $words;
    }

    private function readWord(): string
    {
        $length = $this->readLength();

        if ($length === 0) {
            return '';
        }

        $data = '';

        while (strlen($data) < $length) {
            $chunk = fread($this->socket, $length - strlen($data));

            if ($chunk === false || $chunk === '') {
                throw new MikrotikConnectionException('انقطع الاتصال أثناء القراءة من واجهة MikroTik API.');
            }

            $data .= $chunk;
        }

        return $data;
    }

    private function readLength(): int
    {
        $firstByte = $this->readByte();

        if (($firstByte & 0x80) === 0x00) {
            return $firstByte;
        }

        if (($firstByte & 0xC0) === 0x80) {
            return (($firstByte & 0x3F) << 8) | $this->readByte();
        }

        if (($firstByte & 0xE0) === 0xC0) {
            return (($firstByte & 0x1F) << 16) | ($this->readByte() << 8) | $this->readByte();
        }

        if (($firstByte & 0xF0) === 0xE0) {
            return (($firstByte & 0x0F) << 24) | ($this->readByte() << 16) | ($this->readByte() << 8) | $this->readByte();
        }

        $bytes = fread($this->socket, 4);

        if ($bytes === false || strlen($bytes) < 4) {
            throw new MikrotikConnectionException('انقطع الاتصال أثناء القراءة من واجهة MikroTik API.');
        }

        return unpack('N', $bytes)[1];
    }

    private function readByte(): int
    {
        $byte = fread($this->socket, 1);

        if ($byte === false || $byte === '') {
            throw new MikrotikConnectionException('انقطع الاتصال أثناء القراءة من واجهة MikroTik API.');
        }

        return ord($byte);
    }
}
