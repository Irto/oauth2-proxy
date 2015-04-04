<?php
namespace Irto\OAuth2Proxy;

use React;
use Illuminate\Container\Container;
use Illuminate\Pipeline\Pipeline;

class Server extends Container {

    /**
     * Configuration values
     * 
     * @var array
     */
    protected $config = [];

    /**
     * Create a new Irto\OAuth2Proxy\Server instance with $config
     * 
     * @param array $config
     * 
     * @return Irto\OAuth2Proxy\Server
     */
    public static function create(array $config)
    {
        $server = new static();

        array_walk($config, array($server, 'set'));

        // Create main loop React\EventLoop based
        $server->singleton('React\EventLoop\Factory', function ($server) {
            return React\EventLoop\Factory::create();
        });

        // DNS resolve, used for create async requests 
        $server->singleton('React\Dns\Resolver\Resolver', function ($server) {
            $dnsResolverFactory = new React\Dns\Resolver\Factory();
            return $dnsResolverFactory->createCached('8.8.8.8', $server['React\EventLoop\Factory']); //Google DNS
        });

        // HTTP Client
        $server->singleton('React\HttpClient\Factory', function ($server) {
            $factory = new React\HttpClient\Factory();
            return $factory->create(
                $server['React\EventLoop\Factory'], 
                $server['React\Dns\Resolver\Resolver']
            );
        });

        // Request handler to React\Http
        $server->singleton('React\Socket\Server', function ($server) {
            $socket = new React\Socket\Server($server['React\EventLoop\Factory']);

            $socket->listen($server->get('port'));

            return $socket;
        });

        // HTTP server for handle requests
        $server->singleton('React\Http\Server', function ($server) {
            return new React\Http\Server($server['React\Socket\Server']);
        });

        return $server;
    }

    /**
     * Execute application
     * 
     * @return self
     */
    public function run()
    {
        $http = $this['React\Http\Server'];
        $http->on('request', array($this, 'handleRequest'));

        $this['React\EventLoop\Factory']->run();
    }

    /**
     * Configure some $key with $value
     * 
     * @param mixed $value
     * @param string $key
     * 
     * @return self
     */
    public function set($value, $key)
    {
        $this->config[$key] = $value;

        return $this;
    }

    /**
     * Return some configured info
     * 
     * @param string $key
     * 
     * @return mixed
     */
    public function get($key)
    {
        return $this->config[$key];
    }

    /**
     * Handler for user requests that will be proxied
     * 
     * @param React\Http\Request $request
     * @param React\Http\Response $response
     * 
     * @return void
     */
    public function handleRequest($request, $response)
    {
        (new Pipeline($this))
            // create request to be transformed in middlewares
            ->send($this->createProxyRequestTo($request))
            ->through([]) //middlewares
            ->then(function($request) use ($response) {
                $request->on(
                    'response', 
                    // work with response when get it
                    function ($result) use ($response) {
                        // pass header to new response
                        $response->writeHead(
                            $result->getCode(),
                            $result->getHeaders()
                        );

                        // when get response wait and send data
                        $result->on('data', array($response, 'end'));
                    }
                );

                $request->end();
                return $response;
            });
    }

    /**
     * Create a request to API
     * 
     * @param React\Http\Request $request
     * 
     * @return React\HttpClient\Request
     */
    protected function createProxyRequestTo($request)
    {
        $client = $this->make('React\HttpClient\Factory');
        $url = $this->get('api_url');

        $request = $client->request(
            $request->getMethod(), // HTML Method Verb
            $url . $request->getPath(), // create URL to API
            $request->getHeaders() // Headers
        );

        return $request;
    }
}