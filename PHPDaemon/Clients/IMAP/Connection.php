<?php

namespace PHPDaemon\Clients\IMAP;

use PHPDaemon\Network\ClientConnection;
use PHPDaemon\Core\Daemon;

class Connection extends ClientConnection
{
    const STATE_CONNECTING  = 0;
    const STATE_CONNECTED   = 1;
    const STATE_CREDS_SENT  = 2;
    const STATE_AUTHORIZED  = 3;

    const FLAG_PASSED       = '\Passed';
    const FLAG_ANSWERED     = '\Answered';
    const FLAG_SEEN         = '\Seen';
    const FLAG_UNSEEN       = '\Unseen';
    const FLAG_DELETED      = '\Deleted';
    const FLAG_DRAFT        = '\Draft';
    const FLAG_FLAGGED      = '\Flagged';

    /**
     * IMAP flags to search criteria
     * @var array
     */
    protected $searchFlags = [
        '\Recent'   => 'RECENT',
        '\Answered' => 'ANSWERED',
        '\Seen'     => 'SEEN',
        '\Unseen'   => 'UNSEEN',
        '\Deleted'  => 'DELETED',
        '\Draft'    => 'DRAFT',
        '\Flagged'  => 'FLAGGED',
    ];

    const TAG_LOGIN         = 'a01';
    const TAG_LIST          = 'a02';
    const TAG_SELECT        = 'a03';
    const TAG_FETCH         = 'a04';
    const TAG_SEARCH        = 'a05';
    const TAG_COUNT         = 'a06';
    const TAG_SIZE          = 'a07';
    const TAG_GETRAWMESSAGE = 'a08';
    const TAG_GETRAWHEADER  = 'a09';
    const TAG_GETRAWCONTENT = 'a10';
    const TAG_GETUID        = 'a11';
    const TAG_CREATEFOLDER  = 'a12';
    const TAG_DELETEFOLDER  = 'a13';
    const TAG_RENAMEFOLDER  = 'a14';
    const TAG_STORE         = 'a15';
    const TAG_DELETEMESSAGE = 'a16';
    const TAG_EXPUNGE       = 'a17';
    const TAG_LOGOUT        = 'a18';
    const TAG_STARTTLS      = 'a19';

    protected $EOL = "\r\n";
    
    protected $state;
    protected $lines = [];
    protected $blob = '';
    protected $blobOctetsLeft = 0;

    public function onReady()
    {
        $this->state = self::STATE_CONNECTING;
    }

    /**
     * escape a single literal
     * @param  $string
     * @return string escaped list for imap
     */
    protected function escapeString($string)
    {
        return '"' . strtr($string, ['\\' => '\\\\', '"' => '\\"']) . '"';
    }

    /**
     * escape a list with literals or lists
     *
     * @param  array $list list with literals or lists as PHP array
     * @return string escaped list for imap
     */
    protected function escapeList($list)
    {
        $result = [];
        foreach ($list as $v) {
            if (!is_array($v)) {
                $result[] = $v;
                continue;
            }
            $result[] = $this->escapeList($v);
        }
        return '(' . implode(' ', $result) . ')';
    }

    /**
     * split a given line in tokens. a token is literal of any form or a list
     *
     * @param  string $line line to decode
     * @return array tokens, literals are returned as string, lists as array
     */
    protected function decodeLine($line)
    {
        $tokens = [];
        $stack = [];
        /*
            We start to decode the response here. The understood tokens are:
                literal
                "literal" or also "lit\\er\"al"
                (literals*)
            All tokens are returned in an array. Literals in braces (the last understood
            token in the list) are returned as an array of tokens. I.e. the following response:
                "foo" baz bar ("f\\\"oo" bar)
            would be returned as:
                array('foo', 'baz', 'bar', array('f\\\"oo', 'bar'));
        */
        //  replace any trailing <NL> including spaces with a single space
        $line = rtrim($line) . ' ';
        while (($pos = strpos($line, ' ')) !== false) {
            $token = substr($line, 0, $pos);
            if (!strlen($token)) {
                continue;
            }
            while ($token[0] === '(') {
                array_push($stack, $tokens);
                $tokens = [];
                $token = substr($token, 1);
            }
            if ($token[0] === '"') {
                if (preg_match('%^\(*"((.|\\\\|\\")*?)" *%', $line, $matches)) {
                    $tokens[] = $matches[1];
                    $line = substr($line, strlen($matches[0]));
                    continue;
                }
            }
            if ($stack && $token[strlen($token) - 1] === ')') {
                // closing braces are not separated by spaces, so we need to count them
                $braces = strlen($token);
                $token = rtrim($token, ')');
                // only count braces if more than one
                $braces -= strlen($token) + 1;
                // only add if token had more than just closing braces
                if (rtrim($token) != '') {
                    $tokens[] = rtrim($token);
                }
                $token = $tokens;
                $tokens = array_pop($stack);
                // special handline if more than one closing brace
                while ($braces-- > 0) {
                    $tokens[] = $token;
                    $token = $tokens;
                    $tokens = array_pop($stack);
                }
            }
            $tokens[] = $token;
            $line = substr($line, $pos + 1);
        }
        // maybe the server forgot to send some closing braces
        while ($stack) {
            $child = $tokens;
            $tokens = array_pop($stack);
            $tokens[] = $child;
        }
        return $tokens;
    }

