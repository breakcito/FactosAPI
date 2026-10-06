<?php

return [
    'secret' => env('JWT_SECRET', env('APP_KEY')),
    'algo' => 'HS256',
];
