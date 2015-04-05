# Under Development

# oauth2-proxy
A PHP Proxy for OAuth2 APIs to create safe Web Apps.

This project will help create Web App over OAuth2 API, working how a proxy (or translator) using CSRF and Session for front-end and translating in OAuth2 to back-end API. Project is working over awesome [ReactPHP](https://github.com/reactphp) libs and [Illuminate](https://github.com/laravel/framework) (from Laravel) components.

Example ```example\server.php```:
```php
<?php

require '../vendor/autoload.php';

$proxy = Irto\OAuth2Proxy\Server::create(array(
    'api_url' => 'http://api.web.domain',
    'port' => 8080,
    'client_id' => 'e22aa202216e86c42beac80f9a6ac2da505dc',
    'client_secret' => '471d2e22aa202216e86c42beac80f9a6ac2da5',
    'grant_path' => '/auth/token',
    'revoke_path' => '/auth/revoke',
    'grant_type' => 'client_grant',

    'session' => [
        'driver' => 'file',
        'folder' => '../storage/sessions/',
        'name' => 'tests',
        'path' => '/',
        'domain' => 'web.domain',
        'lifetime' => 3600,
    ]
        
));

$proxy->run();

?>
```

For run it's easy...

```
    $ php ./server.php
```

...or in background with nohup

```
    $ nohup php ./server.php &
```

# TODO

* Unit Tests
* OAuth2 authorization flow
* Friendly configuration
* Documentation (my english is to bad .-.) 