<?php

/*
 * Entry point for new courses.  A course's own index.php contains:
 *
 *     <?php require __DIR__ . '/calendar/index.php';
 */

require_once __DIR__ . '/src/autoload.php';
\Cal5\App::fromEntry((string) getcwd(), __DIR__)->run();
