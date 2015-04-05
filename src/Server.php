<?php
namespace Irto\OAuth2Proxy;

use React;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Container\Container;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Session\SessionServiceProvider;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Session\FileSessionHandler;

class Server extends Container {

    /**
     * Configuration middlewares
     * 
     * @var array
     */
    protected $middlewares = array(
        'Irto\OAuth2Proxy\Middleware\Session',
        'Irto\OAuth2Proxy\Middleware\CSRFToken',
        'Irto\OAuth2Proxy\Middleware\Authorization',
    );

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

        $server->singleton('config', function ($server) use ($config) {
            return new Collection($config);
        });

        $server->bind('Irto\OAuth2Proxy\Server', function ($server) {
            return $server;
        });

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
        $server->singleton('React\HttpClient\Client', function ($server) {
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

        // HTTP server for handle requests
        $server->singleton('SessionHandlerInterface', function ($server) {
            return new FileSessionHandler(
                $server['Illuminate\Filesystem\Filesystem'], 
                array_get($server['config']->all(), 'session.folder')
            );
        });

        $server->boot();

        return $server;
    }

    /**
     * Boot application
     * 
     * @return self
     */
    public function boot()
    {
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
     * Return some configured info
     * 
     * @param string $key
     * 
     * @return mixed
     */
    public function get($key)
    {
        return $this['config']->get($key);
    }

    /**
     * Return middlewares
     * 
     * @param bool $reverse order
     * 
     * @return Illuminate\Support\Collection
     */
    public function middlewares($reverse = false)
    {
        return new Collection($reverse ? array_reverse($this->middlewares) : $this->middlewares);
    }

    /**
     * Sends a data through configured middlewares
     * 
     * @param mixed $send
     * @param string $via function to be called from middlewares
     * @param bool $reverse order
     * 
     * @return Illuminate\Pipeline\Pipeline
     */
    public function throughMiddlewares($send, $via = 'handle', $reverse = false)
    {
        $middlewares = $this->middlewares($reverse);

        return (new Pipeline($this))
            // create request to be transformed in middlewares
            ->via($via)
            ->send($send)
            ->through($middlewares->all()); //middlewares
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
        $request = $this->make('Irto\OAuth2Proxy\ProxyRequest', compact('request'));
        $response = $this->make('Irto\OAuth2Proxy\ProxyResponse', compact('response', 'request'));
        $request->setFutureResponse($response);

        try {
            return $this->throughMiddlewares($request, 'request')
                ->then(function($request) use ($response) {

                    $request->on(
                        'response', 

                        /**
                         * Get async response and send to user through middlewares
                         * 
                         * @param React\HttpClient\Response $result
                         * @param React\Http\Response $response
                         * 
                         * @return void
                         */
                        function ($result) use ($response) {
                            $response->mergeClientResponse($result);

                            $result->on('data', function ($data) use ($response) {
                                $response->addDataToBuffer($data);
                            });

                            $result->on('end', function () use ($response) {

                                // send reponse to middlewares in reverse order
                                $this->throughMiddlewares($response, 'response', true)->then(function ($response) {
                                    $response->dispatch(); // sends response to user
                                });
                            });
                        }

                    );

                    $request->dispatch();// sends request to api
                    return $response;
                });
        } catch (TokenMismatchException $e) {
            $responseData = array(
                'type' => 'token_mismatch',
                'message' => 'Trying to do a not authorized action.'
            );
        } catch (\Exception $e) {
            $responseData = array(
                'type' => 'error',
                'message' => $e->getMessage()
            );
        }

        $response->headers()->put('Content-type', 'application/json');
        $response->dispatch(500, json_encode($responseData));
    }
}