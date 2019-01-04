<?php
declare(strict_types=1);

$basePath = __DIR__ . '/../';

require $basePath . 'vendor/autoload.php';

/* ----------- Nette tester -------------- */
if (!class_exists('Tester\Assert')) {
	echo "Install Nette Tester using `composer update --dev`\n";
	exit(1);
}

Tester\Environment::setup();
/* ----------- Nette tester -------------- */
