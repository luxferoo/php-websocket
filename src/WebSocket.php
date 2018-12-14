<?php


class WebSocket
{
    private $master;
    private $clients = [];

    private $onMessage;
    private $onClosed;
    private $middleware;

    public function __construct()
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
        if (!is_resource($socket))
            throw new \HttpSocketException("socket_create() failed: " . socket_strerror(socket_last_error()));
        $this->master = $socket;
        $this->clients = [$socket];
        return $this;
    }

    public function bind($address, $port)
    {
        if (!socket_bind($this->master, $address, $port))
            throw new \HttpSocketException("socket_bind() failed: " . socket_strerror(socket_last_error()));
        return $this;
    }

    public function listen($backlog)
    {
        if (!socket_listen($this->master, $backlog))
            throw new \HttpSocketException("socket_listen() failed: " . socket_strerror(socket_last_error()));
        return $this;
    }

    public function onMessage(Closure $cb)
    {
        $this->onMessage = $cb;
    }

    public function onClosed(Closure $cb)
    {
        $this->onClosed = $cb;
    }

    public function setMiddleware(Closure $cb)
    {
        $this->middleware = $cb;
    }

    public function start()
    {
        while (true) {
            $read = $this->clients;
            $write = null;
            $except = null;
            if (socket_select($read, $write, $except, 0) < 1)
                continue;

            // check if there is a client trying to connect
            if (in_array($this->master, $read)) {
                $this->clients[] = $newsock = socket_accept($this->master);
                $data = @socket_read($newsock, 4096, PHP_BINARY_READ);
                if ($this->middleware instanceOf Closure && !call_user_func_array($this->middleware, [$newsock, $data])) {
                    $key = array_search($this->master, $read);
                    unset($read[$key]);
                    $key = array_search($newsock, $this->clients);
                    unset($this->clients[$key]);
                }
                if ($this->handshake($newsock, $data)) {
                    $key = array_search($this->master, $read);
                    unset($read[$key]);
                }
            }

            // loop through all the clients that have data to read from
            foreach ($read as $read_sock) {
                $bytes = socket_recv($read_sock, $buf, 4096, MSG_DONTWAIT);
                if ($bytes === 0) {
                    $key = array_search($read_sock, $this->clients);
                    call_user_func_array($this->onClosed, [$read_sock]);
                    unset($this->clients[$key]);
                } else {
                    $buf = trim($buf);
                    if (!empty($buf)) {
                        call_user_func_array($this->onMessage, [$read_sock, $buf]);
                    }
                }
            }
        }
    }

    public function getClients()
    {
        return $this->clients;
    }

    public static function unmask($payload)
    {
        $length = ord($payload) & 127;

        if ($length == 126) {
            $masks = substr($payload, 4, 4);
            $data = substr($payload, 8);
        } elseif ($length == 127) {
            $masks = substr($payload, 10, 4);
            $data = substr($payload, 14);
        } else {

            $masks = substr($payload, 2, 4);
            $data = substr($payload, 6);

        }

        $text = '';
        for ($i = 0; $i < strlen($data); ++$i) {
            $text .= $data[$i] ^ $masks[$i % 4];
        }
        return $text;
    }

    private function handshake($client, $headers)
    {
        preg_match("/Sec-WebSocket-Version: (.*)\r\n/", $headers, $match);
        $version = $match[1];
        if ($version == 13) {
            if (preg_match("/Sec-WebSocket-Key: (.*)\r\n/", $headers, $match))
                $key = $match[1];
            $acceptKey = $key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
            $acceptKey = base64_encode(sha1($acceptKey, true));

            $upgrade = "HTTP/1.1 101 Switching Protocols\r\n" .
                "Upgrade: websocket\r\n" .
                "Connection: Upgrade\r\n" .
                "Sec-WebSocket-Accept: $acceptKey" .
                "\r\n\r\n";
            socket_write($client, $upgrade);
            return true;
        } else {
            return false;
        }
    }

    public static function encode($text)
    {
        // 0x1 text frame (FIN + opcode)
        $b1 = 0x80 | (0x1 & 0x0f);
        $length = strlen($text);

        if ($length <= 125) $header = pack('CC', $b1, $length); elseif ($length > 125 && $length < 65536) $header = pack('CCS', $b1, 126, $length);
        elseif ($length >= 65536)
            $header = pack('CCN', $b1, 127, $length);

        return $header . $text;
    }
}

