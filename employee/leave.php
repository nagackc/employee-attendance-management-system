<?php
declare(strict_types=1);

$query = trim((string)($_SERVER['QUERY_STRING'] ?? ''));
header('Location: calendar.php' . ($query !== '' ? '?' . $query : ''), true, 302);
exit;
