<?php

declare(strict_types=1);

return [

    /*
    | The Manticore index (table) name. Connection details (mysql host/port on
    | 9306) live in the package's config/manticore.php, driven by MANTICORE_*.
    */
    'manticore_index' => env('MANTICORE_INDEX', 'roadworks'),

];
