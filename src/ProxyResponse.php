<?php
namespace Irto\OAuth2Proxy;

use Illuminate\Support\Collection;
use Illuminate\Support\Arr;
use Evenement\EventEmitterTrait;
use Irto\OAuth2Proxy\Server;
use React\Http\Response;
use React\HttpClient\Response as ClientResponse;
use Irto\OAuth2Proxy\ProxyRequest as Request;
use Symfony\Component\HttpFoundation\Cookie;

class ProxyResponse {

    use EventEmitterTrait;

    /**
     * @var Irto\OAuth2Proxy\Server
     */
    protected $server = null;

    /**
     * Response headers
     * 
     * @var Illuminate\Support\Collection
     */
    protected $headers = null;

    /**
     * @var React\Http\Response
     */
    protected $original = null;

    /**
     * @var React\HttpClient\Response
     */
    protected $clientResponse = null;

    /**
     * @var React\HttpClient\Request
     */
    protected $request = null;

    /**
     * @var string
     */
    protected $buffer = null;

    /**
     * @var string
     */
    protected $buffering = true;

    /**
     * @var int
     */
    protected $code = null;

    /**
     * Headers not allowed to be proxied to API
     * 
     * @var array
     */
    protected $headersNotAllowed = array(
        'Access-Control-Allow-Origin',
        'Access-Control-Request-Method',
        'Access-Control-Allow-Headers',
        'Server',
        'Vary',
    );

    /**
     * Constructor
     * 
     * @param Irto\OAuth2Proxy\Server $server
     * @param React\Http\Response $response 
     * @param React\Http\Response $response 
     * 
     * @return Irto\OAuth2Proxy\ProxyRequest
     */
    public function __construct(Server $server, Response $response, Request $request)
    {
        $this->original = $response;
        $this->server = $server;
        $this->request = $request;

        $this->headers = new Collection();
    }

    /**
     * Headers that will be passed to API
     * 
     * @return Illuminate\Support\Collection
     */
    public function headers()
    {
        return $this->headers;
    }

    /**
     * 
     * 
     * @param string $data
     * 
     * @return self
     */
    public function write($data)
    {
        if ($this->buffering) {
            $this->buffer .= $data;
        } else {
            $this->original->write($data);
        }

        return $this;
    }

    /**
     * 
     * @return string
     */
    public function getBufferEnd()
    {
        $this->buffering = false;

        return $this->buffer;
    }

    /**
     * Add Cookies
     * 
     * @param Symfony\Component\HttpFoundation\Cookie $cookie
     * 
     * @return self
     */
    public function setCookie(Cookie $cookie)
    {
        $cookies = array_merge(
            $this->headers()->get('Set-Cookie', []),
            [$cookie->__toString()]
        );

        $this->headers()->put('Set-Cookie', $cookies);

        return $this;
    }

    /**
     * Originated request
     * 
     * @return React\Http\Request
     */
    public function originRequest()
    {
        return $this->request;
    }

    /**
     * Originated response
     * 
     * @return React\HttpClient\Response
     */
    public function clientResponse()
    {
        return $this->clientResponse;
    }

    /**
     * Originated response
     * 
     * @return React\Http\Response
     */
    public function originResponse()
    {
        return $this->original;
    }

    /**
     * 
     * 
     * @return int
     */
    public function dataLength()
    {
        return strlen($this->buffer);
    }

    /**
     * Return response code
     * 
     * @return int
     */
    public function getCode()
    {
        return $this->code ?: $this->clientResponse->getCode();
    }

    /**
     * Set response code
     * 
     * @return self
     */
    public function setCode($code)
    {
        $this->code = $code;

        return $this;
    }

    /**
     * 
     * @param React\HttpClient\Response $clientResponse
     * 
     * @return self
     */
    public function mergeClientResponse($clientResponse)
    {
        $headers = new Collection(
            Arr::except($clientResponse->getHeaders(), $this->headersNotAllowed)
        );
        $headers->forget(0);

        $this->headers = $this->headers()->merge($headers->all());
        $this->clientResponse = $clientResponse;

        return $this;
    }

    /**
     * Dispatch headers and buffered data to response
     * 
     * @param int $code [false]
     * @param string $data [null]
     * 
     * @return void
     */
    public function dispatch($code = false)
    {
        $code = $code ?: $this->getCode();
        $headers = $this->headers()->all();

        $this->original->writeHead($code, $headers);
        $data = $this->getBufferEnd();

        if (!empty($data)) $this->original->write($data);
    }

    /**
     * Ends connection and send $data
     * 
     * @param string $data [null]
     * 
     * @return void
     */
    public function end($data = null)
    {
        $this->original->end($data);
    }
}