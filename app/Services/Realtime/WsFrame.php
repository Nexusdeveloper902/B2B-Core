<?php

namespace App\Services\Realtime;

/**
 * Minimal RFC 6455 WebSocket frame codec (TASK-016, ADR-026).
 *
 * Server->client frames are unmasked (as the RFC requires); client->
 * server frames MUST be masked and are decoded with the 4-byte XOR
 * key. Only what the realtime feed needs is implemented: text/ping/
 * pong/close opcodes, FIN-only messages, the three length encodings,
 * and a 1 MiB sanity ceiling on inbound frame size. No fragmentation
 * reassembly (browsers don't fragment small client messages) and no
 * permessage-deflate (we advertise no extensions, so clients must
 * send uncompressed).
 */
final class WsFrame
{
    public const OP_CONT = 0x0;

    public const OP_TEXT = 0x1;

    public const OP_BINARY = 0x2;

    public const OP_CLOSE = 0x8;

    public const OP_PING = 0x9;

    public const OP_PONG = 0xA;

    /** Hard ceiling on a single inbound frame: 1 MiB (protocol abuse guard). */
    public const MAX_INBOUND = 0x100000;

    /**
     * Encode a server->client frame (never masked, per RFC 6455 §5.1).
     *
     * @param  int  $opcode  one of the OP_* constants
     */
    public static function encode(string $payload, int $opcode = self::OP_TEXT, bool $fin = true): string
    {
        $length = \strlen($payload);
        $frame = chr(($fin ? 0x80 : 0x00) | $opcode);

        if ($length < 126) {
            $frame .= chr($length);
        } elseif ($length < 65536) {
            $frame .= chr(126).pack('n', $length);
        } else {
            $frame .= chr(127).pack('J', $length);
        }

        return $frame.$payload;
    }

    /**
     * Decode the first complete frame from $buffer.
     *
     * @return array{consumed: int, frame: array{fin: bool, opcode: int, payload: string}|null, error: string|null}
     *                                                                                                              consumed is the byte count to trim from the buffer (0 while
     *                                                                                                              incomplete); frame is null until a whole frame is buffered;
     *                                                                                                              error is set for protocol violations the caller should treat
     *                                                                                                              as a reason to drop the client.
     */
    public static function decode(string $buffer): array
    {
        $len = \strlen($buffer);
        if ($len < 2) {
            return ['consumed' => 0, 'frame' => null, 'error' => null];
        }

        $b0 = \ord($buffer[0]);
        $b1 = \ord($buffer[1]);

        $fin = ($b0 & 0x80) !== 0;
        $rsv = ($b0 & 0x70) !== 0; // reserved bits — must be zero
        $opcode = $b0 & 0x0F;
        $masked = ($b1 & 0x80) !== 0;
        $length = $b1 & 0x7F;
        $offset = 2;

        if ($length === 126) {
            if ($len < 4) {
                return ['consumed' => 0, 'frame' => null, 'error' => null];
            }
            $length = unpack('n', substr($buffer, 2, 2))[1];
            $offset = 4;
        } elseif ($length === 127) {
            if ($len < 10) {
                return ['consumed' => 0, 'frame' => null, 'error' => null];
            }
            $length = unpack('J', substr($buffer, 2, 8))[1];
            $offset = 10;
        }

        if ($rsv || $length > self::MAX_INBOUND || ($length !== 0 && $opcode === self::OP_CONT)) {
            return ['consumed' => 0, 'frame' => null, 'error' => 'protocol'];
        }

        $mask = '';
        if ($masked) {
            if ($len < $offset + 4) {
                return ['consumed' => 0, 'frame' => null, 'error' => null];
            }
            $mask = substr($buffer, $offset, 4);
            $offset += 4;
        }

        if ($len < $offset + $length) {
            return ['consumed' => 0, 'frame' => null, 'error' => null];
        }

        $payload = substr($buffer, $offset, $length);
        if ($masked) {
            for ($i = 0; $i < $length; $i++) {
                $payload[$i] = \chr(\ord($payload[$i]) ^ \ord($mask[$i % 4]));
            }
        }

        return [
            'consumed' => $offset + $length,
            'frame' => ['fin' => $fin, 'opcode' => $opcode, 'payload' => $payload],
            'error' => null,
        ];
    }

    /** Client->server frames are masked in tests; build one the RFC-legal way. */
    public static function encodeMasked(string $payload, int $opcode = self::OP_TEXT, ?string $mask = null): string
    {
        $mask ??= random_bytes(4);
        $masked = '';
        $length = \strlen($payload);
        for ($i = 0; $i < $length; $i++) {
            $masked .= \chr(\ord($payload[$i]) ^ \ord($mask[$i % 4]));
        }

        $frame = chr(0x80 | $opcode);
        if ($length < 126) {
            $frame .= chr(0x80 | $length);
        } elseif ($length < 65536) {
            $frame .= chr(0x80 | 126).pack('n', $length);
        } else {
            $frame .= chr(0x80 | 127).pack('J', $length);
        }

        return $frame.$mask.$masked;
    }
}
