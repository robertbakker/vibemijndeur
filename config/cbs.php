<?php

declare(strict_types=1);

return [
    /*
     | The CBS "gebiedsindelingen" archive (one GeoPackage per year inside a zip).
     | https://www.cbs.nl/nl-nl/dossier/nederland-regionaal/geografische-data/cbs-gebiedsindelingen
     */
    'archive_url' => env(
        'CBS_ARCHIVE_URL',
        'https://geodata.cbs.nl/files/Gebiedsindelingen/cbsgebiedsindelingen2016_heden.zip',
    ),

    /*
     | Latest year whose GeoPackage carries all five levels (incl. wijk/buurt).
     | 2025 is absent from the archive and 2026 is provisional without wijk/buurt.
     */
    'default_year' => (int) env('CBS_YEAR', 2024),

    // `ogr2ogr` (GDAL) binary used to load the GeoPackage layers into PostGIS.
    'ogr2ogr' => env('OGR2OGR_BIN', 'ogr2ogr'),
];
