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
     * Catch a proxied request
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

        $request->session()->start()->then(function () use ($response, $request, $next) {
            $config = $this->server['config']['session'];
            $session = $request->session();

            $response->setCookie(new Cookie(
                $session->getName(), $session->getId(), Carbon::now()->addMinutes($config['lifetime']),
                $config['path'], $config['domain'], array_get($config, 'secure', false)
            ));

            try {
                return $next($request);
            } catch (\Exception $e) {
                $session->save();
                $this->server->catchException($e, $response);
            }
        }, function ($e) use ($response) {
            return $this->server->catchException($e, $response);
        });
    }

    /**
     * Catch response from request to api
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