    /**
      * @param array $items
      * @param string $from
      * @param string $to
      * @param bool $uid
      * @param string $tag
     */
    protected function fetch($items, $from, $to = null, $uid = false, $tag = self::TAG_FETCH)
    {
        if (is_array($from)) {
            $set = implode(',', $from);
        } elseif ($to === null) {
            $set = (int) $from;
        } elseif ($to === INF) {
            $set = (int) $from . ':*';
        } else {
            $set = (int) $from . ':' . (int) $to;
        }
        $this->writeln($tag .($uid ? ' UID' : '')." FETCH $set ". $this->escapeList((array)$items) );
    }

    /**
     * @param array $flags
     * @param string $from
     * @param string $to
     * @param string $mode (+/-)
     * @param bool $silent
     * @param string $tag
    */
    protected function store(array $flags, $from, $to = null, $mode = null, $silent = true, $tag = self::TAG_STORE)
    {
        $item = 'FLAGS';
        if ($mode == '+' || $mode == '-') {
            $item = $mode . $item;
        }
        if ($silent) {
            $item .= '.SILENT';
        }
        $flags = $this->escapeList($flags);
        $set = (int)$from;
        if ($to !== null) {
            $set .= ':' . ($to == INF ? '*' : (int)$to);
        }
        $this->writeln($tag . ' UID STORE ' . $set . ' ' . $item . ' ' . $flags );
    }

    /**
    * @param string $reference
    * @param string $mailbox
    */
    public function listFolders($cb, $reference = '', $mailbox = '*')
    {
        $this->onResponse->push($cb);
        $this->writeln(self::TAG_LIST .' LIST ' . $this->escapeString($reference)
            . ' ' .  $this->escapeString($mailbox) );
    }

    /**
    * @param string $box
    */
    public function selectBox($cb, $box = 'INBOX')
    {
        $this->onResponse->push($cb);
        $this->writeln(self::TAG_SELECT .' SELECT ' . $this->escapeString($box));
    }

    /**
    * @param string $tag
    */
    protected function expunge($tag = self::TAG_EXPUNGE)
    {
        $this->writeln($tag .' EXPUNGE');
    }

    /**
    * @param string $haystack
    * @param string $needle
    */
    public function auth($cb, $login, $password)
    {
        $this->onResponse->push($cb);
        $this->writeln(self::TAG_LOGIN . " LOGIN $login $password");
    }

    /**
     * @param array $params
     * @param string $tag
     */
    protected function searchMessages($params, $tag = self::TAG_SEARCH)
    {
        $this->writeln("$tag UID SEARCH ".implode(' ', $params));
    }

    /**
     * @param string $haystack
     * @param string $needle
     */
    protected function startsWith($haystack, $needle)
    {
        // search backwards starting from haystack length characters from the end
        return $needle === '' || strrpos($haystack, $needle, -strlen($haystack)) !== false;
    }

    /**
     * @param array $lines
     */
    protected function decodeList($lines)
    {
        $list = [];
        foreach (array_map([$this, 'decodeLine'], $lines) as $tokens) {
            $folderEntry = [];
            if (!isset($tokens[0]) || $tokens[0] !== 'LIST') {
                continue;
            }
            if (isset($tokens[3])) {
                $folderEntry['name'] = $tokens[3];
            } else {
                continue;
            }
            if (isset($tokens[1])) {
                $folderEntry['flags'] = $tokens[1];
            } else {
                continue;
            }
            $list[] = $folderEntry;
        }
        return $list;
    }

    /**
     * @param array $lines
     */
    protected function decodeCount($lines)
    {
        foreach (array_map([$this, 'decodeLine'], $lines) as $tokens) {
            if (!isset($tokens[0]) || $tokens[0] !== 'SEARCH') {
                continue;
            }
            return count($tokens) - 1;
        }
        return 0;
    }

