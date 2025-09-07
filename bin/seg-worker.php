#!/usr/bin/env php
<?php
declare(strict_types=1);

putenv('SKIP_ROUTE_LOAD=1');  // <-- important
require __DIR__ . '/../vendor/autoload.php';

/** @var object $wrap */
$wrap = require __DIR__ . '/../bootstrap.php';
/** @var Psr\Container\ContainerInterface $c */
$c = $wrap->getContainer();

/** @var App\Service\SegmentBuildOrchestrator $orc */
$orc = $c->get(App\Service\SegmentBuildOrchestrator::class);

error_log('[SEG][WORKER] starting runForever()');
$orc->runForever();