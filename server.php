<?php
include "vendor/autoload.php";
error_reporting(E_ALL);
use Inbenta\LineConnector\LineConnector;

//Instance new LineConnector
$appPath=__DIR__.'/';
$app = new LineConnector($appPath);

$handle = $app->handleRequest();
