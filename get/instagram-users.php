<?php

/**
 * Returns JSON that provides metadata for the cached user profiles
 *
 * Museum Now is a brain-child of the Museum of Contemporary Art Australia,
 * made with much love by Tim Wray and is based on the work of MCA Now,
 * a product produced by Rory McKay.
 *
 * Contact: timwray.mail@gmail.com; rorymckay@gmail.com
 *
 */

header('Content-type: application/json');
require_once(realpath(dirname(__FILE__).'/../core/core.php'));

echo file_get_contents(INSTAGRAM_USERS_METADATA_FILE);

?>