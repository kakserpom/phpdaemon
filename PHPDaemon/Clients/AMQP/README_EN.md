[TOC]

# PHPDaemon AMQP Implementation


The AMQP protocol represents RPC calls between the broker and the client in a full duplex mode. The packets sent to connection are called frames.
To start handshakes the client anew sends to the broker \PHPDaemon\Clients\AMQP\AMQPConnection:: PROTOCOL_HEADER header which contain the protocol version.
If the broker supports such version, then in reply you receive \PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Connection\ConnectionStartFrame.
After receiving this frame exchange of packets for installation of steady connection begins.

Protocol reference https://www.rabbitmq.com/amqp-0-9-1-reference.html

The driver contain these classes.

1. ```\PHPDaemon\Clients\AMQP\Pool``` - the connection pool.
2. ```\PHPDaemon\Clients\AMQP\Connection``` - the \PHPDaemon\Network\ClientConnection extends
3. ```\PHPDaemon\Clients\AMQP\Channel``` - the class implements the channel for interaction with the broker
4. ```\PHPDaemon\Clients\AMQP\Message``` - the message class.

## Configuration


```
Pool:\PHPDaemon\Clients\AMQP\Pool {
    server 'tcp://127.0.0.1';
    port 5672;
    username 'guest';
    password 'guest';
    vhost '/';
}

```

***

## Work with driver

### Create a connection.
Basically process of creation of connection looks so:
```
#!php
$amqpClient = Pool::getInstance();
$amqpClient->getConnection(function ($connection) {
    /** @var Connection $connection */
    if(!$connection->isConnected()) {
        return;
    }

    // do stuff
});


```
At first we get an instance of a class *\PHPDaemon\Clients\AMQP\Pool* , then we call a *getConnection* method
The callback function will be caused directly at the time of creation (the receiving existing) of connection.
If this new connection, then at the time of its creation begins process of handshake according to the AMQP protocol and opening of the channel with an id = 1.
In case of successful opening channel the event *Connection::ON_HANDSHAKE_EVENT* will be emitted.
In a callback function on an event *Connection::ON_HANDSHAKE_EVENT* will be passed a instance of *\PHPDaemon\Clients\AMQP\Channel*
***

### Work with channel

#### Channel::close

This method close the channel


#### Channel::declareQueue
+ ```\PHPDaemon\Clients\AMQP\Channel::declareQueue```
Arguments:
    - *$name* - the queue name
    - *$options* - the options

         - passive - (bool) If set, the server will response with frame ```\PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Queue\QueueDeclareOkFrame``` if queue exists. By default **FALSE**
         - durable - (bool) If set, queue remain active when a server restarts. By default **FALSE**
         - exclusive - (bool) If set, the queue only be accessed by the current connection, and are deleted when that connection closes. By default **FALSE**
         - autoDelete - (bool)  If set, the queue is deleted when all consumers have finished using it. By default **FALSE**
         - noWait - (bool) If set, the server will not respond to the method. By default **FALSE**
         - arguments - (array) The broker specified arguments.

    - *$callback* - optional, the callback function. Will be fired when confirmation receive. No arguments. *If noWait set to TRUE, then callback will not be fired*


#### Channel::deleteQueue
+ ```\PHPDaemon\Clients\AMQP\Channel::deleteQueue```
Arguments:
    - *$name* - the queue name
    - *$options* - the options

        - ifUnused - (bool) If set, the server will only delete the queue if it has no consumers. By default **FALSE**
        - ifEmpty - (bool) If set, the server will only delete the queue if it has no messages. By default **FALSE**
        - noWait - (bool) If set, the server will not respond to the method. By default **FALSE**

    - *$callback* - optional, the callback function. Will be fired when confirmation receive. No arguments. *If noWait set to TRUE, then callback will not be fired*

