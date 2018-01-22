<?php

namespace PHPDaemon\Clients\AMQP\Driver;

/**
 * Options related to AMQP connections.
 *
 * Class ConnectionOptions
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver
 */
class ConnectionOptions
{
    /**
     * @var string The hostname or IP address of the AMQP broker.
     */
    private $host;

    /**
     * @var int The TCP port of the AMQP broker.
     */
    private $port;

    /**
     * @var string The username to use to authentication.
     */
    private $username;

    /**
     * @var string The password to use for authentication.
     */
    private $password;

    /**
     * @var string The virtual-host to use.
     */
    private $vhost;

    /**
     * @var string The product name to report to the broker.
     */
    private $productName;

    /**
     * @var string The product version to report to the broker.
     */
    private $productVersion;

    /**
     * @var float|null The timeout in seconds (null = PHP default).
     */
    private $connectionTimeout;

    /**
     * @var float|null The heartbeat interval in seconds (null = use broker suggestion).
     */
    private $heartbeatInterval;

    /**
     * @param string $host The hostname or IP address of the AMQP broker.
     * @param int $port The TCP port of the AMQP broker.
     * @param string $username The username to use to authentication.
     * @param string $password The password to use for authentication.
     * @param string $vhost The virtual-host to use.
     */
    public function __construct(
        $host,
        $port,
        $username,
        $password,
        $vhost
    )
    {
        assert(
            $port >= 1 && $port <= 65535,
            'Port must be between 1 and 65535, inclusive.'
        );

        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->vhost = $vhost;
    }

    /**
     * Get the hostname or IP address of the AMQP broker.
     * @return string
     */
    public function getHost()
    {
        return $this->host;
    }

    /**
     * Set the hostname or IP address of the AMQP broker.
     * @param $host
     * @return ConnectionOptions
     */
    public function setHost($host)
    {
        $this->host = $host;
        return $this;
    }

    /**
     * Get the TCP port of the AMQP broker.
     * @return int
     */
    public function getPort()
    {
        return $this->port;
    }

    /**
     * Set the TCP port of the AMQP broker.
     * @param $port
     * @return ConnectionOptions
     */
    public function setPort($port)
    {
        $this->port = $port;
        return $this;
    }

    /**
     * Get the username to use for authentication.
     * @return string
     */
    public function getUsername()
    {
        return $this->username;
    }

    /**
     * Set the username to use for authentication.
     * @param $username
     * @return ConnectionOptions
     */
    public function setUsername($username)
    {
        $this->username = $username;
        return $this;
    }

    /**
     * Get the password to use for authentication.
     * @return string
     */
    public function getPassword()
    {
        return $this->password;
    }

    /**
     * Set the password to use for authentication.
     * @param $password
     * @return ConnectionOptions
     */
    public function setPassword($password)
    {
        $this->password = $password;
        return $this;
    }

    /**
     * Get the AMQP virtual-host to use.
     * @return string
     */
    public function getVhost()
    {
        return $this->vhost;
    }

    /**
     * Set the virtual-host to use.
     * @param $vhost
     * @return ConnectionOptions
     */
    public function setVhost($vhost)
    {
        $this->vhost = $vhost;
        return $this;
    }

    /**
     * Get the product name to report to the broker.
     * @return string
     */
    public function getProductName()
    {
        return $this->productName;
    }

    /**
     * Set the product name to report to the broker.
     * @param string $name
     * @return ConnectionOptions
     */
    public function setProductName($name)
    {
        $this->productName = $name;
        return $this;
    }

    /**
     * Get the product version to report to the broker.
     * @return string
     */
    public function getProductVersion()
    {
        return $this->productVersion;
    }

    /**
     * Set the product version to report to the broker.
     *
     * @param string $version The product version.
     * @return ConnectionOptions
     */
    public function setProductVersion($version)
    {
        $this->productVersion = $version;
        return $this;
    }

    /**
     * Get the maximum time to allow for the connection to be established.
     *
     * @return float|null The timeout in seconds (null = PHP default).
     */
    public function getConnectionTimeout()
    {
        return $this->connectionTimeout;
    }

    /**
     * Set the maximum time to allow for the connection to be established.
     *
     * @param float|null $timeout The timeout in seconds (null = PHP default).
     * @return ConnectionOptions
     */
    public function setConnectionTimeout($timeout = null)
    {
        $this->connectionTimeout = $timeout;
        return $this;
    }

    /**
     * Get how often the broker and client must send heartbeat frames to keep
     * the connection alive.
     *
     * @return float|null The heartbeat interval in seconds (null = use broker suggestion).
     */
    public function getHeartbeatInterval()
    {
        return $this->heartbeatInterval;
    }

    /**
     * Set how often the broker and client must send heartbeat frames to keep
     * the connection alive.
     *
     * @param float|null $interval The heartbeat interval in seconds (null = use broker suggestion).
     * @return ConnectionOptions
     */
    public function setHeartbeatInterval($interval = null)
    {
        $this->heartbeatInterval = $interval;
        return $this;
    }
}
