<?php

require '../vendor/autoload.php';

$proxy = Irto\OAuth2Proxy\Server::create([
    'api_url' => 'http://api.gamergrade.dev',
    'port' => 8080,
    'client_id' => 'd6d2b510d18471d2e22aa202216e86c42beac80f9a6ac2da505dcb79c7b2fd99',
    'client_secret' => 'd6d2b510d18471d2e22aa202216e86c42beac80f9a6ac2da505dcb79c7b2fd99',
    'grant_path' => '/auth/token',
    'revoke_path' => '/auth/revoke',
    'grant_type' => 'gamergrade_webapp'
]);

$proxy->run();