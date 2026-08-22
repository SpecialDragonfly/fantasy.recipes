<?php

declare(strict_types=1);

use Slim\App;

return function (App $app): void {
    (require __DIR__ . '/Routes/public.php')($app);
    (require __DIR__ . '/Routes/auth.php')($app);
    (require __DIR__ . '/Routes/password_reset.php')($app);
    (require __DIR__ . '/Routes/user.php')($app);
    (require __DIR__ . '/Routes/admin.php')($app);
};
