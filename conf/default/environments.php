<?php

/* Environments configuration.

* Place your regexes to check if the application is running in each environment
* By default, production environment is assumed. If any regex matches the conditions the environment will be changed.
* You can filter by HTTP_HOST. Examples:

    'development'   => [
        'type'  => 'http_host',
        'regex' => '/^dev.mydomain.com$/m',    // This will set 'development' environment when the HTTP_HOST is 'dev.mydomain.com'
    ],
*/
return array(
    'development'   => [
        'type'  => '',  // http_host
        'regex' => '',
    ],
    'preproduction' => [
        'type'  => '',  // http_host
        'regex' => '',
    ]
);
