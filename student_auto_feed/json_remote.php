#!/usr/bin/env php
<?php

require "config.php";
new json_remote();

/** class to retrieve json files from remote server and convert data to single CSV */
class json_remote {
    private static $ssh2_conn   = null;
    private static $hostname    = JSON_REMOTE_HOSTNAME;
    private static $port        = JSON_REMOTE_PORT;
    private static $fingerprint = JSON_REMOTE_FINGERPRINT;
    private static $username    = JSON_REMOTE_USERNAME;
    private static $password    = JSON_REMOTE_PASSWORD;
    private static $remote_path = JSON_REMOTE_PATH;
    private static $local_path  = JSON_LOCAL_PATH;

    public function __construct() {

    }

    public function __destruct() {
        $this->remote_disconnect();
    }

    private function remote_connect() {
        //Ensure any existing connection is elegantly closed.
        $this->remote_disconnect();

        //Connect.
        self::$ssh2_conn = ssh2_connect(self::$hostname, self::$port);

        //Validate connection.
        if (!$this->validate_remote_connect()) {
            fprintf(STDERR, "Cannot connect to remote server %s\n", self::hostname);
            return false;
        }

        //Validate fingerprint to prevent communicating with an imposter server.
        $remote_fingerprint = ssh2_fingerprint(self::$ssh2_conn, SSH2_FINGERPRINT_SHA1 | SSH2_FINGERPRINT_HEX);
        if ($remote_fingerprint !== self::$fingerprint) {
            fprintf(STDERR, "Remote server fingerprint does not match.\nExpected: %s\nActual: %s\n", self::$fingerprint, $remote_fingerprint);
            $this->remote_disconnect();
            return false;
        }

        //Authenticate session.
        if (ssh2_auth_password(self::$ssh2_conn, self::$username, self::$password) === false) {
            fprintf(STDERR, "Could not authenticate %s@%s.\n", self::$username, self::$hostname);
            return false;
        }

        //Connection established, server verified, and session authenticated.
        return true;
    }

    private function validate_remote_connect() {
        if (is_resource(self::$ssh2_conn) && get_resource_type(self::$ssh_conn) === "SSH2 Session") {
            return true;
        }

        return false;
    }

    private function remote_disconnect() {
        if ($this->validate_remote_connect()) {
            ssh2_disconnect(self::$ssh2_conn);
        }
    }

    /**
     * Secure copy JSON data files from remote server.
     *
     * @return boolean true on success.
     */
    private function retrieve_json_files() {
        if (!$this->validate_remote_connect()) {
            return false;
        }

        //Get a list of files to secure-copy.
        $command = sprintf("/bin/ls %s", self::$remote_path);
        $ssh2_stream = ssh2_exec(self::$ssh2_conn, $command);
        stream_set_blocking($ssh2_stream, true);
        $files = stream_get_contents($ssh2_stream);
        $files = preg_split("~\r\n|\n|\r~", $files);
        $files = array_filter($files, function($file) {
            return (!empty($file));
        });

        //secure-copy files from list.
        $is_success = true; //assumed unless proven otherwise.
        foreach ($files as $file) {
            $remote_file = self::$remote_path . $file;
            $local_file  = self::$local_path  . $file;
            if (ssh2_scp_recv($ssh2_conn, $remote_file, $local_file) === false) {
                fprintf(STDERR, "Failed to scp %s to %s\n%s", $remote_file, $local_file, error_get_last());
                $is_success = false;
            }
        }

        return $is_success;
    }

    private function build_csv() {

    }
}

//EOF
?>
