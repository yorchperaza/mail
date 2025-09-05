#!/usr/bin/env php
<?php
declare(strict_types=1);

use App\Service\SegmentBuildOrchestrator;

$container = require dirname(__DIR__).'/bootstrap.php';

$orchestrator = $container->get(SegmentBuildOrchestrator::class);
$orchestrator->runForever();
