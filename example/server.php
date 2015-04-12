<?php

require '../vendor/autoload.php';

$proxy = Irto\OAuth2Proxy\Server::create(array(
    /**
     * About OAuth2 Server API
     */
    'api_url' => 'http://api.webdomain.dev',
    'client_id' => 'd6d2b510d18471d2e22aa202216e86c42beac80f9a6ac2da505dcb79c7b2fd99',
    'client_secret' => 'd6d2b510d18471d2e22aa202216e86c42beac80f9a6ac2da505dcb79c7b2fd99',
    'grant_type' => 'webapp',
    'grant_path' => '/auth/token',
    'revoke_path' => '/auth/revoke',

    /**
     * About server
     */
    'port' => 8080,
    'verbose' => false,

    /**
     * Session Configurations
     * 
     * See more about in Illuminate (from Laravel) Session.
     */
    'session' => [
        'driver' => 'file',
        'folder' => '../storage/sessions/',
        'name' => 'tests',
        'path' => '/',
        'domain' => 'webdomain.dev',
        'lifetime' => 3600,
    ]
        
));

$proxy->run();