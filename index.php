<?php
/**
 * Root Index - Redirect to Admin Portal
 */
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet', true);
header("Location: admin/login.php");
exit;
