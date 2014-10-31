<?php

namespace Hathoora\Jaal\Daemons\Http\Upstream;

use Hathoora\Jaal\Jaal;
use Hathoora\Jaal\Logger;
use Hathoora\Jaal\Daemons\Http\Client\RequestInterface as ClientRequestInterface;
use Hathoora\Jaal\Daemons\Http\Message\Parser;
use Hathoora\Jaal\Daemons\Http\Message\Response;
use Hathoora\Jaal\Daemons\Http\Message\ResponseInterface;
use Hathoora\Jaal\Daemons\Http\Vhost\Vhost;
use Hathoora\Jaal\IO\React\SocketClient\ConnectorInterface;
use Hathoora\Jaal\IO\React\SocketClient\Stream;
use Hathoora\Jaal\Util\Time;

Class Request extends \Hathoora\Jaal\Daemons\Http\Message\Request implements RequestInterface
{
    /**
     * @var Vhost
     */
    protected $vhost;

    /**
     * @var ClientRequestInterface
     */
    protected $clientRequest;

    /**
     * @var Stream
     */
    protected $stream;

    /**
     * @var ResponseInterface
     */
    protected $response;

    /**
     * @var array
     */
    private $handleUpstreamDataAtts = [
        'consumed' => 0,
        'length' => 0,
        'buffer' => '',
        'methodEOM' => '',
        'segments' => 0,
        'hasError' => false
    ];

    /**
     * @param Vhost $vhost
     * @param ClientRequestInterface $clientRequest
     */
    public function __construct(Vhost $vhost, ClientRequestInterface $clientRequest)
    {
        parent::__construct($clientRequest->getMethod(), $clientRequest->getUrl(), $clientRequest->getHeaders());
        $this->setBody($clientRequest->getBody());
        $this->vhost = $vhost;
        $this->clientRequest = $clientRequest;
        $this->prepareHeaders();
        $this->setState(ClientRequestInterface::STATE_PENDING);
    }


    /**
     * Prepares headers for the request which would be sent to upstream (from Jaal server)
     */
    protected function prepareHeaders()
    {
        if ($version = $this->vhost->config->get('http_version')) {
            $this->setProtocolVersion($version);
        }

        // setting new proxy request headers
        $arrHeaders = $this->vhost->config->get('headers.server_to_upstream_request');

        foreach ($arrHeaders as $header => $value) {
            $this->addHeader($header, $value);
        }

        // copy headers from original (client) request to request we will make to upstream
        $arrClientHeaders = $this->clientRequest->getHeaders();
        foreach ($arrClientHeaders as $header => $value) {
            $header = strtolower($header);

            if (isset(Headers::$arrAllowedUpstreamHeaders[$header]) && !$this->hasHeader($header)) {
                $this->addHeader($header, $value);
            }
        }
    }

    /**
     * Return  client's request
     *
     * @return ClientRequestInterface
     */
    public function getClientRequest()
    {
        return $this->clientRequest;
    }

    /**
     * Set outbound stream
     *
     * @param Stream $stream
     * @return self
     */
    public function setStream(Stream $stream)
    {
        $this->stream = $stream;

        return $this;
    }

    /**
     * Returns connection stream socket
     *
     * @return ConnectorInterface
     */
    public function getStream()
    {
        return $this->stream;
    }

    /**
     * Set upstream response
     *
     * @param ResponseInterface $response
     * @return self
     */
    public function setResponse(ResponseInterface $response)
    {
        $this->response = $response;

        return $this;
    }

    /**
     * Get upstream response
     *
     * @return ResponseInterface
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * Send's the request to upstream server
     */
    public function send()
    {
        $this->setState(self::STATE_RETRIEVING);

        $hello = $this->getRawHeaders() . "\r\n\r\n" . $this->getBody();

        Logger::getInstance()->log(-100, "\n" . '----------- Request Write: ' . $this->id . ' -----------' . "\n" .
            $hello .
            "\n" . '----------- /Request Write: ' . $this->id . ' -----------' . "\n");

        $this->stream->write($hello);
    }

    /**
     * Handles upstream data
     */
    public function handleUpstreamOutputData(Stream $stream, $data)
    {
        if ($this->vhost->outboundIOManager->getProp($stream, 'request')) {

            $request = $this->vhost->outboundIOManager->getProp($stream, 'request');

            $consumed =& $request->handleUpstreamDataAtts['consumed'];
            $length =& $request->handleUpstreamDataAtts['length'];
            $methodEOM =& $request->handleUpstreamDataAtts['methodEOM'];
            $hasError =& $request->handleUpstreamDataAtts['hasError'];
            $buffer =& $request->handleUpstreamDataAtts['buffer'];
            $segments =& $request->handleUpstreamDataAtts['segments'];

            $segments++;
            $isEOM = false;
            $response = null;
            $buffer .= $data;

            if (!$methodEOM) {

                Logger::getInstance()->log(-100, "\n" . '----------- Request Read: ' . $request->id . ' -----------' . "\n" .
                    $data .
                    "\n" . '----------- /Request Read: ' . $request->id . ' -----------' . "\n");

                // @TODO no need to parse entire message, just look for content-length

                if (strlen($data))
                    $response = Parser::getResponse($data);

                if ($response) {
                    if ($response->hasHeader('Content-Length')) {
                        $length = $response->getHeader('Content-Length');
                        $methodEOM = 'length';
                    } else if ($response->hasHeader('Transfer-Encoding') && ($header = $response->getHeader('Transfer-Encoding')) && $header == 'chunked') {
                        $methodEOM = 'chunk';
                    } else
                        $hasError = 400;

                    // remove header from body as we keep track of bodylength
                    $data = $response->getBody();
                } else {
                    $hasError = 401;
                }
            }

            if (!$hasError) {
                // @TODO check of end of message in chunk mode
                if ($methodEOM == 'chunk' && $data = "") {
                    $isEOM = true;
                } else if ($methodEOM == 'length') {

                    $consumed += strlen($data);

                    if ($consumed >= $length) {
                        $isEOM = true;
                    }
                }

                if ($isEOM) {

                    if ($response)
                        $request->response = $response;
                    else
                        $request->response = Parser::getResponse($buffer);

                    if ($request->response instanceof ResponseInterface) {
                        $request->setExecutionTime();
                        $request->response->setMethod($this->getMethod());
                        $request->setState(self::STATE_DONE);
                        $request->clientRequest->setResponse(clone $this->response);
                        $this->end();

                    } else {
                        $hasError = 404;
                    }
                }
            }


            if ($hasError) {
                $request->setState(self::STATE_ERROR);
                $request->end();
            }

        } else {
            die('Out of sync...');
        }
    }

    /**
     * Prepares client's reponse headers once upstream's response has been received
     */
    protected function prepareClientResponseHeader()
    {
        if ($this->clientRequest->getResponse()) {
            $arrHeaders = $this->vhost->config->get('headers.upstream_to_client_response');

            foreach ($arrHeaders as $header => $value) {
                if ($value === false) {
                    $this->clientRequest->getResponse()->removeHeader($header);
                } else {
                    $this->clientRequest->getResponse()->addHeader($header, $value);
                }
            }

            $this->clientRequest->getResponse()->addHeader('Exec-Time', $this->clientRequest->getExecutionTime());
            $this->clientRequest->getResponse()->addHeader('X-Exec-Time', $this->getExecutionTime());
            $this->clientRequest->setExecutionTime();
        }
    }

    /**
     * Upstream reply is client's request response
     *
     * @param null $code to overwrite upstream's response
     * @param null $message
     */
    public function reply($code = null, $message = null)
    {
        $this->prepareClientResponseHeader();
        $this->cleanup();

        $eventName = Jaal::getInstance()->getDaemon('httpd')->emitUpstreamResponseHandler($this, $code, $message);

        // by default spit the message out
        if (!Jaal::getInstance()->getDaemon('httpd')->listeners($eventName)) {
            $this->clientRequest->reply($code, $message);
        }
    }

    /**
     * Cleanups internal registry
     */
    private function cleanup()
    {
        Logger::getInstance()->log(-99, 'UPSTREAM RESPONSE ('. $this->state .') ' . Logger::getInstance()->color($this->getUrl(), 'red') . ' using remote stream: '. Logger::getInstance()->color($this->stream->id, 'green'));
        Jaal::getInstance()->getDaemon('httpd')->outboundIOManager->removeProp($this->stream, 'request');

        if (!$this->vhost->config->get('upstreams.keepalive.max') && !$this->vhost->config->get('upstreams.keepalive.max')) {
            $this->stream->end();
        }
    }
}
