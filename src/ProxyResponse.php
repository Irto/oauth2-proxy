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
     * Headers not allowed to be proxied to API
     * 
     * @var array
     */
    protected $headersNotAllowed = array(
        'Access-Control-Allow-Origin',
        'Access-Control-Request-Method',
        'Access-Control-Allow-Headers',
        'Connection',
        'Server',
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
     * Headers that will be passed to API
     * 
     * @param string $data
     * 
     * @return self
     */
    public function addDataToBuffer($data)
    {
        $this->buffer .= $data;

        return $this;
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

        $this->headers = $headers->merge($this->headers()->all());

        $this->clientResponse = $clientResponse;

        return $this;
    }

    /**
     * Ends request and dispatch
     * 
     * @param int $code [false]
     * @param string $data [null]
     * 
     * @return void
     */
    public function dispatch($code = false, $data = null)
    {
        $code = $code ?: $this->clientResponse->getCode();
        $headers = $this->headers()->all();

        $this->addDataToBuffer($data);
        $this->original->writeHead($code, $headers);
        $this->original->end($this->buffer);
    }
}