    /**
     * @param array $lines
     */
    protected function decodeGetUniqueId($lines)
    {
        $uids = [];
        foreach (array_map([$this, 'decodeLine'], $lines) as $tokens) {
            if (!isset($tokens[1]) || $tokens[1] !== 'FETCH') {
                continue;
            }
            if (!isset($tokens[2][0]) || $tokens[2][0] !== 'UID') {
                continue;
            }
            if (!isset($tokens[0]) || !isset($tokens[2][1])) {
                continue;
            }
            $uids[$tokens[0]] = $tokens[2][1];
        }
        return $uids;
    }

    /*
     * @param array $lines
     */
    protected function decodeSize($lines)
    {
        $sizes = [];
        foreach (array_map([$this, 'decodeLine'], $lines) as $tokens) {
            if (!isset($tokens[1]) || $tokens[1] !== 'FETCH') {
                continue;
            }
            if (!isset($tokens[2][0]) || $tokens[2][0] !== 'UID') {
                continue;
            }
            if (!isset($tokens[2][2]) || $tokens[2][2] !== 'RFC822.SIZE') {
                continue;
            }
            if (!isset($tokens[2][1]) || !isset($tokens[2][3])) {
                continue;
            }
            $sizes[$tokens[2][1]] = $tokens[2][3];
        }
        return $sizes;
    }

    /**
     *
     * @param string    $tag response tag
     * @param string    $type OK, NO, BAD
     * @param string    $line last response line
     * @param array     $lines full response
     * @param string    $blob
     */
    protected function onCommand($tag, $type, $line, $lines, $blob)
    {
        $ok = $type === 'OK';
        $no = $type === 'NO';
        if ($type === 'BAD') {
            $this->log("Server said: " . $line);
        }
        $raw = ['lines' => $lines, 'blob' => $blob];
        switch ($tag) {
            case self::TAG_LOGIN:
                if ($ok) {
                    $this->state = self::STATE_AUTHORIZED;
                    $this->onResponse->executeOne($this, $line);
                } elseif ($no) {
                    $this->log("Failed to login: " . $line);
                    $this->finish();
                }
                break;

            case self::TAG_LIST:
                $this->onResponse->executeOne($this, $ok, $this->decodeList($lines));
                break;

            case self::TAG_GETUID:
                $this->onResponse->executeOne($this, $ok, $this->decodeGetUniqueId($lines));
                break;

            case self::TAG_COUNT:
                $this->onResponse->executeOne($this, $ok, $this->decodeCount($lines));
                break;

            case self::TAG_SIZE:
                $this->onResponse->executeOne($this, $ok, $this->decodeSize($lines));
                break;

            case self::TAG_DELETEMESSAGE:
                $this->expunge();
                break;

            case self::TAG_EXPUNGE:
                $this->onResponse->executeOne($this, count($lines) - 1, $raw);
                break;

            case self::TAG_GETRAWMESSAGE:
                $this->onResponse->executeOne($this, !empty($blob), $raw);
                break;

            case self::TAG_GETRAWHEADER:
                $this->onResponse->executeOne($this, !empty($blob), $raw);
                break;

            case self::TAG_GETRAWCONTENT:
                $this->onResponse->executeOne($this, !empty($blob), $raw);
                break;

            default:
                $this->onResponse->executeOne($this, $ok, $raw);
                break;
        }
    }

    public function onRead()
    {
        while (($rawLine = $this->readLine(\EventBuffer::EOL_CRLF_STRICT)) !== null) {
            if ($this->blobOctetsLeft > 0) {
                $this->blob .= $rawLine . "\r\n";
                $this->blobOctetsLeft -= strlen($rawLine) + 2;
                continue;
            }
            if (preg_match('~\{([0-9]+)\}$~', $rawLine, $matches)) {
                $this->blob = '';
                $this->blobOctetsLeft = $matches[1];
            }
            @list($tag, $line) = @explode(' ', $rawLine, 2);
            @list($type, $restLine) = @explode(' ', $line, 2);

            if ($this->state == self::STATE_CONNECTING) {
                if ($this->startsWith($rawLine, '* OK')) {
                    $this->state = self::STATE_CONNECTED;
                    $this->connected = true;
                } else {
                    $this->log("IMAP hello failed");
                    $this->finish();
                    return;
                }
                if ($this->onConnected) {
                    $this->onConnected->executeAll($this->connected ? $this : false);
                    $this->onConnected = null;
                }
                return;
            } elseif ($this->state != self::STATE_CONNECTING) {
                if ($tag === '*') {
                    $this->lines[] = $line;
                    continue;
                }
                if (!in_array($type, ['OK', 'BAD', 'NO'])) {
                    $this->lines[] = $rawLine;
                    continue;
                }
                $this->lines[] = $line;
                $this->onCommand($tag, $type, $line, $this->lines, $this->blob);
                $this->lines = [];
            }
        }
    }

