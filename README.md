# install
```
composer require salamander/quick
```

# quick start
```
<?php
define("ROOT", __DIR__ . '/..');
define('APP', ROOT . '/app');


require ROOT . '/vendor/autoload.php';

$container = [
	'settings' => [
		'host' => '0.0.0.0',
		'port' => 8888
	]
];
$app = new \Quick\App($container);
$app->get('/', '\App\Controller\IndexController:show');

$app->start();
```