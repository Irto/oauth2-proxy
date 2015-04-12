<?php
namespace Irto\OAuth2Proxy\Middleware;

use Closure;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpFoundation\Cookie;
use Irto\OAuth2Proxy\Server;

class CSRFToken {

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
        $token = $request->headers()->get('X-XSRF-TOKEN');

        if ( ! $token || $token != $request->session()->token()) {
            $cookie = new Cookie('XSRF-TOKEN', $request->session()->token(), time() + 60 * 120, '/', null, false, false);
            $request->futureResponse()->setCookie($cookie);

            throw new TokenMismatchException;
        } else {
            $response = $next($request);
        }

        return $response;
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
        return $next($response);
    }
}