    /**
     * Count messages all messages in current box
     * @param null $flags
     */
    public function countMessages($cb, $flags = null)
    {
        $this->onResponse->push($cb);
        if ($flags === null) {
            $this->searchMessages(['ALL'], self::TAG_COUNT);
            return;
        }
        $params = [];
        foreach ((array) $flags as $flag) {
            if (isset($this->searchFlags[$flag])) {
                $params[] = $this->searchFlags[$flag];
            } else {
                $params[] = 'KEYWORD';
                $params[] = $this->escapeString($flag);
            }
        }
        $this->searchMessages($params, self::TAG_COUNT);
    }

    /**
     * get a list of messages with number and size
     * @param int $uid number of message
     */
    public function getSize($cb, $uid = null)
    {
        $this->onResponse->push($cb);
        if ($uid) {
            $this->fetch('RFC822.SIZE', $uid, null, true, self::TAG_SIZE);
        } else {
            $this->fetch('RFC822.SIZE', 1, INF, true, self::TAG_SIZE);
        }
    }

    /**
     * Fetch a message
     * @param int $uid unique number of message
     */
    public function getRawMessage($cb, $uid, $byUid = true)
    {
        $this->onResponse->push($cb);
        $this->fetch(['FLAGS', 'BODY[]'], $uid, null, $byUid, self::TAG_GETRAWMESSAGE);
    }

    /*
     * Get raw header of message or part
     * @param  int  $uid unique number of message
     */
    public function getRawHeader($cb, $uid, $byUid = true)
    {
        $this->onResponse->push($cb);
        $this->fetch(['FLAGS', 'RFC822.HEADER'], $uid, null, $byUid, self::TAG_GETRAWHEADER);
    }

    /*
     * Get raw content of message or part
     *
     * @param  int $uid   number of message
     */
    public function getRawContent($cb, $uid, $byUid = true)
    {
        $this->onResponse->push($cb);
        $this->fetch(['FLAGS', 'RFC822.TEXT'], $uid, null, $byUid, self::TAG_GETRAWCONTENT);
    }

    /**
     * get unique id for one or all messages
     *
     * @param int|null $id message number
     */
    public function getUniqueId($cb, $id = null)
    {
        $this->onResponse->push($cb);
        if ($id) {
            $this->fetch('UID', $id, null, false, self::TAG_GETUID);
        } else {
            $this->fetch('UID', 1, INF, false, self::TAG_GETUID);
        }
    }

    /**
     * create a new folder (and parent folders if needed)
     *
     * @param string $folder folder name
     * @return bool success
     */
    public function createFolder($cb, $folder, $parentFolder = null)
    {
        $this->onResponse->push($cb);
        if ($parentFolder) {
            $folder =  $parentFolder . '/' . $folder ;
        }
        $this->writeln(self::TAG_CREATEFOLDER . " CREATE ".$this->escapeString($folder));
    }

    /**
     * remove a folder
     *
     * @param  string $name name or instance of folder
     */
    public function removeFolder($cb, $folder)
    {
        $this->onResponse->push($cb);
        $this->writeln(self::TAG_DELETEFOLDER . " DELETE ".$this->escapeString($folder));
    }

    /**
     * rename and/or move folder
     * @param  string $oldName name or instance of folder
     * @param  string $newName new global name of folder
     */
    public function renameFolder($cb, $oldName, $newName)
    {
        $this->onResponse->push($cb);
        $this->writeln(self::TAG_RENAMEFOLDER . " RENAME "
            .$this->escapeString($oldName)." ".$this->escapeString($newName));
    }

    /**
     * Remove a message from server.
     * @param  int $uid unique number of message
     */
    public function removeMessage($cb, $uid)
    {
        $this->onResponse->push($cb);
        $this->store([self::FLAG_DELETED], $uid, null, '+', true, self::TAG_DELETEMESSAGE);
    }

    /**
     * logout of imap server
     */
    public function logout($cb = null)
    {
        if ($cb) {
            $this->onResponse->push($cb);
        }
        $this->writeln(self::TAG_LOGOUT . " LOGOUT");
    }

    public function onFinish()
    {
        $this->onResponse->executeAll(false);
        parent::onFinish();
    }
}
