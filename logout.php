<?php
require __DIR__ . '/includes/bootstrap.php';

// Log keluar hanya melalui POST dengan token CSRF (elak log keluar paksa melalui pautan)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(current_user() ? 'index.php' : 'login.php');
}
csrf_check();
app_log('Log keluar', 'auth.log');
tamatkan_sesi();
header('Location: login.php');
