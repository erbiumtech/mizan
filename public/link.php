<?php
$target = __DIR__ . '/httpdocs/storage/app/public';
$link   = __DIR__ . '/httpdocs/public/storage';
if (symlink($target, $link)) {
    echo "Symlink created.";
} else {
    echo "Failed: " . error_get_last()['message'];
}
