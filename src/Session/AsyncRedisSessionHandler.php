<?php 
namespace Irto\OAuth2Proxy\Session;

use SessionHandlerInterface;
use Clue\React\Redis\Factory;
use React\EventLoop\LoopInterface;
use Symfony\Component\Finder\Finder;
use Illuminate\Filesystem\Filesystem;

use Irto\OAuth2Proxy\Server;

class AsyncRedisSessionHandler implements SessionHandlerInterface {

    /**
     * @var Clue\React\Redis\Factory
     */
    protected $factory;

    /**
     * @var React\Promise\PromiseInterface
     */
    protected $client;

    /**
     * @var int
     */
    protected $lifetime;

    /**
     * @var string
     */
    protected $savePath;

    /**
     * @var \React\Promise\PromiseInterface
     */
    protected $promise;

    /**
     * @var React\EventLoop\LoopInterface
     */
    protected $loop;

    /**
     * @var Irto\OAuth2Proxy\Server
     */
    protected $server;

    /**
     * @var array
     */
    protected $statistics = array(
        'best' => 0,
        'poor' => 0,
        'avg' => 0,
        'total' => 0,
    );

    /**
     * Create a new file driven handler instance.
     *
     * @param \Illuminate\Filesystem\Filesystem $files
     * @param React\EventLoop\LoopInterface $loop
     * @param string $path
     * 
     * @return void
     */
    public function __construct(Factory $factory, LoopInterface $loop, Server $server, $lifetime)
    {
        $this->factory = $factory;
        $this->lifetime = $lifetime;
        $this->server = $server;
        $this->loop = $loop;

        // PING redis connection every 5 minutes
        // and try to reconnect on connection error
        $this->timer = $loop->addPeriodicTimer(60 * 5, function () use ($server) {
            $this->promise->then(function ($client) use ($server) {
                $client->PING()->then(function ($pong) use ($server) {
                    $server->log('Redis server responded ping with: %s', [$pong]);

                    return ($pong == 'PONG');
                }, function ($e) {
                    return $this->reconnectByError($e);
                });
            });
        });

        // server statistics for redis connection
        $this->loop->addPeriodicTimer(60 * 30, function () {
            $this->server->log('Server statistics to the last 30 minutes.');
            $this->server->log('Best time of %fs, poor time of %fs and a average of %f seconds for total %d requests.', array_values($this->statistics));

            $this->statistics = array(
                'best' => 0,
                'poor' => 0,
                'avg' => 0,
                'total' => 0,
            );
        });
    }

    /**
     * Try to recconect to server and logs $e error
     * 
     * @return \React\Promise\PromiseInterface
     */
    protected function reconnectByError(\Exception $e) 
    {
        $this->server->log('Application got an error on ping redis server: %s', [$e->getMessage()]);

        // attempt to reconnect
        $this->server->log('Attempt to reconnect with Redis server.');
        return $this->getClient();
    }

    /**
     * Delay timer to verify server connection
     * 
     * @return void
     */
    public function delayTimer()
    {
        $this->timer->cancel();
        $this->timer = $this->loop->addPeriodicTimer(
            $this->timer->getInterval(), 
            $this->timer->getCallback()
        );
    }

    /**
     * Connect to server and retrun promise with client.
     * 
     * @return \React\Promise\PromiseInterface
     */
    public function createClient()
    {
        return $this->factory->createClient($this->savePath)->then(function ($client) {
            $this->server->log('Connected with success to Redis server.');

            return $client;
        }, function ($e) {
            $this->server->log('Application got an error on try to connect with Redis server: %s.', [$e->getMessage()]);
            
            throw $e;
        });
    }

    /**
     * Retrive client connection or creates one.
     * 
     * @return \React\Promise\PromiseInterface
     */
    public function getClient()
    {
        return $this->promise ?: ($this->promise = $this->createClient());
    }

    /**
     * Save time to statistics
     * 
     * @param int $time
     * 
     * @return void
     */
    public function saveTime($time)
    {
        if ($time < $this->statistics['best'] || $this->statistics['best'] == 0) {
            $this->statistics['best'] = $time;
        }

        if ($time > $this->statistics['poor']) {
            $this->statistics['poor'] = $time;
        }

        $this->statistics['avg'] += $time;

        if ($this->statistics['avg'] != $time) {
            $this->statistics['avg'] /= 2;
        }

        ++$this->statistics['total'];
    }

    /**
     * {@inheritDoc}
     */
    public function open($savePath, $sessionName)
    {
        $this->savePath = $savePath;

        return $this->getClient()->then(function ($client) {
            $this->client = $client;
        }, function ($e) {
            throw $e;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function close()
    {
        $this->client->end();

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function read($sessionId)
    {
        $time = microtime(true);

        return $this->client->GET($sessionId)->then(function ($response) use ($time) {
            // record time
            $this->saveTime(microtime(true) - $time);

            return $response;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function write($sessionId, $data)
    {
        $this->client->SET($sessionId, $data)->then(function ($response) {
            return $this->delayTimer();
        }, function ($e) {
            return $this->reconnectByError($e);
        });

        $this->client->EXPIRE($sessionId, $this->lifetime);
    }

    /**
     * {@inheritDoc}
     */
    public function destroy($sessionId)
    {
        $this->client->DEL($sessionId);
    }

    /**
     * {@inheritDoc}
     */
    public function gc($lifetime)
    {
        return true;
    }

}
