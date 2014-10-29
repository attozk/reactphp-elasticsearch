<?php

namespace Hathoora\Jaal\Daemons\Http\Client;

use Hathoora\Jaal\Daemons\Http\Message\Response;
use Hathoora\Jaal\Daemons\Http\Message\ResponseInterface;
use Hathoora\Jaal\IO\React\Socket\ConnectionInterface;
use Hathoora\Jaal\Jaal;

Class Request extends \Hathoora\Jaal\Daemons\Http\Message\Request implements RequestInterface
{
    /**
     * @var ResponseInterface
     */
    protected $response;
    protected $stream;

    public function __construct($method, $url, $headers = array())
    {
        parent::__construct($method, $url, $headers);
    }


    /**
     * Sets connection stream to client or proxy
     *
     * @param ConnectionInterface $stream
     * @return self
     */
    public function setStream(ConnectionInterface $stream)
    {
        $this->stream = $stream;

        return $this;
    }

    /**
     * Returns connection stream socket
     *
     * @return ConnectionInterface
     */
    public function getStream()
    {
        return $this->stream;
    }

    /**
     * Read data from stream until reached end of message
     *
     * @param $data
     */
    public function handleData($data)
    {
        $this->body .= $data;

        $EOM = $this->getEOMType();

        if ($EOM == 'length') {

        }
    }

    public function setResponse(ResponseInterface $response)
    {
        $this->response = $response;

        return $this;
    }

    public function getResponse() {
        return $this->response;
    }

    private function prepareResponseHeaders()
    {
       $this->response->addHeader('Connection', 'Closed');
    }

    public function send()
    {
        $this->prepareResponseHeaders();
        $this->setState(self::STATE_DONE);
        $this->stream->write($this->response->getRawHeaders() . $this->response->getBody());
        $this->end();
    }

    public function error($code, $description = '')
    {
        $this->setState(self::STATE_DONE);

        if (!$this->response)
            $this->response = new Response($code);

        $this->prepareResponseHeaders();
        $this->response->setStatusCode($code);

        if ($description)
            $this->response->setReasonPhrase($code);

        $this->stream->write($this->response->getRawHeaders() . $this->response->getBody());
        $this->end();
    }

    private function end()
    {
        Jaal::getInstance()->getDaemon('httpd')->inboundIOManager->removeProp($this->stream, 'request');
    }
}