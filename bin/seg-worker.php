#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

/** @var Psr\Container\ContainerInterface $c */
$c = (require __DIR__.'/../bootstrap.php')->getContainer();

/** @var App\Service\SegmentBuildOrchestrator $orc */
$orc = $c->get(App\Service\SegmentBuildOrchestrator::class);

error_log('[SEG][WORKER] starting runForever()');
$orc->runForever();
