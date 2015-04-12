<?php
namespace Irto\OAuth2Proxy\Middleware;

use Closure;
use Irto\OAuth2Proxy\Server;

class Authorization {

    /**
     * @var Irto\OAuth2Proxy\Server
     */
    protected $server = null;

    /**
     * Buffered data
     * 
     * @var string
     */
    protected $buffer = null;

    /**
     * Constructor
     * 
     * @param Irto\OAuth2Proxy\Server $server
     * 
     * @return Irto\OAuth2Proxy\Middleware\Authorization
     */
    public function __construct(Server $server)
    {
        $this->server = $server;
    }

    /**
     * Change data watchers to work in api credentials to send to api server
     * 
     * @param Irto\OAuth2Proxy\ProxyRequest $request
     * 
     * @return void
     */
    protected function proxyContent($request)
    {
        $original = $request->originRequest();

        $original->removeAllListeners('data');
        $original->on('data', function ($data) use ($request, $original) {
            $this->bufferData($data);

            if ($this->bufferLength() == (int) $original->getHeaders()['Content-Length']) {
                $request->write($this->getDataEnd());
            }
        });
    }

    /**
     * Buffered data length
     * 
     * @return int
     */
    protected function bufferLength()
    {
        return strlen($this->buffer);
    }

    /**
     * Buffer data 
     * 
     * @return self
     */
    protected function bufferData($data)
    {
        $this->buffer .= $data;

        return $this;
    }

    /**
     * Return total content length after data merge
     * 
     * @return int
     */
    protected function getContentLength($request)
    {
        $length = (int) $request->headers()->get('Content-Length');

        return $length + strlen(json_encode($this->getOAuthCredentials())) - 1;
    }

    /**
     * Return data to send to API with credentials merged with front-end data
     * 
     * @return string
     */
    protected function getDataEnd()
    {
        $data = json_decode($this->buffer, true);
        $this->buffer = null;

        if (!empty($data) && $data) {
            $data += $this->getOAuthCredentials();
            return json_encode($data, JSON_UNESCAPED_SLASHES);
        }
    }

    /**
     * Return configured webapp oauth2 credentials to api
     * 
     * @return array
     */
    private function getOAuthCredentials()
    {
        return array(
            'grant_type' => 'password',
            'client_id' => $this->server['config']->get('client_id'),
            'client_secret' => $this->server['config']->get('client_secret'),
        );
    }
    
    /**
     * Catch $request when it is created
     * 
     * @param Irto\OAuth2Proxy\ProxyRequest  $request
     * @param Closure $next
     * 
     * @return Irto\OAuth2Proxy\ProxyResponse
     */
    public function request($request, Closure $next) 
    {
        if ($request->originRequest()->getPath() == $this->server['config']->get('grant_path')) {
            $this->proxyContent($request);
            $request->headers()->put('Content-Length', $this->getContentLength($request));
        } else {
            $session = $request->session();
            if ($auth = $session->get('_oauth_grant', false)) {
                $request->headers()->put('Authorization', "{$auth['token_type']} {$auth['access_token']}");
            }
        }

        return $next($request);
    }

    /**
     * Catch $response on get it from api server.
     * 
     * @param Irto\OAuth2Proxy\ProxyResponse $response
     * @param Closure $next
     * 
     * @return React\HttpClient\Response
     */
    public function response($response, Closure $next) 
    {
        if ($response->originRequest()->originRequest()->getPath() == $this->server['config']->get('grant_path')) {
            $original = $response->originResponse();

            if ($original->getCode() == 200) {
                $response->setCode(204);
            }

            $original->removeAllListeners('data');
            $original->on('data', function ($data) use ($response, $original) {
                $this->bufferData($data);

                if ($response->dataLength() === (int) $response->headers()->get('Content-Length', false)) {
                    $original->close();
                }
            });

            $original->on('end', function () use ($response) {
                $response->end();
                $this->processResponse($response, json_decode($this->buffer, true));
            });
        }

        return $next($response);
    }

    /**
     * 
     * @param Irto\OAuth2Proxy\ProxyResponse $response
     * @param array $data
     * 
     * @return self
     */
    protected function processResponse($response, $data)
    {
        $session = $response->originRequest()->session();
        $session->put('_oauth_grant', $data);

        return $this;
    }
}