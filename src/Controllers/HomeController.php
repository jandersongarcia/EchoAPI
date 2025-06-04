<?php

namespace App\Controllers;

use Medoo\Medoo;
use Monolog\Logger;

class HomeController
{
    private $db;
    private $logger;

    public function __construct(Medoo $db, Logger $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    public function index()
    {
        echo "API funcionando!";
    }
}
