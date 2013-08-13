<?php

require_once('mimeDecode.php');

/*
 * @class mailReader.php
 *
 * @brief Receive mail and attachments with PHP
 *
 * Support: 
 * http://stuporglue.org/mailreader-php-parse-e-mail-and-save-attachments-php-version-2/
 *
 * Code:
 * https://github.com/stuporglue/mailreader
 *
 * See the README.md for the license, and other information
 */
class mail_reader {

    var $saved_files = Array();
    var $send_email = FALSE; // Send confirmation e-mail back to sender?
    var $save_msg_to_db = FALSE; // Save e-mail message and file list to DB?
    var $save_directory; // A safe place for files. Malicious users could upload a php or executable file, so keep this out of your web root
    var $allowed_senders = Array(); // Allowed senders is just the email part of the sender (no name part)
    var $allowed_mime_types = Array(
        'audio/wave', 'application/pdf', 'application/zip', 'application/octet-stream', 'image/jpeg', 'image/png', 'image/gif',
    );
    var $debug = FALSE;
    var $raw = '';
    var $decoded;
    var $from;
    var $subject;
    var $body;

    /**
     * @brief Receive mail and attachments with PHP
     *
     * @param $save_directory  (required) A path to a directory where files will be saved
     * @param $allowed_senders (required) An array of email addresses allowed to send through this script
     * @param $pdo             (optional) A PDO connection to a database for saving emails
     *
     * @return nothing
     */
    public function __construct( $save_directory, $allowed_senders, $pdo = NULL ) {

        if ( !preg_match('|/$|', $save_directory) ) {
            $save_directory .= '/';
        } // add trailing slash if needed

        $this->save_directory = $save_directory;
        $this->allowed_senders = $allowed_senders;
        $this->pdo = $pdo;
    }

    /**
     * @brief Read an email message
     *
     * @param $src (optional) Which file to read the email from. Default is php://stdin for use as a pipe email handler
     *
     * @return An associative array of files saved. The key is the file name, the value is an associative array with size and mime type as keys.
     */
    public function read_email( $src = 'php://stdin' ) {

        // Process the e-mail from stdin
        $fd = fopen($src, 'r');

        while ( !feof($fd) ) {
            $this->raw .= fread($fd, 1024);
        }

        // Now decode it!
        // http://pear.php.net/manual/en/package.mail.mail-mimedecode.decode.php
        $decoder = new Mail_mimeDecode($this->raw);
        $this->decoded = $decoder->decode(Array(
                                               'decode_headers' => TRUE, 'include_bodies' => TRUE, 'decode_bodies' => TRUE,
                                          ));

        // Set $this->from_email and check if it's allowed
        $this->from = $this->decoded->headers['from'];
        $this->from_email = preg_replace('/.*<(.*)>.*/', "$1", $this->from);

        if ( !in_array($this->from_email, $this->allowed_senders) ) {
            die("$this->from_email not an allowed sender");
        }

        // Set the $this->subject
        $this->subject = $this->decoded->headers['subject'];

        // Find the email body, and any attachments
        // $body_part->ctype_primary and $body_part->ctype_secondary make up the mime type eg. text/plain or text/html
        if ( isset($this->decoded->parts) && is_array($this->decoded->parts) ) {
            foreach ( $this->decoded->parts as $idx => $body_part ) {
                $this->decode_part($body_part);
            }
        }

        if ( isset($this->decoded->disposition) && $this->decoded->disposition == 'inline' ) {
            $mimeType = "{$this->decoded->ctype_primary}/{$this->decoded->ctype_secondary}";

            if ( isset($this->decoded->d_parameters) && array_key_exists('file_name', $this->decoded->d_parameters) ) {
                $file_name = $this->decoded->d_parameters['file_name'];
            } else {
                $file_name = 'file';
            }

            $this->save_file($file_name, $this->decoded->body, $mimeType);
            $this->body = "Body was a binary";
        }

        // We might also have uuencoded files. Check for those.
        if ( !isset($this->body) ) {
            if ( isset($this->decoded->body) ) {
                $this->body = $this->decoded->body;
            } else {
                $this->body = "No plain text body found";
            }
        }

        if ( preg_match("/begin ([0-7]{3}) (.+)\r?\n(.+)\r?\nend/Us", $this->body) > 0 ) {

            foreach ( $decoder->uudecode($this->body) as $file ) {
                $this->save_file($file['file_name'], $file['file_data']);
            }

            // Strip out all the uuencoded attachments from the body
            while ( preg_match("/begin ([0-7]{3}) (.+)\r?\n(.+)\r?\nend/Us", $this->body) > 0 ) {
                $this->body = preg_replace("/begin ([0-7]{3}) (.+)\r?\n(.+)\r?\nend/Us", "\n", $this->body);
            }
        }

        // Put the results in the database if needed
        if ( $this->save_msg_to_db && !is_null($this->pdo) ) {
            $this->save_to_db();
        }

        // Send response e-mail if needed
        if ( $this->send_email && $this->from_email != "" ) {
            $this->send_email();
        }

        // Print messages
        if ( $this->debug ) {
            $this->debug_message();
        }

        return $this->saved_files;
    }

