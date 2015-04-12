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
     * Booted middlewares
     * 
     * @var Illuminate\Support\Collection
     */
    private $_middlewares = array();

    /**
     * Configuration middlewares
     * 
     * @var array
     */
    protected $middlewares = array(
        'Irto\OAuth2Proxy\Middleware\Session',
        'Irto\OAuth2Proxy\Middleware\CSRFToken',
        'Irto\OAuth2Proxy\Middleware\Authorization',
        'Irto\OAuth2Proxy\Middleware\ProxyData',
    );

    /**
     * If are in verbose mode
     * 
     * @var boolean
     */
    protected $verbose = true;

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
        isset($config['verbose']) && $server->setVerbose($config['verbose']);

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
     * Constructor
     */
    public function __construct()
    {
        $this->log("\nCreating server...");
    }

    /**
     * Boot application
     * 
     * @return self
     */
    public function boot()
    {
        $this->log("\nBooting...");

        $this->_middlewares = new Collection($this->middlewares);
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

        $this->log("\n Starting main loop...");
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
     * Set server verbose mode
     * 
     * @param bool $bool
     * 
     * @return self
     */
    public function setVerbose($bool)
    {
        $this->verbose = (bool) $bool;

        return $this;
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
        return $reverse ? $this->_middlewares->reverse() : $this->_middlewares;
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

                            // send reponse to middlewares in reverse order
                            $this->throughMiddlewares($response, 'response', true)->then(function ($response) {
                                $response->dispatch(); // sends response to user
                            });
                        }

                    );

                    return $response;
                });
        } catch (TokenMismatchException $e) {
            $responseData = array(
                'type' => 'token_mismatch',
                'message' => 'Trying to do a not authorized action.'
            );
            $code = 400;
        } catch (\Exception $e) {
            $this->log("\nApplication get a exception: %s.", [$e->getMessage()]);

            $responseData = array(
                'type' => 'error',
                'message' => $e->getMessage()
            );
            $code = 500;
        }

        $response->headers()->put('Content-type', 'application/json');
        $response->write(json_encode($responseData));
        $response->dispatch($code);
        $response->end();
    }

    /**
     * Log a message
     * 
     * @param string message
     * @param array $params tobe placed with {@link sprintf}
     * 
     * @return self
     */
    public function log($message, array $params = array())
    {
        array_unshift($params, $message);
        $message = call_user_func_array('sprintf', $params);

        if ($this->verbose) {
            echo $message;
        }

        return $this;
    }
}