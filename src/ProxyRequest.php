<?php
namespace Irto\OAuth2Proxy;

use Illuminate\Support\Collection;
use Illuminate\Support\Arr;
use Evenement\EventEmitterTrait;
use Irto\OAuth2Proxy\Server;
use React\HttpClient\Client as HttpClient;
use React\Http\Request;
use React\Http\Response;

class ProxyRequest {

    use EventEmitterTrait;

    /**
     * @var Irto\OAuth2Proxy\Server
     */
    protected $server = null;

    /**
     * @var Illuminate\Session\Store
     */
    protected $session = null;

    /**
     * Request headers
     * 
     * @var Illuminate\Support\Collection
     */
    protected $headers = null;

    /**
     * Async HTTP Client for make requests to API
     * 
     * @var React\HttpClient\Factory
     */
    protected $client = null;

    /**
     * Future response to user
     * 
     * @var React\Http\Response
     */
    protected $response = null;

    /**
     * Origin Request
     * 
     * @var React\Http\Request
     */
    protected $request = null;

    /**
     * 
     * @var string
     */
    protected $buffer = null;

    /**
     * Is 'false' after api connect has established
     * 
     * @var string
     */
    protected $buffering = true;

    /**
     * Headers not allowed to be proxied to API
     * 
     * @var array
     */
    protected $headersNotAllowed = array(
        'User-Agent',
        'Host'
    );

    /**
     * Constructor
     * 
     * @param React\HttpClient\Factory $client
     * @param Irto\OAuth2Proxy\Server $server
     * @param React\Http\Request $request original request
     * 
     * @return Irto\OAuth2Proxy\ProxyRequest
     */
    public function __construct(HttpClient $client, Server $server, Request $request)
    {
        $headers = Arr::except($request->getHeaders(), $this->headersNotAllowed);

        $this->original = $request;
        $this->client = $client;
        $this->server = $server;

        $headers = new Collection($headers);
        $headers->put(
            'Cookie', 
            new Collection(\http_parse_cookie($headers->get('Cookie')))
        );

        $this->headers = $headers;

        $request->on('data', array($this, 'write'));
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
     * @return string
     */
    public function getBufferEnd()
    {
        $this->buffering = false;

        return $this->buffer;
    }

    /**
     * Write data to server or to buffer
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
            $this->request->write($data);
        }

        return $this;
    }

    /**
     * Future response for user
     * 
     * @return React\Http\Response
     */
    public function futureResponse()
    {
        return $this->response;
    }

    /**
     * Future response for user
     * 
     * @return self
     */
    public function setFutureResponse($response)
    {
        $this->response = $response;

        return $this;
    }

    /**
     * Headers that will be passed to API
     * 
     * @return Illuminate\Session\Store
     */
    public function session()
    {
        return $this->session ?: $this->createSession();
    }

    /**
     * Creates a new session from request
     * 
     * @return lluminate\Session\Store
     */
    protected function createSession()
    {
        $sessionName = array_get($this->server['config']->all(), 'session.name');

        return $this->session = $this->server->make('Illuminate\Session\Store', [
            'name' => $sessionName,
            'id' => array_get($this->headers()->get('Cookie')->all(), "cookies.{$sessionName}")
        ]);
    }

    /**
     * Ends request and dispatch
     * 
     * @return void
     */
    public function dispatch()
    {
        $method = $this->original->getMethod();
        $url = $this->server->get('api_url') . $this->original->getPath();
        $headers = $this->headers()->all();

        $this->request = $this->createClientRequest($method, $url, $headers);
        $this->request->end($this->getBufferEnd());
    }

    /**
     * Generates request for HTTP Client (API), and add event listners
     * @param string $method 
     * @param string $url
     * @param array $headers
     * @return React\HttpClient\Request
     */
    protected function createClientRequest($method, $url, $headers)
    {
        $request = $this->client->request($method, $url, $headers);

        foreach ($this->listeners as $event => $listeners) {
            $request->on($event, function () use ($event) {
                $this->emit($event, func_get_args());
            });
        }

        return $request;
    }
}