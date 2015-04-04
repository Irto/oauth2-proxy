<?php
namespace Irto\OAuth2Proxy\Middleware;

use Closure;

class Authorization {
    
    /**
     * 
     * @param React\HttpClient\Response $response
     * @param Closure $next
     * 
     * @return React\HttpClient\Response
     */
    public function request($request, Closure $next) 
    {
        return $next($request);
    }

    /**
     * 
     * @param React\HttpClient\Response $response
     * @param Closure $next
     * 
     * @return React\HttpClient\Response
     */
    public function response($response, Closure $next) 
    {
        return $next($response);
    }
}