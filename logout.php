<?php
session_start();
$was_whm = isset($_SESSION['role']) && $_SESSION['role'] === 'whm';
session_destroy();
session_start();
session_unset();
session_regenerate_id(true);
session_write_close();
header('Location: ' . ($was_whm ? '/whm/login.php' : '/login.php'));
exit;
