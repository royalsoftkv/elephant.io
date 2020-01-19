<?php
/**
 * This file is part of the Elephant.io package
 *
 * For the full copyright and license information, please view the LICENSE file
 * that was distributed with this source code.
 *
 * @copyright Wisembly
 * @license   http://www.opensource.org/licenses/MIT-License MIT License
 */

namespace ElephantIO;

use Psr\Log\NullLogger;
use Psr\Log\LoggerInterface;

use ElephantIO\Exception\SocketException;

/**
 * Represents the IO Client which will send and receive the requests to the
 * websocket server. It basically suggercoat the Engine used with loggers.
 *
 * @author Baptiste Clavié <baptiste@wisembly.com>
 */
class Client
{
    /** @var EngineInterface */
    private $engine;

    /** @var LoggerInterface */
    private $logger;

    private $isConnected = false;

    private $callbacks = [];
    private $eventListeners = [];

    public function __construct(EngineInterface $engine, LoggerInterface $logger = null)
    {
        $this->engine = $engine;
        $this->logger = $logger ?: new NullLogger;
    }

    public function __destruct()
    {
        if (!$this->isConnected) {
            return;
        }

        $this->close();
    }

    /**
     * Connects to the websocket
     *
     * @return $this
     */
    public function initialize()
    {
        try {
            $this->logger->debug('Connecting to the websocket');
            $this->engine->connect();
            $this->logger->debug('Connected to the server');

            $this->isConnected = true;
        } catch (SocketException $e) {
            $this->logger->error('Could not connect to the server', ['exception' => $e]);

            throw $e;
        }

        return $this;
    }

    /**
     * Reads a message from the socket
     *
     * @return string Message read from the socket
     */
    public function read()
    {
        $this->logger->debug('Reading a new message from the socket');
        $packet = $this->engine->read();
        $this->parsePacket($packet);
    }

    public function listen() {
        while (true) {
            try{
                $this->read();
            } catch (Exception $e) {
                break;
            }
        }
    }

    private function parsePacket($packet) {
        if (!empty($packet)) {
            var_dump($packet);
            $pos = strpos($packet, "[");
            if($pos!==false) {
                $code = substr($packet, 0, $pos);
                $code = intval($code);
                $data = substr($packet, $pos);
                $data = json_decode($data, true);
                switch ($code) {
                    case 42:
                        if ($data[0] == "ACK") {
                            //acknowledge
                            $ack = $data[0];
                            $eventId = $data[1];
                            $response = $data[2];
                            $this->executeCallback($eventId, $response);
                        } else {
                            $event = array_shift($data);
                            $params = $data;
                            $this->triggerListener($event, $params);
                        }
                    break;
                    default:
                        throw new Exception("Unhandled packet code");
                }
            } else {
                throw new Exception("No code for packet");
            }
        }
    }

    private function triggerListener($event, $params) {
        if(isset($this->eventListeners[$event])) {
            $cb = $this->eventListeners[$event];
            if(is_callable($cb)) {
                call_user_func($cb, ...$params);
            }
        }
    }

    private function executeCallback($eventId, $response) {
        $cb = $this->getCallback($eventId);
        if($cb) {
            call_user_func_array($cb, [$response]);
        }
        unset($this->callbacks[$eventId]);
    }

    private function getCallback($eventId) {
        if(isset($this->callbacks[$eventId])) {
            $cb = $this->callbacks[$eventId];
            if(is_callable($cb)) {
                return $cb;
            }
        }
        return false;
    }

    /**
     * Emits a message through the engine
     *
     * @param string $event
     * @param array  $args
     *
     * @return $this
     */
    public function emit($event, array $args, callable $ack = null)
    {
        if($ack) {
            $eventId = uniqid();
            $args[]="ACK:$eventId";
            $this->registerCallback($eventId, $ack);
        }

        $this->logger->debug('Sending a new message', ['event' => $event, 'args' => $args]);
        $this->engine->emit($event, $args, is_callable($ack));

        return $this;
    }

    private function registerCallback($eventId, $cb) {
        $this->callbacks[$eventId]=$cb;
    }

    public function on($event, $cb) {
        $this->eventListeners[$event] = $cb;
    }
    /**
     * Sets the namespace for the next messages
     *
     * @param string namespace the name of the namespace
     * @return $this
     */
    public function of($namespace)
    {
        $this->logger->debug('Setting the namespace', ['namespace' => $namespace]);
        $this->engine->of($namespace);

        return $this;
    }

    /**
     * Closes the connection
     *
     * @return $this
     */
    public function close()
    {
        $this->logger->debug('Closing the connection to the websocket');
        $this->engine->close();

        $this->isConnected = false;

        return $this;
    }

    /**
     * Gets the engine used, for more advanced functions
     *
     * @return EngineInterface
     */
    public function getEngine()
    {
        return $this->engine;
    }
}
