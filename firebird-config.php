<?php
/**
 * FireBird private server configuration for Frame.io / Adobe OAuth.
 *
 * IMPORTANT:
 * - Replace the placeholder values below with the values from Adobe Developer Console.
 * - Keep this file OUTSIDE Git. Add firebird-config.php to .gitignore.
 * - Never paste the Client Secret into gbirds.php or commit it to GitHub.
 * - On Hostinger, chmod this file to 600 when supported.
 *
 * This file is loaded automatically by gbirds.php when it exists alongside it.
 */

define('FIREBIRD_FRAMEIO_CLIENT_ID', '175eb39cbc7241deb96d16dc07f2673b');
define('FIREBIRD_FRAMEIO_CLIENT_SECRET', 'p8e-aCzx1McEJ4AdpMbaTll_GPx_iwTsEnf3');

define(
    'FIREBIRD_FRAMEIO_REDIRECT_URI',
    'https://hh5hh.com/gbirds.php?api=frameio-callback'
);

define(
    'FIREBIRD_FRAMEIO_SCOPES',
    'openid email profile offline_access additional_info.roles'
);

define(
    'FIREBIRD_FRAMEIO_PROJECT_ID',
    'f7f67254-9ec8-4e2c-99f8-32cd31123eef'
);

// Upload target collection inside the project. Resolved at runtime by id first,
// then by name. Leave COLLECTION_ID empty to match the "Project_FIREBIRD"
// collection by name (robust against the collection id changing).
define('FIREBIRD_FRAMEIO_COLLECTION_ID', '');
define('FIREBIRD_FRAMEIO_COLLECTION_NAME', 'Project_FIREBIRD');

// Explicit upload base folder — the "BIRDS" folder inside Assets. Takes priority
// over the collection. Date subfolders (Y-m-d) are created under it. Leave empty
// to fall back to the collection / project root.
define('FIREBIRD_FRAMEIO_FOLDER_ID', 'a80bef5f-0ec5-4074-b68e-6e70c5f1670f');
