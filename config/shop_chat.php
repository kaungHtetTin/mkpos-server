<?php

return [
    'enabled' => env('SHOP_CHAT_ENABLED', true),
    'realtime_enabled' => env('SHOP_CHAT_REALTIME_ENABLED', false),
    'secret' => env('SHOP_CHAT_APP_SECRET'),
    // Backend publishing can use an internal hostname; browsers use the public host.
    'publish_host' => env('SHOP_CHAT_PUBLISH_HOST', env('SHOP_CHAT_SOCKET_HOST', '127.0.0.1')),
    'publish_port' => env('SHOP_CHAT_PUBLISH_PORT', env('SHOP_CHAT_SOCKET_PORT', 8080)),
    'publish_scheme' => env('SHOP_CHAT_PUBLISH_SCHEME', env('SHOP_CHAT_SOCKET_SCHEME', 'http')),
    'host' => env('SHOP_CHAT_SOCKET_HOST', '127.0.0.1'),
    'port' => env('SHOP_CHAT_SOCKET_PORT', 8080),
    'path' => env('SHOP_CHAT_SOCKET_PATH', '/socket.io'),
    'scheme' => env('SHOP_CHAT_SOCKET_SCHEME', 'http'),
];
