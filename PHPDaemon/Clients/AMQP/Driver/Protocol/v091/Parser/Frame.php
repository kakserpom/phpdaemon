<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Parser;

use PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPProtocolException;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\BodyFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Constants;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\FrameInterface as ProtocolFrameInterface;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\HeartbeatFrame;

/**
 * Produces Frame objects from binary data.
 *
 * Class GeneratedFrameParser
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Parser
 */
class Frame implements FrameInterface
{
    use ScalarParserTrait, HeaderFrameParserTrait, MethodFrameParserTrait;

    // the size of each portion of the header ...
    const HEADER_TYPE_SIZE = 1; // header field "frame type" - unsigned octet
    const HEADER_CHANNEL_SIZE = 2; // header field "channel id" - unsigned short
    const HEADER_PAYLOAD_LENGTH_SIZE = 4; // header field "payload length" - unsigned long

    // the total header size ...
    const HEADER_SIZE = self::HEADER_TYPE_SIZE
    + self::HEADER_CHANNEL_SIZE
    + self::HEADER_PAYLOAD_LENGTH_SIZE;

    // minimum size of a valid frame (header + end with no payload) ...
    const MINIMUM_FRAME_SIZE = self::HEADER_SIZE + 1; // end marker is always 1 byte

    /**
     * The parser used to parse AMQP tables.
     * @var Table
     */
    private $tableParser;

    /**
     * @var integer The number of bytes required in the buffer to produce the
     *              next frame.
     *
     * This value starts as MINIMUM_FRAME_SIZE and is increased to include the
     * frame's payload size when the frame header becomes available.
     */
    private $requiredBytes;

    /**
     * @var string A buffer containing incoming binary data that can not yet be
     *             used to produce a frame.
     */
    private $buffer;

    /**
     * @param Table $tableParser The parser used to parse AMQP tables.
     */
    public function __construct(Table $tableParser)
    {
        $this->tableParser = $tableParser;
        $this->requiredBytes = self::MINIMUM_FRAME_SIZE;
        $this->buffer = '';
    }

    /**
     * Retrieve the next frame from the internal buffer.
     *
     * @param string $buffer Binary data to feed to the parser.
     * @param int &$requiredBytes The minimum number of bytes that must be
     *                               read to produce the next frame.
     *
     * @return ProtocolFrameInterface|null        The frame parsed from the start of the buffer.
     * @throws AMQPProtocolException              The incoming data does not conform to the AMQP specification.
     */
    public function feed($buffer, &$requiredBytes = 0)
    {
        $this->buffer .= $buffer;
        $availableBytes = \strlen($this->buffer);

        // not enough bytes for a frame ...
        if ($availableBytes < $this->requiredBytes) {
            $requiredBytes = $this->requiredBytes;

            return null;

            // we're still looking for the header ...
        }
        if ($this->requiredBytes === self::MINIMUM_FRAME_SIZE) {
            // now that we know the payload size we can add that to the number
            // of required bytes ...
            $this->requiredBytes += \unpack(
                'N',
                mb_orig_substr(
                    $this->buffer,
                    self::HEADER_TYPE_SIZE + self::HEADER_CHANNEL_SIZE,
                    self::HEADER_PAYLOAD_LENGTH_SIZE
                )
            )[1];

            // taking the payload into account we still don't have enough bytes
            // for the frame ...
            if ($availableBytes < $this->requiredBytes) {
                $requiredBytes = $this->requiredBytes;

                return null;
            }
        }

        // we've got enough bytes, check that the last byte is the end marker ...
        if (\ord($this->buffer[$this->requiredBytes - 1]) !== Constants::FRAME_END) {
            throw new AMQPProtocolException(
                sprintf(
                    'Frame end marker (0x%02x) is invalid.',
                    \ord($this->buffer[$this->requiredBytes - 1])
                )
            );
        }

        // read the (t)ype and (c)hannel then discard the header ...
        $fields = \unpack('Ct/nc', $this->buffer);
        $this->buffer = mb_orig_substr($this->buffer, self::HEADER_SIZE);

        $type = $fields['t'];

        // read the frame ...
        if ($type === Constants::FRAME_METHOD) {
            $frame = $this->parseMethodFrame();
        } elseif ($type === Constants::FRAME_HEADER) {
            $frame = $this->parseHeaderFrame();
        } elseif ($type === Constants::FRAME_BODY) {
            $length = $this->requiredBytes - self::MINIMUM_FRAME_SIZE;
            $frame = new BodyFrame();
            $frame->content = mb_orig_substr($this->buffer, 0, $length);
            $this->buffer = mb_orig_substr($this->buffer, $length);
        } elseif ($type === Constants::FRAME_HEARTBEAT) {
            if (self::MINIMUM_FRAME_SIZE !== $this->requiredBytes) {
                throw new AMQPProtocolException(
                    sprintf(
                        'Heartbeat frame payload size (%d) is invalid, must be zero.',
                        $this->requiredBytes - self::MINIMUM_FRAME_SIZE
                    )
                );
            }
            $frame = new HeartbeatFrame();
        } else {
            throw new AMQPProtocolException(
                sprintf(
                    'Frame type (0x%02x) is invalid.',
                    $type
                )
            );
        }

        // discard the end marker ...
        $this->buffer = mb_orig_substr($this->buffer, 1);

        $consumedBytes = $availableBytes - \strlen($this->buffer);

        // the frame lied about its payload size ...
        if ($consumedBytes !== $this->requiredBytes) {
            throw new AMQPProtocolException(
                sprintf(
                    'Mismatch between frame size (%s) and consumed bytes (%s).',
                    $this->requiredBytes,
                    $consumedBytes
                )
            );
        }

        $this->requiredBytes = $requiredBytes = self::MINIMUM_FRAME_SIZE;
        $frame->frameChannelId = $fields['c'];

        return $frame;
    }
}
