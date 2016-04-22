<?php

require 'vendor/autoload.php';
$bower_finder = new BowerFinder;

var_dump($bower_finder->getDependentFilesForComponents('bootstrap,bootstrap-toggle,bootstrap-daterangepicker'));
