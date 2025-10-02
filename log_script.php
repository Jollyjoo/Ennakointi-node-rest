<!-- 
Laitetaan palvelimelle crontab pyörimään ajatettu kerran tunnissa

crontab -e
0 9 * * * php /var/www/html/log_script.php

Muista Vi -komennot 
(i=insert)
Esc + : -> wq	Write and quit (save and exit).
Esc + : -> q!	Quit without saving changes i.e. discard changes.

katsele logeja
tail -f crone_test.log
-->

<?php
// Define the log file path
$logFile = 'crone_test.log';

// Get the current timestamp
$timestamp = date('Y-m-d H:i:s');

// Create the log message
$logMessage = "Hello, World! - $timestamp\n";

// Write the log message to the file
file_put_contents($logFile, $logMessage, FILE_APPEND);

echo "Log entry added successfully.";
?>