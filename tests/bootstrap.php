<?php
define('IS_PHPUNIT', true);
session_start();
$_SESSION = []; // Sesión limpia

// Forzamos base de datos en memoria para los tests
$DB_PATH = ':memory:';
$ADMIN_NAME = 'admin';
$ADMIN_PASS = '12345';
$URL_LIBRERIA = 'http://localhost/';

// Incluimos la librería (el código CLI preventivo evitará que se ejecute el routing)
require_once __DIR__ . '/../ena_login.php';

// Inicializar la base de datos en memoria
install_database($DB_PATH);
