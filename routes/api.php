<?php

/*
| Every route here is prefixed with /api/v1 and uses the "api" middleware group (bootstrap/app.php).
| Each module keeps its own routes in Modules/<Module>/routes/api.php.
*/
foreach (glob(base_path('Modules/*/routes/api.php')) as $moduleRoutes) {
    require $moduleRoutes;
}
