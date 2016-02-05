<?php
namespace Irto\OAuth2Proxy\Middleware;

use Closure;
use React\HttpClient\Client;
use Irto\OAuth2Proxy\Server;

class Authorization {

    /**
     * @var Irto\OAuth2Proxy\Server
     */
    protected $server = null;

    /**
     * @var React\HttpClient\Client
     */
    protected $client = null;

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
    public function __construct(Server $server, Client $client)
    {
        $this->server = $server;
        $this->client = $client;
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

        $data = $request->getBufferClean();
        $this->bufferData($data);

        $original->removeAllListeners('data');
        if ($this->bufferLength() == (int) $request->headers()->get('content-length')) {
            $request->write($this->getDataEnd(true));
        } else {
            $original->on('data', function ($data) use ($request, $original) {
                $this->bufferData($data);

                if ($this->bufferLength() == (int) $request->headers()->get('content-length')) {
                    $request->write($this->getDataEnd(true));
                }
            });
        }

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
        $length = (int) $request->headers()->get('content-length');

        return $length + strlen(json_encode($this->getOAuthCredentials())) - 1;
    }

    /**
     * Return data to send to API with credentials merged with front-end data
     * 
     * @return string
     */
    protected function getDataEnd($mergeCredentials = false)
    {
        $data = json_decode($this->buffer, true);
        $this->buffer = null;

        if ($mergeCredentials && !empty($data) && $data) {
            $data += $this->getOAuthCredentials();
            return json_encode($data, JSON_UNESCAPED_SLASHES);
        }

        return json_encode($data, JSON_UNESCAPED_SLASHES);
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
            $request->headers()->put('content-length', $this->getContentLength($request));
        } else {
            $session = $request->session();

            if ($credentials = $session->get('oauth_grant', false)) {
                if ($request->originRequest()->getPath() == $this->server['config']->get('revoke_path')) {
                    $request->query()->put('token', $session->get('oauth_grant.access_token', false));
                }
            } else {
                $credentials = $this->server->getClientCredentials();
            }

            $request->headers()->put('authorization', "{$credentials['token_type']} {$credentials['access_token']}");
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
        $request = $response->originRequest();
        $original = $response->clientResponse();

        if ($request->originRequest()->getPath() == $this->server['config']->get('grant_path') && $original->getCode() == 200) {
            $response->setCode(204);

            $original->removeAllListeners('data');
            $original->on('data', function ($data) use ($response, $original) {
                $this->bufferData($data);

                if ($response->dataLength() === (int) $response->headers()->get('content-length', -1)) {
                    $original->close();
                }
            });

            $original->on('end', function () use ($response) {
                $response->end();
                $this->processResponse($response, json_decode($this->getDataEnd(), true));
            });
        }

        $session = $request->session();

        if ($original->getCode() == 401 && $session->has('oauth_grant.refresh_token')) {
            $original->removeAllListeners('data');
            $this->updateCredentials($response, function ($clientResponse) use ($response, $request, $next, $session) {

                $request->removeAllListeners('response');
                $request->on('response', function ($original) use ($next, $response) {
                    $response->mergeClientResponse($original);

                    $next($response);
                });

                $credentials = $session->get('oauth_grant');
                $request->headers()->put('authorization', "{$credentials['token_type']} {$credentials['access_token']}");

                $request->retry();
            });

            return false;
        }

        return $next($response);
    }

    /**
     * 
     * 
     */
    protected function updateCredentials($response, $callback)
    {
        $session = $response->originRequest()->session();
        $url = $this->server->get('api_url') . $this->server['config']->get('grant_path');

        $data = json_encode(array(
            'client_id' => $this->server['config']->get('client_id'),
            'client_secret' => $this->server['config']->get('client_secret'),
            'refresh_token' => $session->get('oauth_grant.refresh_token'),
            'grant_type' => 'refresh_token'
        ));

        $request = $this->client->request('POST', $url, array(
            'content-type' => 'application/json;charset=UTF-8',
            'content-length' => strlen($data),
        ));

        $request->on('response', function ($clientResponse) use ($response, $request, $callback, $session) {
            if ($clientResponse->getCode() != 200) {
                $clientResponse->on('data', function ($data) use ($clientResponse, $request, $callback, $session) {
                    $session->forget('oauth_grant');
                    $this->server->log('Não foi possível autenticar o usuário utilizando o refresh token (%s).', [$data]);
                });

                return $callback($clientResponse);
            }

            $clientResponse->on('data', function ($data) { $this->bufferData($data); }); 

            $clientResponse->on('end', function ($data) use ($clientResponse, $response, $callback) {
                $data = $this->getDataEnd();
                $this->processResponse($response, json_decode($data, true));
                $callback($clientResponse);
            });
        });

        $request->end($data);
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
        $session->set('oauth_grant', $data);
        $session->save();

        return $this;
    }
}