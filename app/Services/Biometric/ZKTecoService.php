<?php

namespace App\Services\Biometric;

use RuntimeException;

/**
 * ZKTecoService — pure PHP TCP/IP client for ZKTeco/eSSL biometric devices.
 *
 * Tested against: eSSL AIFACE-MAGNUM (ZKTeco SDK protocol, port 4370).
 *
 * Protocol reference: https://github.com/adrobinoga/zk-protocol
 *
 * Packet layout (TCP):
 *   [4 bytes magic][2 bytes total_length][2 bytes reserved]  ← TCP outer header
 *   [2 bytes cmd][2 bytes checksum][2 bytes session_id][2 bytes reply_id][N bytes data] ← inner
 */
class ZKTecoService
{
    // ── ZKTeco command codes ───────────────────────────────────────────────
    private const CMD_CONNECT = 1000;

    private const CMD_EXIT = 1001;

    private const CMD_ENABLE_DEVICE = 1002;

    private const CMD_DISABLE_DEVICE = 1003;

    private const CMD_RESTART = 1004;

    private const CMD_ACK_OK = 2000;

    private const CMD_ACK_ERROR = 2001;

    private const CMD_ACK_DATA = 2002;

    // eSSL AIFACE-MAGNUM and some newer firmware variants return 6001 (0x1771)
    // instead of CMD_ACK_OK for a successful CMD_CONNECT.  The session_id is
    // valid and no authentication step is required.
    private const CMD_ACK_OK_ESSL = 6001;

    // Returned by CMD_READ_ALLLOG / CMD_USERTEMP_RRQ when the device log is empty.
    private const CMD_ACK_EMPTY = 2032;

    private const CMD_PREPARE_DATA = 1500;

    private const CMD_DATA = 1501;

    private const CMD_FREE_DATA = 1502;

    private const CMD_READ_ALLLOG = 13;   // get attendance log

    private const CMD_CLEAR_ATTLOG = 211;  // erase all attendance logs

    private const CMD_USERTEMP_RRQ = 9;    // get enrolled users

    private const CMD_AUTH = 1102;         // authenticate with comm key / password

    // ── TCP framing ────────────────────────────────────────────────────────
    private const TCP_MAGIC = "\x50\x50\x82\x7d"; // 4-byte header magic

    private const HEADER_SIZE = 8;   // outer TCP header bytes

    private const CMD_HEADER_SIZE = 8;   // inner command header bytes

    // ── Attendance record ──────────────────────────────────────────────────
    // Each raw record returned by the device is 40 bytes:
    //   0-1  : device user ID (uint16 LE)
    //   2-5  : ZK-encoded timestamp (uint32 LE)
    //   6    : state  byte (0=check_in, 1=check_out, 4=ot_in, 5=ot_out)
    //   7    : verify byte (1=FP, 2=PIN, 3=Card, 4=Face)
    //   8-39 : reserved / padding
    private const RECORD_SIZE = 40;

    private mixed $socket = null;

    private int $sessionId = 0;

    // ZKTeco SDK spec: CMD_CONNECT must use reply_id = 0xFFFF (65535).
    // Initialise at 65534 so the first increment lands on 65535.
    private int $replyId = 65534;

    // Set to true to dump raw packets to stdout (for one-time protocol debugging).
    public bool $debug = false;

    public function __construct(
        private readonly string $ip,
        private readonly int $port = 4370,
        private readonly int $timeout = 30,
    ) {}