    /**
     * @brief Decode a single body part of an email message
     *
     * @note  Recursive if nested body parts are found
     *
     * @note  This is the meat of the script.
     *
     * @param $body_part (required) The body part of the email message, as parsed by Mail_mimeDecode
     */
    private function decode_part( $body_part ) {

        if ( array_key_exists('name', $body_part->ctype_parameters) ) { // everyone else I've tried
            $file_name = $body_part->ctype_parameters['name'];
        } else {

            if ( $body_part->ctype_parameters && array_key_exists('file_name', $body_part->ctype_parameters) ) { // hotmail
                $file_name = $body_part->ctype_parameters['file_name'];
            } else {
                $file_name = "file";
            }
        }

        $mime_type = "{$body_part->ctype_primary}/{$body_part->ctype_secondary}";

        if ( $this->debug ) {
            print "Found body part type $mime_type\n";
        }

        if ( $body_part->ctype_primary == 'multipart' ) {

            if ( is_array($body_part->parts) ) {

                foreach ( $body_part->parts as $sub_part_key => $sub_part_value ) {
                    $this->decode_part($sub_part_value);
                }
            }
        } else {

            if ( $mime_type == 'text/plain' ) {

                if ( !isset($body_part->disposition) ) {
                    $this->body .= $body_part->body . "\n"; // Gather all plain/text which doesn't have an inline or attachment disposition
                }
            } else {

                if ( in_array($mime_type, $this->allowed_mime_types) ) {
                    $this->save_file($file_name, $body_part->body, $mime_type);
                }
            }
        }
    }

    /**
     * @brief Save off a single file
     *
     * @param $file_name  (required) The file_name to use for this file
     * @param $contents   (required) The contents of the file we will save
     * @param $mime_type  (required) The mime-type of the file
     */
    private function save_file( $file_name, $contents, $mime_type = 'unknown' ) {

        $file_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $file_name);
        $unlocked_and_unique = FALSE;

        while ( !$unlocked_and_unique ) {
            // Find unique
            $name = time() . "_" . $file_name;

            while ( file_exists($this->save_directory . $name) ) {
                $name = time() . "_" . $file_name;
            }

            // Attempt to lock
            $outfile = fopen($this->save_directory . $name, 'w');

            if ( flock($outfile, LOCK_EX) ) {
                $unlocked_and_unique = TRUE;
            } else {
                flock($outfile, LOCK_UN);
                fclose($outfile);
            }
        }

        fwrite($outfile, $contents);
        fclose($outfile);

        // This is for readability for the return e-mail and in the DB
        $this->saved_files[$name] = Array(
            'size' => $this->format_bytes(filesize($this->save_directory . $name)), 'mime' => $mime_type
        );
    }

    /**
     * @brief Format Bytes into human-friendly sizes
     *
     * @return A string with the number of bytes in the largest applicable unit (eg. KB, MB, GB, TB)
     */
    private function format_bytes( $bytes, $precision = 2 ) {

        $units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * @brief Save the plain text, subject and sender of an email to the database
     */
    private function save_to_db() {

        $insert = $this->pdo->prepare("INSERT INTO emails (from_address,subject,body) VALUES (?,?,?)");

        // Replace non UTF-8 characters with their UTF-8 equivalent, or drop them
        if ( !$insert->execute(Array(
                                    mb_convert_encoding($this->from_email, 'UTF-8', 'UTF-8'), mb_convert_encoding($this->subject, 'UTF-8', 'UTF-8'), mb_convert_encoding($this->body, 'UTF-8', 'UTF-8')
                               ))
        ) {
            if ( $this->debug ) {
                print_r($insert->errorInfo());
            }
            die("INSERT INTO emails failed!");
        }

        $email_id = $this->pdo->lastInsertId();

        foreach ( $this->saved_files as $file => $data ) {
            $insertFile = $this->pdo->prepare("INSERT INTO files (email_id,file_name,mail_size,mime) VALUES (:email_id,:file_name,:size,:mime)");
            $insertFile->bindParam(':email_id', $email_id);
            $insertFile->bindParam(':file_name', mb_convert_encoding($file, 'UTF-8', 'UTF-8'));
            $insertFile->bindParam(':size', $data['size']);
            $insertFile->bindParam(':mime', $data['mime']);

            if ( !$insertFile->execute() ) {

                if ( $this->debug ) {
                    print_r($insertFile->errorInfo());
                }

                die("Insert file info failed!");
            }
        }
    }

    /**
     * @brief Send the sender a response email with a summary of what was saved
     */
    private function send_email() {

        $message = "Thanks! I just uploaded the following ";
        $message .= "files to your storage:\n\n";
        $message .= "file_name -- Size\n";

        foreach ( $this->saved_files as $file_name => $content ) {
            $message .= "$file_name -- ({$content['size']}) of type {$content['mime']}\n";
        }

        $message .= "\nI hope everything looks right. If not,";
        $message .= "please send me an e-mail!\n";

        mail($this->from_email, $this->subject, $message);
    }

    /**
     * @brief Print a summary of the most recent email read
     */
    private function debug_message() {

        print "From : $this->from_email\n";
        print "Subject : $this->subject\n";
        print "Body : $this->body\n";
        print "Saved Files : \n";
        print_r($this->saved_files);
    }
}
