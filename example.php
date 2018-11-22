<?php

require './src/WebSocket.php';

$address = "127.0.0.1";
$port = 9001;

$socket = new WebSocket();

try {
    $socket
        ->bind($address, $port)
        ->listen(20);
} catch (HttpSocketException $exception) {
    console($exception);
    die;
}


/**
 * $client | socket resource of the client
 * $buffer | received buffer
 **/
$socket->onMessage(function ($client, $buffer) {
    //unmask data to humanly readable message
    $msg = WebSocket::unmask($buffer);
    socket_write($client, WebSocket::encode("You wrote : " . $msg));
});


/**
 * $client | socket resource of the client
 **/
$socket->onClosed(function ($client) {
    echo $client . PHP_EOL;
});

/**
 * called before handshake
 * $client | socket resource of the client
 * $data | the first data sent by the user will always be a header
 *              where u can find more information about the client
 **/
$socket->setMiddleware(function ($client, $data) {
    return true;
});

@$socket->start();


function console($text)
{
    $File = "log.txt";
    $Handle = fopen($File, 'a');
    fwrite($Handle, $text);
    fclose($Handle);
}

