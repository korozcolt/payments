<?php

declare(strict_types=1);

// Minimal CodeIgniter bootstrap: constants + autoloader so framework classes behave outside an app.
define('SYSTEMPATH', __DIR__.'/../vendor/codeigniter4/framework/system/');
define('APPPATH', __DIR__.'/../vendor/codeigniter4/framework/app/');
define('ROOTPATH', __DIR__.'/../');
define('WRITEPATH', sys_get_temp_dir().'/payments-ci4-tests/');
define('FCPATH', __DIR__.'/');
define('EXIT_SUCCESS', 0);
@mkdir(WRITEPATH, 0777, true);

require SYSTEMPATH.'Test/bootstrap.php';
