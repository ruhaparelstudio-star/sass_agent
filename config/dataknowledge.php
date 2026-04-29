<?php

return [
    'assets' => [
        'disk' => env('DK_ASSET_DISK', env('FILESYSTEM_DISK', 'local')),
        'pricelist_dir' => env('DK_PRICELIST_DIR', 'tenant-assets/pricelist'),
        'invoice_dir' => env('DK_INVOICE_DIR', 'tenant-assets/invoice'),
    ],
];
