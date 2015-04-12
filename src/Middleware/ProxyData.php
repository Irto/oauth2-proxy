<?php
namespace Irto\OAuth2Proxy\Middleware;

use Closure;

class ProxyData {
    
    /**
     * 
     * @param Irto\OAuth2Proxy\ProxyRequest  $request
     * @param Closure $next
     * 
     * @return Irto\OAuth2Proxy\ProxyResponse
     */
    public function request($request, Closure $next) 
    {
        $response = $next($request);

        $request->dispatch();// sends request to api

        return $response;
    }

    /**
     * 
     * @param Irto\OAuth2Proxy\ProxyResponse $response
     * @param Closure $next
     * 
     * @return Irto\OAuth2Proxy\ProxyResponse
     */
    public function response($response, Closure $next) 
    {
        $original = $response->originResponse();

        $original->on('data', function ($data) use ($response, $original) {
            $response->write($data);

            if ($response->dataLength() === (int) $response->headers()->get('Content-Length', -1)) {
                $original->close();
            }

        });

        $original->on('end', function () use ($response) {
            $response->end();
        });

        return $next($response);
    }
}