#### Channel::purgeQueue
+ ```\PHPDaemon\Clients\AMQP\Channel::purgeQueue```
Arguments:
    - *$name* - the queue name
    - *$options* - the options

        - noWait - (bool) If set, the server will not respond to the method. By default **FALSE**

    - *$callback* - optional, the callback function. Will be fired when confirmation receive. No arguments. *If noWait set to TRUE, then callback will not be fired*

#### Channel::bindQueue
+ ```\PHPDaemon\Clients\AMQP\Channel::bindQueue```
Arguments:
    - *$name* - the queue name
    - *$exchangeName* - the exchange name
    - *$routingKey* - the routing key
    - *$options* - the options

            - noWait - (bool) If set, the server will not respond to the method. By default **FALSE**

    - *$callback* - optional, the callback function. Will be fired when confirmation receive. No arguments. *If noWait set to TRUE, then callback will not be fired*

#### Channel::unbindQueue
+ ```\PHPDaemon\Clients\AMQP\Channel::unbindQueue```
Arguments:
    - *$name* - the queue name
    - *$exchangeName* - the exchange name
    - *$routingKey* - the routing key
    - *$callback* - optional, the callback function. Will be fired when confirmation receive. No arguments.

#### Channel::declareExchange
+ ```\PHPDaemon\Clients\AMQP\Channel::declareExchange```
Arguments:
    - *$name* - the exchange name
    - *$options* - the options

         - type - (string) Exchange type direct|fanout|topic|headers . By default **direct**
         - passive - (bool) f set, the server will reply with ```\PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Exchange\ExchangeDeclareOkFrame``` if the exchange already exists with the same name. By default **FALSE**
         - durable - (bool) If set when creating a new exchange, the exchange will be marked as durable. By default **FALSE**
         - internal - (bool) If set, the exchange may not be used directly by publishers, but only when bound to other exchanges. By default **FALSE**
         - autoDelete - (bool)  If set, the exchange is deleted when all queues have finished using it. By default **FALSE**
         - noWait - (bool) If set, the server will not respond to the method. By default **FALSE**
         - arguments - (array) The broker specified arguments.

    - *$callback* - optional, the callback function. Will be fired when confirmation receive. No arguments. *If noWait set to TRUE, then callback will not be fired*

#### Channel::deleteExchange
+ ```\PHPDaemon\Clients\AMQP\Channel::deleteExchange```
Arguments:
    - *$name* - the exchange name
    - *$options* - the options

            - ifUnused - (bool) Если установлено в TRUE и есть подписчики на обменник, то удаления не произойдет, а канал закроется. By default **FALSE**
            - noWait - (bool) If set, the server will not respond to the method. By default **FALSE**

    - *$callback* - optional, the callback function. Will be fired when confirmation receive. No arguments.

#### Channel::bindExchange
+ ```\PHPDaemon\Clients\AMQP\Channel::deleteExchange```
Arguments:
    - *$name* - the source exchange name
    - *$exchangeName* - the target exchange name
    - *$routingKey* - the routing key
    - *$options* - the options

            - noWait - (bool) If set, the server will not respond to the method. By default **FALSE**

    - *$callback* - optional, the callback function. Will be fired when confirmation receive. No arguments.


#### Channel::unbindExchange
+ ```\PHPDaemon\Clients\AMQP\Channel::unbindExchange```
Arguments:
    - *$name* - the source exchange name
    - *$exchangeName* - the target exchange name
    - *$routingKey* - the routing key
    - *$options* - the options

            - noWait - (bool) If set, the server will not respond to the method. By default **FALSE**

    - *$callback* - optional, the callback function. Will be fired when confirmation receive. No arguments.


#### Channel::publish
+ ```\PHPDaemon\Clients\AMQP\Channel::unbindExchange```
Arguments:
    - *$content* - the message content
    - *$exchangeName* - the exchange name to publish
    - *$routingKey* - the routing key
    - *$options* - the options

            - contentLength - (int) message length
            - contentType - (string) the mime-type of message. By default text/plain
            - contentEncoding - (string)
            - headers - (array) additional headers
            - messageId - (string)
            - deliveryMode - (int)
            - correlationId - (string) the corrlation identifier (RPC)
            - replyTo - (string) the queue name to reply (RPC)
            - expiration - (string) expiration date time
            - timestamp - (int) send date time
            - type - (string) the message type
            - userId - (string) user identifier
            - appId - (string) application identifier


