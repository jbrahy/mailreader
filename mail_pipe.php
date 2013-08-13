 #!/usr/bin/php -q
<?php
//  Use -q so that php doesn't print out the HTTP headers

/*
 * mailPipe.php
 *
 * This script is a sample of how to use mailReader.php as a mail pipe to save 
 * emailed attachments and emails into a directory and/or database
 *
 * Test it by running
 *
 * cat mail.raw | ./mailPipe.php
 *
 * Support: 
 * http://stuporglue.org/mailreader-php-parse-e-mail-and-save-attachments-php-version-2/
 *
 * Code:
 * https://github.com/stuporglue/mailreader
 *
 * See the README.md for the license, and other information
 */


// Set a long timeout in case we're dealing with big files
set_time_limit(600);
ini_set('max_execution_time', 600);

// Anything printed to STDOUT will be sent back to the sender as an error!
// error_reporting(-1);
// ini_set("display_errors", 1);


// Require the file with the mailReader class in it
require_once('mail_reader.php');

// Where should discovered files go
$save_directory = __DIR__; // stick them in the current directory

// Configure your MySQL database connection here
// Other PDO connections will probably work too
$db_hostname = '127.0.0.1';
$db_username = 'db_username';
$db_password = 'db_password';
$db_database = 'db_database';

$pdo = new PDO("mysql:host=$db_hostname;dbname=$db_database;charset=utf8", $db_username, $db_password);

// Who can send files to through this script?
$allowed_senders = Array( 'my_email@example.com', 'whatever@example.com' );

$mail_reader = new mail_reader($save_directory, $allowed_senders, $pdo);

$mail_reader->save_msg_to_db = TRUE;
$mail_reader->send_email = TRUE;
// Example of how to add additional allowed mime types to the list
// $mail_reader->allowed_mime_types[] = 'text/csv';
$mail_reader->read_email();
