<?php
namespace PHPDaemon\Servers\WebSocket\Protocols;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Servers\WebSocket\Connection;

/**
 * WebSocket protocol hixie-76
 * @see    http://tools.ietf.org/html/draft-hixie-thewebsocketprotocol-76
 */
class V0 extends Connection
{

    const STRING = 0x00;
    const BINARY = 0x80;

    protected $key;

    /**
     * Sends a handshake message reply
     * @param string Received data (no use in this class)
     * @return boolean OK?
     */
    public function sendHandshakeReply($extraHeaders = '')
    {
        if (!isset($this->server['HTTP_SEC_WEBSOCKET_KEY1']) || !isset($this->server['HTTP_SEC_WEBSOCKET_KEY2'])) {
            return false;
        }

        $final_key = $this->_computeFinalKey(
            $this->server['HTTP_SEC_WEBSOCKET_KEY1'],
            $this->server['HTTP_SEC_WEBSOCKET_KEY2'],
            $this->key
        );
        $this->key = null;

        if (!$final_key) {
            return false;
        }

        if (!isset($this->server['HTTP_SEC_WEBSOCKET_ORIGIN'])) {
            $this->server['HTTP_SEC_WEBSOCKET_ORIGIN'] = '';
        }

        $this->write("HTTP/1.1 101 Web Socket Protocol Handshake\r\n"
            . "Upgrade: WebSocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Origin: " . $this->server['HTTP_ORIGIN'] . "\r\n"
            . "Sec-WebSocket-Location: ws://" . $this->server['HTTP_HOST'] . $this->server['REQUEST_URI'] . "\r\n");
        if ($this->pool->config->expose->value) {
            $this->writeln('X-Powered-By: phpDaemon/' . Daemon::$version);
        }
        if (isset($this->server['HTTP_SEC_WEBSOCKET_PROTOCOL'])) {
            $this->writeln("Sec-WebSocket-Protocol: " . $this->server['HTTP_SEC_WEBSOCKET_PROTOCOL']);
        }
        $this->write($extraHeaders . "\r\n" . $final_key);
        return true;
    }

    /**
     * Computes final key for Sec-WebSocket.
     * @param string Key1
     * @param string Key2
     * @param string Data
     * @return string Result
     */
    protected function _computeFinalKey($key1, $key2, $data)
    {
        if (mb_orig_strlen($data) < 8) {
            Daemon::$process->log(get_class($this) . '::' . __METHOD__ . ' : Invalid handshake data for client "' . $this->addr . '"');
            return false;
        }
        return md5($this->_computeKey($key1) . $this->_computeKey($key2) . mb_orig_substr($data, 0, 8), true);
    }

    /**
     * Computes key for Sec-WebSocket.
     * @param string Key
     * @return string Result
     */
    protected function _computeKey($key)
    {
        $spaces = 0;
        $digits = '';

        for ($i = 0, $s = mb_orig_strlen($key); $i < $s; ++$i) {
            $c = mb_orig_substr($key, $i, 1);

            if ($c === "\x20") {
                ++$spaces;
            } elseif (ctype_digit($c)) {
                $digits .= $c;
            }
        }

        if ($spaces > 0) {
            $result = (float)floor($digits / $spaces);
        } else {
            $result = (float)$digits;
        }

        return pack('N', $result);
    }

    /**
     * Sends a frame.
     * @param  string $data Frame's data.
     * @param  string $type Frame's type. ("STRING" OR "BINARY")
     * @param  callable $cb Optional. Callback called when the frame is received by client.
     * @callback $cb ( )
     * @return boolean         Success.
     */
    public function sendFrame($data, $type = null, $cb = null)
    {
        if (!$this->handshaked) {
            return false;
        }

        if ($this->finished && $type !== 'CONNCLOSE') {
            return false;
        }
        if ($type === 'CONNCLOSE') {
            if ($cb !== null) {
                $cb($this);
                return true;
            }
        }

        $type = $this->getFrameType($type);
        // Binary
        if (($type & self::BINARY) === self::BINARY) {
            $n = mb_orig_strlen($data);
            $len = '';
            $pos = 0;

            char:

            ++$pos;
            $c = $n >> 0 & 0x7F;
            $n >>= 7;

            if ($pos !== 1) {
                $c += 0x80;
            }

            if ($c !== 0x80) {
                $len = chr($c) . $len;
                goto char;
            };

            $this->write(chr(self::BINARY) . $len . $data);
        } // String
        else {
            $this->write(chr(self::STRING) . $data . "\xFF");
        }
        if ($cb !== null) {
            $this->onWriteOnce($cb);
        }
        return true;
    }

    /**
     * Called when new data received
     * @return void
     */
    public function onRead()
    {
        if ($this->state === self::STATE_PREHANDSHAKE) {
            if ($this->getInputLength() < 8) {
                return;
            }
            $this->key = $this->readUnlimited();
            $this->handshake();
        }
        if ($this->state === self::STATE_HANDSHAKED) {
            while (($buflen = $this->getInputLength()) >= 2) {
                $hdr = $this->look(10);
                $frametype = ord(mb_orig_substr($hdr, 0, 1));
                if (($frametype & 0x80) === 0x80) {
                    $len = 0;
                    $i = 0;
                    do {
                        if ($buflen < $i + 1) {
                            // not enough data yet
                            return;
                        }
                        $b = ord(mb_orig_substr($hdr, ++$i, 1));
                        $n = $b & 0x7F;
                        $len *= 0x80;
                        $len += $n;
                    } while ($b > 0x80);

                    if ($this->pool->maxAllowedPacket <= $len) {
                        // Too big packet
                        $this->finish();
                        return;
                    }

                    if ($buflen < $len + $i + 1) {
                        // not enough data yet
                        return;
                    }
                    $this->drain($i + 1);
                    $this->onFrame($this->read($len), 'BINARY');
                } else {
                    if (($p = $this->search("\xFF")) !== false) {
                        if ($this->pool->maxAllowedPacket <= $p - 1) {
                            // Too big packet
                            $this->finish();
                            return;
                        }
                        $this->drain(1);
                        $data = $this->read($p);
                        $this->drain(1);
                        $this->onFrame($data, 'STRING');
                    } else {
                        if ($this->pool->maxAllowedPacket < $buflen - 1) {
                            // Too big packet
                            $this->finish();
                            return;
                        }
                        // not enough data yet
                        return;
                    }
                }
            }
        }
    }
}
