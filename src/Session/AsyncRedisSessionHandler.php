<?php 
namespace Irto\OAuth2Proxy\Session;

use SessionHandlerInterface;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Clue\React\Redis\Factory;

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
     * Create a new file driven handler instance.
     *
     * @param  \Illuminate\Filesystem\Filesystem  $files
     * @param  string  $path
     * @return void
     */
    public function __construct(Factory $factory, $lifetime)
    {
        $this->factory = $factory;
        $this->lifetime = $lifetime;
    }

    /**
     * {@inheritDoc}
     */
    public function open($savePath, $sessionName)
    {
        return $this->factory->createClient()->then(function ($client) {
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
        return $this->client->GET($sessionId);
    }

    /**
     * {@inheritDoc}
     */
    public function write($sessionId, $data)
    {
        $this->client->SET($sessionId, $data);
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
        
    }

}
