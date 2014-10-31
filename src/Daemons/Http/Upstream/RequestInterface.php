<?php

namespace Hathoora\Jaal\Daemons\Http\Upstream;

use Hathoora\Jaal\Daemons\Http\Client\RequestInterface as ClientRequestInterface;
use Hathoora\Jaal\Daemons\Http\Message\Response;
use Hathoora\Jaal\Daemons\Http\Message\ResponseInterface;
use Hathoora\Jaal\Daemons\Http\Vhost\Vhost;
use Hathoora\Jaal\IO\React\SocketClient\Stream;
use Hathoora\Jaal\IO\React\SocketClient\ConnectorInterface;

Interface RequestInterface extends \Hathoora\Jaal\Daemons\Http\Message\RequestInterface
{
    /**
     * Return  client's request
     *
     * @return ClientRequestInterface
     */
    public function getClientRequest();

    /**
     * Set outbound stream
     *
     * @param Stream $stream
     * @return self
     */
    public function setStream(Stream $stream);

    /**
     * Returns connection stream socket
     *
     * @return ConnectorInterface
     */
    public function getStream();

    /**
     * Set upstream response
     *
     * @param ResponseInterface $response
     * @return self
     */
    public function setResponse(ResponseInterface $response);

    /**
     * Get upstream response
     *
     * @return ResponseInterface
     */
    public function getResponse();

    /**
     * Send's the request to upstream server
     */
    public function send();

    /**
     * @param Stream $stream
     * @param $data
     * @return mixed
     */
    public function handleUpstreamOutputData(Stream $stream, $data);

    /**
     * Upstream reply is client's request response
     *
     * @param null $code to overwrite upstream's response
     * @param null $message
     */
    public function reply($code = null, $message = null);
}
