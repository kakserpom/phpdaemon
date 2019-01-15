<?php

namespace PHPDaemon\Clients\PostgreSQL;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Network\ClientConnection;
use PHPDaemon\Structures\StackCallbacks;

class Connection extends ClientConnection
{

    /**
     * @var string Protocol version
     */
    public $protover = '3.0';

    /**
     * @var integer Maximum packet size
     */
    public $maxPacketSize = 0x1000000;

    /**
     * @var integer Charset number
     */
    public $charsetNumber = 0x08;

    /**
     * @var string Database name
     */
    public $dbname = '';

    /**
     * @var string Username
     */
    protected $user = 'root';

    /**
     * @var string Password
     */
    protected $password = '';

    /**
     * @var string Default options
     */
    public $options = '';

    /**
     * @var integer Connection's state. 0 - start,  1 - got initial packet,  2 - auth. packet sent,  3 - auth. error,  4 - handshaked OK
     */
    public $state = 0;

    /**
     * @var string State of pointer of incoming data. 0 - Result Set Header Packet,  1 - Field Packet,  2 - Row Packet
     */
    public $instate = 0;

    /**
     * @var array Resulting rows
     */
    public $resultRows = [];

    /**
     * @var array Resulting fields
     */
    public $resultFields = [];

    /**
     * @var string Equals to INSERT_ID().
     */
    public $insertId;

    /**
     * @var integer Inserted rows number
     */
    public $insertNum;

    /**
     * @var integer Number of affected rows
     */
    public $affectedRows;

    /**
     * @var array Runtime parameters from server
     */
    public $parameters = [];

    /**
     * @var string Backend key
     */
    public $backendKey;

    /**
     * @var int
     */
    public $errno = 0;

    /**
     * @var string
     */
    public $errmsg;

    /**
     * @var
     */
    public $status;

    /**
     * State: authentication packet sent
     */
    const STATE_AUTH_PACKET_SENT = 2;

    /**
     * State: authencation error
     */
    const STATE_AUTH_ERROR = 3;

    /**
     * State: authentication passed
     */
    const STATE_AUTH_OK = 4;

    /**
     * Called when the stream is handshaked (at low-level), and peer is ready to recv. data
     * @return void
     */
    public function onReady()
    {
        if ($this->pool->config->protologging->value) {
            Daemon::log("New connection\n\n");
        }
        $e = explode('.', $this->protover);
        $packet = pack('nn', $e[0], $e[1]);

        if (strlen($this->user)) {
            $packet .= "user\x00" . $this->user . "\x00";
        }

        if (strlen($this->dbname)) {
            $packet .= "database\x00" . $this->dbname . "\x00";
        }
        elseif (strlen($this->path)) {
            $packet .= "database\x00" . $this->path . "\x00";
        }

        if (strlen($this->options)) {
            $packet .= "options\x00" . $this->options . "\x00";
        }

        $packet .= "\x00";
        $this->sendPacket('', $packet);
    }

    /**
     * Executes the given callback when/if the connection is handshaked.
     * @param  callable $cb Callback
     * @callback $cb ( )
     * @return void
     */
    public function onConnected($cb)
    {
        if ($this->state === self::STATE_AUTH_ERROR) {
            $cb($this, false);
        } elseif ($this->state === self::STATE_AUTH_OK) {
            $cb($this, true);
        } else {
            if (!$this->onConnected) {
                $this->onConnected = new StackCallbacks();
            }
            $this->onConnected->push($cb);
        }
    }

    /**
     * Converts binary string to integer
     * @param  string $str Binary string
     * @param  boolean $l Optional. Little endian. Default value - true.
     * @return integer      Resulting integer
     */
    public function bytes2int($str, $l = true)
    {
        if ($l) {
            $str = strrev($str);
        }

        $dec = 0;
        $len = mb_orig_strlen($str);

        for ($i = 0; $i < $len; ++$i) {
            $dec += ord(mb_orig_substr($str, $i, 1)) * pow(0x100, $len - $i - 1);
        }

        return $dec;
    }

    /**
     * Converts integer to binary string
     * @param  integer $len Length
     * @param  integer $int Integer
     * @param  boolean $l Optional. Little endian. Default value - true.
     * @return string       Resulting binary string
     */
    public function int2bytes($len, $int = 0, $l = true)
    {
        $hexstr = dechex($int);

        if ($len === null) {
            if (mb_orig_strlen($hexstr) % 2) {
                $hexstr = "0" . $hexstr;
            }
        } else {
            $hexstr = str_repeat('0', $len * 2 - mb_orig_strlen($hexstr)) . $hexstr;
        }

        $bytes = mb_orig_strlen($hexstr) / 2;
        $bin = '';

        for ($i = 0; $i < $bytes; ++$i) {
            $bin .= chr(hexdec(substr($hexstr, $i * 2, 2)));
        }

        return $l ? strrev($bin) : $bin;
    }

