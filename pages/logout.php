<?php
require __DIR__ . '/../functions/helpers.php';

session_unset();
session_destroy();
redirect('../pages/login.php');
