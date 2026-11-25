<?php

declare(strict_types=1);

// Filesystem locations shared by configuration and code.

define('BASEDIR', realpath(dirname(__DIR__)));
define('CONFDIR', BASEDIR . '/config');
define('STATEDIR', BASEDIR . '/var/state');
define('IMAGE_DIR', BASEDIR . '/public/images');
define('UPLOAD_DIR', BASEDIR . '/public/uploads');