    /**
     * Send a packet
     * @param  string $type Data
     * @param  string $packet Packet
     * @return boolean         Success
     */
    public function sendPacket($type, $packet)
    {
        $header = $type . pack('N', mb_orig_strlen($packet) + 4);

        $this->write($header);
        $this->write($packet);

        if ($this->pool->config->protologging->value) {
            Daemon::log('Client --> Server: ' . Debug::exportBytes($header . $packet) . "\n\n");
        }

        return true;
    }

    /**
     * Builds length-encoded binary string
     * @param  string $s String
     * @return string    Resulting binary string
     */
    public function buildLenEncodedBinary($s)
    {
        if ($s === null) {
            return "\251";
        }

        $l = mb_orig_strlen($s);

        if ($l <= 250) {
            return chr($l) . $s;
        }

        if ($l <= 0xFFFF) {
            return "\252" . $this->int2bytes(2, $l) . $s;
        }

        if ($l <= 0xFFFFFF) {
            return "\254" . $this->int2bytes(3, $l) . $s;
        }

        return $this->int2bytes(8, $l) . $s;
    }

    /**
     * Parses length-encoded binary
     * @param  string &$s Reference to source string
     * @param  integer &$p
     * @return integer     Result
     */
    public function parseEncodedBinary(&$s, &$p)
    {
        $f = ord(mb_orig_substr($s, $p, 1));
        ++$p;

        if ($f <= 250) {
            return $f;
        }

        if ($s === 251) {
            return null;
        }

        if ($s === 255) {
            return false;
        }

        if ($f === 252) {
            $o = $p;
            $p += 2;

            return $this->bytes2int(mb_orig_substr($s, $o, 2));
        }

        if ($f === 253) {
            $o = $p;
            $p += 3;

            return $this->bytes2int(mb_orig_substr($s, $o, 3));
        }

        $o = $p;
        $p = +8;

        return $this->bytes2int(mb_orig_substr($s, $o, 8));
    }

    /**
     * Parse length-encoded string
     * @param  string &$s Reference to source string
     * @param  integer &$p Reference to pointer
     * @return integer     Result
     */
    public function parseEncodedString(&$s, &$p)
    {
        $l = $this->parseEncodedBinary($s, $p);

        if ($l === null || $l === false) {
            return $l;
        }

        $o = $p;
        $p += $l;

        return mb_orig_substr($s, $o, $l);
    }

    /**
     * Send SQL-query
     * @param  string $q Query
     * @param  callable $callback Optional. Callback called when response received.
     * @callback $callback ( )
     * @return boolean            Success
     */
    public function query($q, $callback = null)
    {
        return $this->command('Q', $q . "\x00", $callback);
    }

    /**
     * Send echo-request
     * @param  callable $callback Optional. Callback called when response received
     * @callback $callback ( )
     * @return boolean Success
     */
    public function ping($callback = null)
    {
        // @todo There is no command for echo-request.
        //return $this->command(, '', $callback);
    }

    /**
     * Sends sync-request
     * @param  callable $cb Optional. Callback called when response received.
     * @callback $cb ( )
     * @return boolean Success
     */
    public function sync($cb = null)
    {
        return $this->command('S', '', $cb);
    }

    /**
     * Send terminate-request to shutdown the connection
     * @param  callable $cb Optional. Callback called when response received.
     * @callback $cb ( )
     * @return boolean Success
     */
    public function terminate($cb = null)
    {
        return $this->command('X', '', $cb);
    }

    /**
     * Sends arbitrary command
     * @param  integer $cmd Command's code. See constants above.
     * @param  string $q Data
     * @param  callable $cb Optional. Callback called when response received.
     * @callback $cb ( )
     * @return boolean Success
     */
    public function command($cmd, $q = '', $cb = null)
    {
        if ($this->state !== self::STATE_AUTH_OK) {
            return false;
        }

        $this->sendPacket($cmd, $q);

        $this->onResponse->push($cb);
        $this->checkFree();

        return true;
    }

