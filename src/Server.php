<?php
namespace Irto\OAuth2Proxy;

use React;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Container\Container;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Session\SessionServiceProvider;
use Illuminate\Session\TokenMismatchException;

use Irto\OAuth2Proxy\ProxyResponse;

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
     * 
     * @var array
     */
    protected $clientCredentials = array();

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
        $server->singleton('React\EventLoop\LoopInterface', function ($server) {
            return React\EventLoop\Factory::create();
        });

        // DNS resolve, used for create async requests 
        $server->singleton('React\Dns\Resolver\Resolver', function ($server) {
            $dnsResolverFactory = new React\Dns\Resolver\Factory();
            return $dnsResolverFactory->createCached('8.8.8.8', $server['React\EventLoop\LoopInterface']); //Google DNS
        });

        // HTTP Client
        $server->singleton('React\HttpClient\Client', function ($server) {
            $factory = new React\HttpClient\Factory();
            return $factory->create(
                $server['React\EventLoop\LoopInterface'], 
                $server['React\Dns\Resolver\Resolver']
            );
        });

        // Request handler to React\Http
        $server->singleton('React\Socket\Server', function ($server) {
            $socket = new React\Socket\Server($server['React\EventLoop\LoopInterface']);

            $socket->listen($server->get('port'));

            return $socket;
        });

        // HTTP server for handle requests
        $server->singleton('React\Http\Server', function ($server) {
            return new React\Http\Server($server['React\Socket\Server']);
        });

        // HTTP server for handle requests
        $server->singleton('SessionHandlerInterface', function ($server) {
            return $server->make('Irto\OAuth2Proxy\Session\AsyncRedisSessionHandler', [
                'lifetime' => array_get($server['config']->all(), 'session.lifetime')
            ]);
        });

        $server->bind('Illuminate\Session\Store', 'Irto\OAuth2Proxy\Session\Store');

        $server->boot();

        return $server;
    }

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->log('Creating server...');
    }

    /**
     * Boot application
     * 
     * @return self
     */
    public function boot()
    {
        $this->log('Booting...');

        $this->_middlewares = new Collection($this->middlewares);

        $loop = $this['React\EventLoop\LoopInterface'];
        $loop->addPeriodicTimer(60 * 30, array($this, 'garbageCollect'));

        $loop->addPeriodicTimer(60 * 30, function () {
            $memory = memory_get_usage() / 1024;
            $formatted = number_format($memory, 3).'K';
            $this->log('Current memory usage: %s', [$formatted]);
        });

        $this['SessionHandlerInterface']->open(
            null,
            array_get($this['config']->all(), 'session.name')
        )->then(function () {
            $this->call(array($this, 'requestClientToken'));
        });
    }

    /**
     * Execute gabarage collect, like for sessions
     * 
     * @return void
     */
    public function garbageCollect()
    {
        $this->log('Garbage Collector fired.');

        $sessionHandler = $this['SessionHandlerInterface'];
        $config = $this['config']['session'];
        
        $sessionHandler->gc($config['lifetime'] * 1.1);
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

        $this->log('Main loop start.');
        $this['React\EventLoop\LoopInterface']->run();
    }

    /**
     * Update public access token
     * 
     * @return mixed
     */
    public function requestClientToken(React\HttpClient\Client $client, React\EventLoop\LoopInterface $loop)
    {
        $this->log('! Updating access token for public api.');

        $url = $this['config']->get('api_url') . $this['config']->get('grant_path');

        $data = json_encode(array(
            'grant_type' => 'client_credentials',
            'client_id' => $this['config']->get('client_id'),
            'client_secret' => $this['config']->get('client_secret'),
        ));

        // create request to retrive access token
        $request = $client->request('POST', $url, array(
            'Content-Type' => 'application/json;charset=UTF-8',
            'Content-Length' => strlen($data),
        ));

        $request->on('response', function ($response) use ($loop) {
            // Response return an error message, it will log and exit
            if ($response->getCode() >= 300 || $response->getCode() < 200) {
                $this->log('!E! Got error on update API public credentials.');

                $response->on('data', function ($data) { $this->log($data); });

                $loop->addTimer(
                    5,

                    /**
                     * Attempt refresh token every 5 seconds until it's work.
                     * 
                     * @return void
                     */
                    function () {
                        $this->log('!E! Retring get API public credentials.');
                        $this->call(array($this, 'requestClientToken'));
                    }
                );

                return;
            }

            $buffer = null;

            // buffer response data
            $response->on('data', function ($data) use (&$buffer) {
                $buffer .= $data;
            });

            $response->on('end', function () use (&$buffer, $loop) {
                $this->clientCredentials = json_decode($buffer, true);

                $this->log('Access token updated! (%s)', [$buffer]);

                $loop->addTimer(
                    $this->getClientCredentials()['expires_in'] - 120,

                    /**
                     * Refresh client access token from api 2 minutes before expire
                     * 
                     * @return void
                     */
                    function () {
                        $this->call(array($this, 'requestClientToken'));
                    }
                );
            });
        });

        $request->end($data);
    }

    /**
     * 
     * 
     * @return array
     */
    public function getClientCredentials()
    {
        return $this->clientCredentials;
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
                            $this->throughMiddlewares($response, 'response')->then(function ($response) {
                                if ($response instanceof ProxyResponse) $response->dispatch(); // sends response to user
                            });
                        }

                    );

                    return $response;
                });
        } catch (\Exception $e) {
            return $this->catchException($e, $response);
        }
    }

    /**
     * 
     */
    public function catchException($e, $response)
    {

        switch (true) {
            case $e instanceof TokenMismatchException:
                $responseData = array(
                    'type' => 'token_mismatch',
                    'message' => 'Trying to do a not authorized action.'
                );
                $code = 400;
                break;
            default:
                $this->log('Application get a exception: %s %s.', [$e->getMessage()]);
                
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
        $message = call_user_func_array('sprintf', $params) . "\n";

        if ($this->verbose) {
            echo date('Y-m-d H:i:s') . '> ' . $message;
        }

        return $this;
    }
}