#!/usr/bin/env php
<?php
/**
 * Helper script for retrieving CSV datasheets from IMAP email account messages.
 *
 * The student auto feed script is designed to read a CSV export of student
 * enrollment.  This helper script is intended to read the CSV export from an
 * IMAP email account message attachment and write the data to as a local file.
 * Requires PHP 7.0+ with imap library.
 *
 * @author Peter Bailie, Rensselaer Polytechnic Institute
 */
require "config.php";

new imap_remote();
exit(0);

/** Class to retrieve CSV datasheet from IMAP and write it to filesystem */
class imap_remote {

    /** @static @property resource */
    private static $imap_conn;

    /** @static @property resource */
    private static $csv_fh;

    /** @static @property boolean */
    private static $csv_locked = false;

    public function __construct() {
        switch(false) {
        case $this->imap_connect():
            exit(1);
        case $this->get_csv_data():
            exit(1);
        }
    }

    public function __destruct() {
        $this->close_csv();
        $this->imap_disconnect();
    }

    /**
     * Open connection to IMAP server.
     *
     * @access private
     * @return boolean true when connection established, false otherwise.
     */
    private function imap_connect() {
        //gracefully close any existing imap connections (shouldn't be any, but just in case...)
        $this->imap_disconnect();

        $hostname   = IMAP_HOSTNAME;
        $port       = IMAP_PORT;
        $username   = IMAP_USERNAME;
        $password   = IMAP_PASSWORD;
        $msg_folder = IMAP_FOLDER;
        $options    = "/" . implode("/", IMAP_OPTIONS);
        $auth = "{{$hostname}:{$port}{$options}}{$msg_folder}";

        self::$imap_conn = imap_open($auth, $username, $password, null, 3, array('DISABLE_AUTHENTICATOR' => 'GSSAPI'));

        if (is_resource(self::$imap_conn) && get_resource_type(self::$imap_conn) === "imap") {
            return true;
        } else {
            fprintf(STDERR, "Cannot connect to {$hostname}.\n%s\n", imap_last_error());
            return false;
        }
    }

    /**
     * Close connection to IMAP server.
     *
     * @access private
     */
    private function imap_disconnect() {
        if (is_resource(self::$imap_conn) && get_resource_type(self::$imap_conn) === "imap") {
            imap_close(self::$imap_conn);
        }
    }

    /**
     * Open/lock CSV file for writing.
     *
     * @access private
     * @return boolean true on success, false otherwise
     */
    private function open_csv() {
        //gracefully close any open file handles (shouldn't be any, but just in case...)
        $this->close_csv();

        //Open CSV for writing.
        self::$csv_fh = fopen(CSV_FILE, "w");
        if (!is_resource(self::$csv_fh) || get_resource_type(self::$csv_fh) !== "stream") {
            fprintf(STDERR, "Could not open CSV file for writing.\n%s\n", error_get_last());
            return false;
        }

        //Lock CSV file.
        if (flock(self::$csv_fh, LOCK_SH, $wouldblock)) {
            self::$csv_locked = true;
            return true;
        } else if ($wouldblock === 1) {
            fprintf(STDERR, "Another process has locked the CSV.\n%s\n", error_get_last());
            return false;
        } else {
            fprintf(STDERR, "CSV not blocked, but still could not attain lock for writing.\n%s\n", error_get_last());
            return false;
        }
    }

    /**
     * Close/Unlock CSV file from writing.
     *
     * @access private
     */
    private function close_csv() {
        //Unlock CSV file, if it is locked.
        if (self::$csv_locked && flock(self::$csv_fh, LOCK_UN)) {
            self::$csv_locked = false;
        }

        //Close CSV file, if it is open.
        if (is_resource(self::$csv_fh) && get_resource_type(self::$csv_fh) === "stream") {
            fclose(self::$csv_fh);
        }
    }

    /**
     * Get CSV attachment and write it to a file.
     *
     * @access private
     * @return boolean true on success, false otherwise.
     */
    private function get_csv_data() {
        $imap_from = IMAP_FROM;
        $imap_subject = IMAP_SUBJECT;
        $search_string = "UNSEEN FROM \"{$imap_from}\" SUBJECT \"{$imap_subject}\"";
        $email_id = imap_search(self::$imap_conn, $search_string);

        //Should only be one message to process.
        if (!is_array($email_id) || count($email_id) != 1) {
            fprintf(STDERR, "Expected one valid datasheet via IMAP mail.\nMessage IDs found (\"false\" means none):\n%s\n", var_export($email_id, true));
            return false;
        }

        //Open CSV for writing.
        if (!$this->open_csv()) {
            return false;
        }

        //Locate file attachment via email structure parts.
        $structure = imap_fetchstructure(self::$imap_conn, $email_id[0]);
        foreach($structure->parts as $part_index=>$part) {
            //Is there an attachment?
            if ($part->ifdisposition === 1 && $part->disposition === "attachment") {

                //Scan through email structure and validate attachment.
                $ifparams_list = array($part->ifdparameters, $part->ifparameters); //indicates if (d)paramaters exist.
                $params_list   = array($part->dparameters, $part->parameters);     //(d)parameter data, parrallel array to $ifparams_list.
                foreach($ifparams_list as $ifparam_index=>$ifparams) {
                    if ((boolean)$ifparams) {
                        foreach($params_list[$ifparam_index] as $params) {
                            if (strpos($params->attribute, "name") !== false && $params->value === IMAP_ATTACHMENT) {
                                //Get attachment data.
                                //Once CSV is written, we can end all nested loops (hence 'break 4;')
                                switch($part->encoding) {
                                //7 bit is ASCII.  8 bit is Latin-1.  Both should be printable without decoding.
                                case ENC7BIT:
                                case ENC8BIT:
                                    fwrite(self::$csv_fh, imap_fetchbody(self::$imap_conn, $email_id[0], $part_index+1));
                                    //Set SEEN flag on email so it isn't re-read again in the future.
                                    imap_setflag_full(self::$imap_conn, (string)$email_id[0], "\SEEN");
                                    return true;
                                //Base64 needs decoding.
                                case ENCBASE64:
                                    fwrite(self::$csv_fh, imap_base64(imap_fetchbody(self::$imap_conn, $email_id[0], $part_index+1)));
                                    //Set SEEN flag on email so it isn't re-read again in the future.
                                    imap_setflag_full(self::$imap_conn, (string)$email_id[0], "\SEEN");
                                    return true;
                                //Quoted Printable needs decoding.
                                case ENCQUOTEDPRINTABLE:
                                    fwrite(self::$csv_fh, imap_qprint(imap_fetchbody(self::$imap_conn, $email_id[0], $part_index+1)));
                                    //Set SEEN flag on email so it isn't re-read again in the future.
                                    imap_setflag_full(self::$imap_conn, (string)$email_id[0], "\SEEN");
                                    return true;
                                default:
                                    fprintf(STDERR, "Unexpected character encoding: %s\n(2 = BINARY, 5 = OTHER)\n", $part->encoding);
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        }

        // If we're down here, something has gone wrong.
        fprintf(STDERR, "Unexpected error while trying to write CSV.\n%s\n", error_get_last());
        return false;
    }
} //END class imap
?>
