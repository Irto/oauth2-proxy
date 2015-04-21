<?php
namespace Irto\OAuth2Proxy\Middleware;

use Closure;
use Carbon\Carbon;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpFoundation\Cookie;

use Irto\OAuth2Proxy\Server;

class Session {

    /**
     * @var Irto\OAuth2Proxy\Server
     */
    protected $server = null;

    /**
     * 
     * @param Irto\OAuth2Proxy\Server $server
     * 
     * @return Irto\OAuth2Proxy\Middleware\Session
     */
    public function __construct(Server $server)
    {
        $this->server = $server;
    }

    /**
     * 
     * @param Irto\OAuth2Proxy\ProxyRequest $request
     * @param Closure $next
     * 
     * @throws Exception
     * 
     * @return Irto\OAuth2Proxy\ProxyRequest
     */    
    public function request($request, Closure $next) 
    {
        $response = $request->futureResponse();
        $request->session()->start();

        $config = $this->server['config']['session'];
        $session = $request->session();

        $response->setCookie(new Cookie(
            $session->getName(), $session->getId(), Carbon::now()->addMinutes($config['lifetime']),
            $config['path'], $config['domain'], array_get($config, 'secure', false)
        ));

        try {
            return $next($request);
        } catch (TokenMismatchException $e) {
            $session->save();
            throw $e;
        }
    }

    /**
     * 
     * @param React\Http\Response $response
     * @param Closure $next
     * 
     * @return React\Http\Response
     */
    public function response($response, Closure $next) 
    {
        $response->originResponse()->on('end', function () use ($response) {
            $response->originRequest()->session()->save();
        });

        return $next($response);
    }
}