    // ──────────────────────────────────────────────────────────────────────
    // Public API
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Open a TCP connection to the device and establish a ZKTeco session.
     *
     * Handles three protocol variants automatically:
     *   A) Standard: CMD_CONNECT → CMD_ACK_OK (session_id in header)
     *   B) Welcome:  device sends an unsolicited banner first, then A
     *   C) Auth:     device sends CMD_UNAUTHENTICATED (cmd!=ACK_OK), we send blank-password auth
     *
     * @throws RuntimeException when the socket cannot be opened or the device rejects the handshake.
     */
    public function connect(): bool
    {
        $this->socket = @fsockopen('tcp://'.$this->ip, $this->port, $errno, $errstr, $this->timeout);

        if ($this->socket === false) {
            throw new RuntimeException("Cannot connect to device {$this->ip}:{$this->port} — {$errstr} ({$errno})");
        }

        stream_set_timeout($this->socket, $this->timeout);

        // ── Some eSSL devices send an unsolicited banner packet the moment TCP connects.
        //    Use a 1-second peek window to drain it before we send CMD_CONNECT.
        $this->drainWelcomePacket();

        $response = $this->sendCommand(self::CMD_CONNECT);
        $this->debugPacket('RECV CMD_CONNECT response', $response);

        // Both ACK_OK (2000) and the eSSL-specific 6001 mean "session established".
        if ($response['cmd'] === self::CMD_ACK_OK || $response['cmd'] === self::CMD_ACK_OK_ESSL) {
            $this->sessionId = $response['session_id'];

            return true;
        }

        // ── Device requires authentication (comm key / password).
        //    Try blank password — if the device has no password set this always succeeds.
        $this->sessionId = $response['session_id'];
        $authResult = $this->sendAuthCommand('');
        $this->debugPacket('RECV CMD_AUTH response', $authResult);

        if ($authResult['cmd'] === self::CMD_ACK_OK || $authResult['cmd'] === self::CMD_ACK_OK_ESSL) {
            $this->sessionId = $authResult['session_id'];

            return true;
        }

        $this->closeSocket();
        throw new RuntimeException(
            "Device rejected session (connect cmd={$response['cmd']}, auth cmd={$authResult['cmd']}). "
            .'Check: device comm key, SDK mode, and firmware version. '
            .'Re-run with --debug to see raw bytes.'
        );
    }

    /**
     * Drain any unsolicited data the device pushes right after TCP connect.
     *
     * Uses stream_select with a 100 ms timeout — non-blocking so we don't
     * stall the CMD_CONNECT handshake. Some ZKTeco devices close the connection
     * if CMD_CONNECT doesn't arrive within ~500 ms of the TCP handshake.
     */
    private function drainWelcomePacket(): void
    {
        $read = [$this->socket];
        $write = null;
        $except = null;

        // 100 ms peek: 0 seconds + 100 000 microseconds.
        $ready = stream_select($read, $write, $except, 0, 100_000);

        if ($ready > 0) {
            $peek = fread($this->socket, 256);

            if ($peek !== false && $peek !== '') {
                $this->debugRaw('DRAIN welcome bytes', $peek);
            }
        }
    }

    /**
     * Blank-password authentication used when CMD_CONNECT returns a non-OK response.
     * Sends CMD_AUTH (1102) with a 4-byte zero key.
     *
     * @return array{cmd: int, checksum: int, session_id: int, reply_id: int, data: string}
     */
    private function sendAuthCommand(string $password): array
    {
        // ZKTeco comm key is a 4-byte little-endian int (0 = no password).
        $key = pack('V', crc32($password));

        return $this->sendCommand(self::CMD_AUTH, $key);
    }

    /**
     * Gracefully close the session and release the TCP socket.
     */
    public function disconnect(): void
    {
        if ($this->socket) {
            try {
                $this->sendCommand(self::CMD_EXIT);
            } catch (\Throwable) {
                // Ignore errors during disconnect — we close the socket regardless.
            }

            $this->closeSocket();
        }
    }

    /**
     * Ping the device — returns true if reachable, false otherwise.
     * Does NOT require a live session; opens and immediately closes one.
     */
    public function ping(): bool
    {
        try {
            $this->connect();
            $this->disconnect();

            return true;
        } catch (\Throwable) {
            $this->closeSocket();

            return false;
        }
    }

    /**
     * Pull all attendance punch records stored on the device.
     *
     * @return array<int, array{
     *     device_user_id: string,
     *     punched_at: string,
     *     state: int,
     *     verify: int,
     * }>
     *
     * @throws RuntimeException
     */
    public function getAttendance(): array
    {
        $this->assertConnected();
        $this->sendCommand(self::CMD_DISABLE_DEVICE);

        try {
            $raw = $this->readBulkData(self::CMD_READ_ALLLOG);
        } finally {
            $this->sendCommand(self::CMD_ENABLE_DEVICE);
        }

        return $this->parseAttendanceRecords($raw);
    }

    /**
     * Pull all enrolled user records stored on the device.
     *
     * @return array<int, array{device_user_id: string, name: string, privilege: int}>
     *
     * @throws RuntimeException
     */
    public function getUsers(): array
    {
        $this->assertConnected();

        $raw = $this->readBulkData(self::CMD_USERTEMP_RRQ);

        return $this->parseUserRecords($raw);
    }

    /**
     * Erase all attendance logs from the device memory.
     * ⚠ This is irreversible — only call after a confirmed successful sync.
     *
     * @throws RuntimeException
     */
    public function clearAttendance(): bool
    {
        $this->assertConnected();

        $response = $this->sendCommand(self::CMD_CLEAR_ATTLOG);

        return in_array($response['cmd'], [self::CMD_ACK_OK, self::CMD_ACK_OK_ESSL], true);
    }

    /**
     * Restart the device (useful after a failed session).
     */
    public function restart(): bool
    {
        $this->assertConnected();

        $response = $this->sendCommand(self::CMD_RESTART);

        return in_array($response['cmd'], [self::CMD_ACK_OK, self::CMD_ACK_OK_ESSL], true);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Protocol internals
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Send a command packet and return the parsed response.
     *
     * @param  string  $data  Optional payload bytes appended after the command header.
     * @return array{cmd: int, checksum: int, session_id: int, reply_id: int, data: string}
     *
     * @throws RuntimeException
     */
    private function sendCommand(int $cmd, string $data = ''): array
    {
        $innerPacket = $this->buildInnerPacket($cmd, $data);
        $outerPacket = $this->buildOuterPacket($innerPacket);

        if ($this->debug) {
            $this->debugRaw("SEND cmd={$cmd}", $outerPacket);
        }

        $written = fwrite($this->socket, $outerPacket);

        if ($written === false || $written !== strlen($outerPacket)) {
            throw new RuntimeException('Failed to write packet to device socket.');
        }

        $response = $this->readResponse();

        if ($this->debug) {
            $this->debugPacket("RECV cmd={$cmd} response", $response);
        }

        return $response;
    }

    /**
     * Build the 8-byte inner command packet (cmd + checksum + session + reply + data).
     */
    private function buildInnerPacket(int $cmd, string $data = ''): string
    {
        // Keep reply_id as a 16-bit counter (wraps 65535 → 0 → 1 …).
        $this->replyId = ($this->replyId + 1) & 0xFFFF;

        // Pack with placeholder checksum = 0, then compute real checksum over the payload.
        $header = pack('vvvv', $cmd, 0, $this->sessionId, $this->replyId);
        $payload = $header.$data;
        $checksum = $this->computeChecksum($payload);

        // Rebuild with real checksum.
        return pack('vvvv', $cmd, $checksum, $this->sessionId, $this->replyId).$data;
    }

    /**
     * Wrap the inner packet in the 8-byte TCP outer header.
     */
    private function buildOuterPacket(string $inner): string
    {
        return self::TCP_MAGIC.pack('v', strlen($inner))."\x00\x00".$inner;
    }

    /**
     * Read one response packet from the socket and parse its fields.
     *
     * @return array{cmd: int, checksum: int, session_id: int, reply_id: int, data: string}
     *
     * @throws RuntimeException
     */
    private function readResponse(): array
    {
        $header = $this->readExact(self::HEADER_SIZE);

        if ($header === false || strlen($header) < self::HEADER_SIZE) {
            throw new RuntimeException('Timed out or connection lost while reading response header.');
        }

        // Validate magic bytes.
        if (substr($header, 0, 4) !== self::TCP_MAGIC) {
            throw new RuntimeException('Response magic mismatch — unexpected device protocol.');
        }

        $bodyLen = unpack('v', substr($header, 4, 2))[1];
        $body = $bodyLen > 0 ? $this->readExact($bodyLen) : '';

        if ($bodyLen > 0 && (strlen($body) < $bodyLen)) {
            throw new RuntimeException('Truncated response body from device.');
        }

        if (strlen($body) < self::CMD_HEADER_SIZE) {
            throw new RuntimeException('Response body too short to contain a command header.');
        }

        $fields = unpack('vcmd/vchecksum/vsession_id/vreply_id', substr($body, 0, 8));

        return [
            'cmd' => $fields['cmd'],
            'checksum' => $fields['checksum'],
            'session_id' => $fields['session_id'],
            'reply_id' => $fields['reply_id'],
            'data' => substr($body, 8),
        ];
    }

    /**
     * Issue a command that triggers a bulk-data transfer.
     *
     * eSSL AIFACE-MAGNUM uses a two-phase response:
     *   Phase 1 — ACK (cmd=2032, data_len=0): command acknowledged
     *   Phase 2 — CMD_PREPARE_DATA (1500): total byte count, followed by CMD_DATA chunks
     *
     * Standard ZKTeco firmware sends CMD_PREPARE_DATA directly (single-phase).
     * Both flows are handled here.
     *
     * @throws RuntimeException
     */
    private function readBulkData(int $cmd): string
    {
        $response = $this->sendCommand($cmd);

        // ── Phase 1: ACK with no inline data ─────────────────────────────────
        // eSSL AIFACE-MAGNUM sends cmd=2032 (ACK) first, then optionally a
        // CMD_PREPARE_DATA packet if there is data to transfer.
        // Use stream_select to peek within 500 ms — if nothing arrives the log is empty.
        if ($response['cmd'] === self::CMD_ACK_EMPTY && strlen($response['data']) === 0) {
            $read = [$this->socket];
            $write = null;
            $except = null;

            $ready = stream_select($read, $write, $except, 0, 500_000); // 500 ms

            if ($ready <= 0) {
                return ''; // no Phase 2 packet → device has no records
            }

            $response = $this->readResponse(); // read Phase 2 packet (CMD_PREPARE_DATA)
        }

        // ── Inline small data (standard ZKTeco ACK_OK or eSSL variant) ───────
        if ($response['cmd'] === self::CMD_ACK_OK || $response['cmd'] === self::CMD_ACK_OK_ESSL) {
            // If ACK carries inline data return it, otherwise no records.
            return $response['data'];
        }

        // ── No records (ACK_EMPTY with explicit empty payload) ────────────────
        if ($response['cmd'] === self::CMD_ACK_EMPTY) {
            return '';
        }

        // ── Bulk transfer: CMD_PREPARE_DATA → CMD_DATA chunks → CMD_FREE_DATA ─
        if ($response['cmd'] !== self::CMD_PREPARE_DATA) {
            throw new RuntimeException("Unexpected response to bulk command {$cmd}: cmd={$response['cmd']}");
        }

        // First 4 bytes of prepare-data body = total payload size in bytes.
        $totalBytes = unpack('V', substr($response['data'], 0, 4))[1];
        $buffer = '';

        while (strlen($buffer) < $totalBytes) {
            $chunk = $this->readResponse();

            if ($chunk['cmd'] === self::CMD_DATA) {
                $buffer .= $chunk['data'];

                continue;
            }

            if ($chunk['cmd'] === self::CMD_FREE_DATA) {
                break;
            }

            throw new RuntimeException("Unexpected cmd {$chunk['cmd']} during bulk data read.");
        }

        // Acknowledge receipt.
        $this->sendCommand(self::CMD_FREE_DATA);

        return $buffer;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Record parsing
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Parse the raw binary attendance dump into structured PHP arrays.
     *
     * @return array<int, array{device_user_id: string, punched_at: string, state: int, verify: int}>
     */
    private function parseAttendanceRecords(string $raw): array
    {
        $records = [];
        $len = strlen($raw);

        for ($offset = 0; $offset + self::RECORD_SIZE <= $len; $offset += self::RECORD_SIZE) {
            $record = substr($raw, $offset, self::RECORD_SIZE);

            $userId = unpack('v', substr($record, 0, 2))[1];         // uint16 LE
            $zkTime = unpack('V', substr($record, 2, 4))[1];         // uint32 LE
            $state = ord($record[6]);
            $verify = ord($record[7]);

            if ($zkTime === 0) {
                continue;  // Empty / uninitialized record — skip.
            }

            $records[] = [
                'device_user_id' => (string) $userId,
                'punched_at' => $this->decodeZKTime($zkTime),
                'state' => $state,
                'verify' => $verify,
            ];
        }

        return $records;
    }

    /**
     * Parse the raw binary user dump.
     *
     * @return array<int, array{device_user_id: string, name: string, privilege: int}>
     */
    private function parseUserRecords(string $raw): array
    {
        // User records are 72 bytes each in standard ZKTeco SDK.
        // Layout: [2 user_id][2 privilege][8 password][24 name][1 card_hi][4 card][1 enable][30 reserved]
        $recordSize = 72;
        $records = [];
        $len = strlen($raw);

        for ($offset = 0; $offset + $recordSize <= $len; $offset += $recordSize) {
            $record = substr($raw, $offset, $recordSize);
            $userId = unpack('v', substr($record, 0, 2))[1];
            $privilege = ord($record[2]);
            $name = rtrim(substr($record, 12, 24), "\x00");

            $records[] = [
                'device_user_id' => (string) $userId,
                'name' => $name,
                'privilege' => $privilege,
            ];
        }

        return $records;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Decode a ZKTeco 32-bit timestamp into a MySQL-compatible datetime string.
     *
     * ZK time encoding:
     *   ((year - 2000) * 12 * 31 + (month-1) * 31 + (day-1)) * 86400
     *   + hour * 3600 + minute * 60 + second
     */
    private function decodeZKTime(int $zkTime): string
    {
        $second = $zkTime % 60;
        $zkTime = intdiv($zkTime, 60);
        $minute = $zkTime % 60;
        $zkTime = intdiv($zkTime, 60);
        $hour = $zkTime % 24;
        $zkTime = intdiv($zkTime, 24);
        $day = $zkTime % 31 + 1;
        $zkTime = intdiv($zkTime, 31);
        $month = $zkTime % 12 + 1;
        $zkTime = intdiv($zkTime, 12);
        $year = $zkTime + 2000;

        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second);
    }

    /**
     * ZKTeco checksum — one's complement sum of 16-bit words.
     *
     * Algorithm per the ZK protocol spec:
     *   1. Accumulate 16-bit LE words; subtract 0xFFFF (not 0x10000) on carry.
     *   2. Return bitwise NOT (one's complement), NOT two's complement.
     */
    private function computeChecksum(string $data): int
    {
        $sum = 0;
        $len = strlen($data);

        for ($i = 0; $i + 1 < $len; $i += 2) {
            $sum += unpack('v', $data[$i].$data[$i + 1])[1];
            if ($sum > 0xFFFF) {
                $sum -= 0xFFFF;  // carry: subtract 65535, not 65536
            }
        }

        // Handle odd trailing byte.
        if ($len % 2 !== 0) {
            $sum += ord($data[$len - 1]);
            if ($sum > 0xFFFF) {
                $sum -= 0xFFFF;
            }
        }

        return (~$sum) & 0xFFFF;  // one's complement (no +1)
    }

    /**
     * Read exactly $length bytes from the socket (blocks until available or timeout).
     * Checks the timed_out flag AFTER each fread so we don't miss the first read.
     */
    private function readExact(int $length): string|false
    {
        $buffer = '';

        while (strlen($buffer) < $length) {
            $chunk = fread($this->socket, $length - strlen($buffer));

            if ($chunk === false || $chunk === '') {
                // Distinguish timeout from closed connection for a clearer error.
                $meta = stream_get_meta_data($this->socket);
                if ($meta['timed_out']) {
                    throw new RuntimeException(
                        "Device did not respond within {$this->timeout}s — check IP/port and that the device is powered on."
                    );
                }

                return false; // connection closed by device
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Debug helpers (only active when $this->debug = true)
    // ──────────────────────────────────────────────────────────────────────

    /** Dump a parsed response packet to stdout for protocol analysis. */
    private function debugPacket(string $label, array $packet): void
    {
        if (! $this->debug) {
            return;
        }

        printf(
            "[ZKTeco DEBUG] %s | cmd=%d (0x%04X) session=%d reply=%d data_len=%d data_hex=%s\n",
            $label,
            $packet['cmd'],
            $packet['cmd'],
            $packet['session_id'],
            $packet['reply_id'],
            strlen($packet['data']),
            bin2hex($packet['data'])
        );
    }

    /** Dump raw bytes to stdout for protocol analysis. */
    private function debugRaw(string $label, string $raw): void
    {
        if (! $this->debug) {
            return;
        }

        printf("[ZKTeco DEBUG] %s | %d bytes: %s\n", $label, strlen($raw), bin2hex($raw));
    }

    private function closeSocket(): void
    {
        if ($this->socket) {
            fclose($this->socket);
            $this->socket = null;
            $this->sessionId = 0;
            $this->replyId = 65534; // reset so next connect() sends reply_id = 65535
        }
    }

    private function assertConnected(): void
    {
        if (! $this->socket) {
            throw new RuntimeException('Not connected to device. Call connect() first.');
        }
    }
}