    /**
     * Set default database name
     * @param  string $name Database name
     * @return boolean       Success
     */
    public function selectDB($name)
    {
        $this->dbname = $name;

        if ($this->state !== 1) {
            return $this->query('USE `' . $name . '`');
        }

        return true;
    }

    /**
     *
     */
    public function onRead()
    {
        start:
        if ($this->finished) {
            return;
        }
        $l = $this->getInputLength();
        if ($l < 5) {
            return; // Not enough data buffered yet
        }
        $type = $this->look(1);

        list(, $length) = unpack('N', $this->look(4, 1));

        $length -= 4;

        if ($l < $length + 5) {
            // Not enough data buffered yet
            return;
        }

        $this->drain(5);

        $packet = $this->read($length);

        if ($type === 'R') {
            // Authentication request
            list(, $authType) = unpack('N', $packet);
            if ($this->pool->config->protologging->value) {
                Daemon::log('auth type = ' . $authType);
            }
            if ($authType === 0) {
                // Successful
                if ($this->pool->config->protologging->value) {
                    Daemon::log(self::class . ': auth. ok.');
                }

                $this->state = self::STATE_AUTH_OK;

                $this->connected = true;
                $this->onConnected->executeAll($this, true);
                if ($this->connected && !$this->busy) {
                    $this->pool->markConnFree($this, $this->url);
                }
            } // @todo move to constant values
            elseif ($authType === 2) {
                // KerberosV5
                Daemon::log(self::class . ': Unsupported authentication method: KerberosV5.');
                $this->state = self::STATE_AUTH_ERROR; // Auth. error
                $this->finish(); // Unsupported,  finish
            } elseif ($authType === 3) {
                // Cleartext
                $this->sendPacket('p', $this->password); // Password Message
                $this->state = self::STATE_AUTH_PACKET_SENT;
            } elseif ($authType === 4) {
                // Crypt
                $salt = mb_orig_substr($packet, 4, 2);
                $this->sendPacket('p', crypt($this->password, $salt)  . "\x00"); // Password Message
                $this->state = self::STATE_AUTH_PACKET_SENT;
            } elseif ($authType === 5) {
                // MD5
                $salt = mb_orig_substr($packet, 4, 4);
                $this->sendPacket('p', 'md5' . md5(md5($this->password . $this->user) . $salt) . "\x00"); // Password Message
                $this->state = self::STATE_AUTH_PACKET_SENT;
            } elseif ($authType === 6) {
                // SCM
                Daemon::log(self::class . ': Unsupported authentication method: SCM.');
                $this->state = self::STATE_AUTH_ERROR; // Auth. error
                $this->finish(); // Unsupported,  finish
            } elseif ($authType === 9) {
                // GSS
                Daemon::log(self::class . ': Unsupported authentication method: GSS.');
                $this->state = self::STATE_AUTH_ERROR; // Auth. error
                $this->finish(); // Unsupported,  finish
            }
        } elseif ($type === 'T') {
            // Row Description
            list(, $numfields) = unpack('n', mb_orig_substr($packet, 0, 2));
            $p = 2;

            for ($i = 0; $i < $numfields; ++$i) {
                list($name) = $this->decodeNULstrings($packet, 1, $p);
                $field = unpack('NtableOID/nattrNo/NdataType/ndataTypeSize/NtypeMod/nformat',
                    mb_orig_substr($packet, $p, 18));
                $p += 18;
                $field['name'] = $name;
                $this->resultFields[] = $field;
            }
        } elseif ($type === 'D') {
            // Data Row
            list(, $numfields) = unpack('n', mb_orig_substr($packet, 0, 2));
            $p = 2;
            $row = [];

            for ($i = 0; $i < $numfields; ++$i) {
                list(, $length) = unpack('N', mb_orig_substr($packet, $p, 4));
                $p += 4;

                if ($length === 0xffffffff) {
                    // hack
                    $length = -1;
                }

                if ($length === -1) {
                    $value = null;
                } else {
                    $value = mb_orig_substr($packet, $p, $length);
                    $p += $length;
                }

                $row[$this->resultFields[$i]['name']] = $value;
            }

            $this->resultRows[] = $row;
        } elseif ($type === 'G' || $type === 'H') {
            // Copy in response
            // The backend is ready to copy data from the frontend to a table; see Section 45.2.5.
            if ($this->pool->config->protologging->value) {
                Daemon::log(self::class . ': Caught CopyInResponse');
            }
        } elseif ($type === 'C') {
            // Close command
            $type = mb_orig_substr($packet, 0, 1);

            if ($type === 'S' || $type === 'P') {
                list($name) = $this->decodeNULstrings(mb_orig_substr($packet, 1));
            } else {
                $tag = $this->decodeNULstrings($packet);
                $tag = explode(' ', $tag[0]);

                if ($tag[0] === 'INSERT') {
                    $this->insertId = $tag[1];
                    $this->insertNum = (int) $tag[2];
                    $this->affectedRows = (int) $tag[2];
                } elseif ($tag[0] === 'DELETE' || $tag[0] === 'UPDATE' || $tag[0] === 'MOVE'
                    || $tag[0] === 'FETCH' || $tag[0] === 'COPY'
                ) {
                    $this->affectedRows = (int) $tag[1];
                }
            }

            $this->onResultDone();
        } elseif ($type === 'n') {
            // No Data
            $this->onResultDone();
        } elseif ($type === 'E') {
            // Error Response
            $code = ord($packet);
            $message = '';

            foreach ($this->decodeNULstrings(mb_orig_substr($packet, 1), 0xFF) as $p) {
                if ($message !== '') {
                    $message .= ' ';
                    $p = mb_orig_substr($p, 1);
                }

                $message .= $p;
            }

            $this->errno = -1;
            $this->errmsg = $message;

            $this->onError();

            if ($this->pool->config->protologging->value) {
                Daemon::log(self::class . ': Error response caught (0x' . dechex($code) . '): ' . $message);
            }
        } elseif ($type === 'I') {
            // Empty Query Response
            $this->errno = -1;
            $this->errmsg = 'Query was empty';
            $this->onError();
        } elseif ($type === 'S') {
            // Portal Suspended
            if ($this->pool->config->protologging->value) {
                Daemon::log(self::class . ': Caught PortalSuspended');
            }
        } elseif ($type === 'S') {
            // Parameter Status
            $u = $this->decodeNULstrings($packet, 2);

            if (isset($u[0])) {
                $this->parameters[$u[0]] = isset($u[1]) ? $u[1] : null;

                if ($this->pool->config->protologging->value) {
                    Daemon::log(self::class . ': Parameter ' . $u[0] . ' = \'' . $this->parameters[$u[0]] . '\'');
                }
            }
        } elseif ($type === 'K') {
            // Backend Key Data
            list(, $this->backendKey) = unpack('N', $packet);
            $this->backendKey = isset($u[1]) ? $u[1] : null;

            if ($this->pool->config->protologging->value) {
                Daemon::log(self::class . ': BackendKey is ' . $this->backendKey);
            }
        } elseif ($type === 'Z') {
            // Ready For Query
            $this->status = $packet;

            if ($this->pool->config->protologging->value) {
                Daemon::log(self::class . ': Ready For Query. Status: ' . $this->status);
            }
        } else {
            Daemon::log(self::class . ': Caught message with unsupported type - ' . $type);
        }

        goto start;

    }

