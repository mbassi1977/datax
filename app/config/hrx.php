<?php

return [
    // true = los correos van a la cola (requiere `php artisan queue:work`); false = se envían tras responder la solicitud.
    'mail_queue' => (bool) env('HRX_MAIL_QUEUE', false),
];