#### Channel::sendToQueue
+ ```\PHPDaemon\Clients\AMQP\Channel::unbindExchange```
Arguments:
    - *$content* - the message content
    - *$name* - the queue name to publish
    - *$options* - the options

            - contentLength - (int) message length
            - contentType - (string) the mime-type of message. By default text/plain
            - contentEncoding - (string)
            - headers - (array) additional headers
            - messageId - (string)
            - deliveryMode - (int)
            - correlationId - (string) the corrlation identifier (RPC)
            - replyTo - (string) the queue name to reply (RPC)
            - expiration - (string) expiration date time
            - timestamp - (int) send date time
            - type - (string) the message type
            - userId - (string) user identifier
            - appId - (string) application identifier


#### Channel::consume
+ ```\PHPDaemon\Clients\AMQP\Channel::consume```
Arguments:
    - *$queueName* - the queue name to consume
    - *$options* - the options

            - consumerTag - (string) unique consumer tag
            - noLocal - (bool) If set the server will not send messages to the connection that published them. By default **FALSE**
            - noAck - (bool) If this field is set to TRUE the server does not expect acknowledgements for messages. By default **FALSE**
            - exclusive - (bool) If set only this consumer can access the queue. By default **FALSE**
            - noWait - (bool) If set, the server will not respond to the method. By default **FALSE**
            - arguments - (array) The broker specified arguments.

    - *$callback* - required, the callback function. Will be fired when new message receive. The first argument is  *\PHPDaemon\Clients\AMQP\Message*

#### Channel::cancel
+ ```\PHPDaemon\Clients\AMQP\Channel::cancel```
Arguments:
    - *$consumerTag* - unique consumer tag
    - *$options* - the options
            - noWait - (bool) If set, the server will not respond to the method. By default **FALSE**


#### Channel::get
+ ```\PHPDaemon\Clients\AMQP\Channel::get```
Arguments:
    - *$queueName* - the queue name
    - *$options* - the options

            - noAck - (bool) If this field is set the server does not expect acknowledgements for messages. By default **FALSE**

    - *$callback* - required, the callback function. The first argument is *\PHPDaemon\Clients\AMQP\Message* if has message in queue, else  **FALSE**


#### Channel::ack
+ ```\PHPDaemon\Clients\AMQP\Channel::ack```
Arguments:
    - *$deliveryTag* - The server-assigned and channel-specific delivery tag.
    - *$options* - the options

            - multiple - (int) If set to 1, the delivery tag is treated as "up to and including", so that multiple messages can be acknowledged with a single method. By default **0**


#### Channel::nack
+ ```\PHPDaemon\Clients\AMQP\Channel::nack```
Arguments:
    - *$deliveryTag* - The server-assigned and channel-specific delivery tag.
    - *$options* - the options

            - multiple - (int) If set to 1, the delivery tag is treated as "up to and including", so that multiple messages can be rejected with a single method. By default **0**
            - requeue - (bool) If set, the server will attempt to requeue the message. By default **TRUE**

#### Channel::reject
+ ```\PHPDaemon\Clients\AMQP\Channel::reject```
Arguments:
    - *$deliveryTag* - The server-assigned and channel-specific delivery tag.
    - *$options* - the options

            - requeue - (bool) If set, the server will attempt to requeue the message. If requeue is false or the requeue attempt fails the messages are discarded or dead-lettered. By default **TRUE**


#### Channel::recover
+ ```\PHPDaemon\Clients\AMQP\Channel::recover```
Arguments:
    - *$requeue* - (bool) If set, the server will attempt to requeue the message, potentially then delivering it to an alternative subscriber. By default **FALSE**