    /**
     * Decode strings from the NUL-terminated representation
     * @param  string $data Binary data
     * @param  integer $limit Optional. Limit of count. Default is 1.
     * @param  reference &$p Optional. Pointer.
     * @return array            Decoded strings
     */
    public function decodeNULstrings($data, $limit = 1, &$p = 0)
    {
        $r = [];

        for ($i = 0; $i < $limit; ++$i) {
            $pos = mb_orig_strpos($data, "\x00", $p);

            if ($pos === false) {
                break;
            }

            $r[] = mb_orig_substr($data, $p, $pos - $p);

            $p = $pos + 1;
        }

        return $r;
    }

    /**
     * Called when the whole result received
     * @return void
     */
    public function onResultDone()
    {
        $this->instate = 0;
        $this->onResponse->executeOne($this, true);
        $this->resultRows = [];
        $this->resultFields = [];
        $this->checkFree();

        if ($this->pool->config->protologging->value) {
            Daemon::log(__METHOD__);
        }
    }

    /**
     * Called when error occured
     * @return void
     */
    public function onError()
    {
        $this->instate = 0;
        $this->onResponse->executeOne($this, false);
        $this->resultRows = [];
        $this->resultFields = [];

        Daemon::log(__METHOD__ . ' #' . $this->errno . ': ' . $this->errmsg);

        if ($this->state === self::STATE_AUTH_PACKET_SENT) {
            // in case of auth error
            $this->state = self::STATE_AUTH_ERROR;
            $this->finish();
        } else {
            $this->checkFree();
        }
    }
}
