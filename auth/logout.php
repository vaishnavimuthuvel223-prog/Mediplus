<?php
require_once __DIR__ . '/../includes/functions.php';

$_SESSION = [];
session_destroy();
redirect('/medi/auth/patient_login.php');

