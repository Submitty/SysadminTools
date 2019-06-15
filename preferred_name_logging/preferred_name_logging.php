#!/usr/bin/env php
<?php

class main {

    private const CONFIG = array(
        'postgresql_logfile_path' => "/var/log/postgresql/",
        'submitty_logfile_path'   => "/var/log/postgresql/",
        'postgresql_logfile'      => "postgresql",
        'submitty_logfile'        => "submitty_preferred_names"
    );

    public static function run() {
        // make sure we are running as root from cli
        switch (true) {
        case posix_getuid() !== 0:
        case php_sapi !== 'cli':
            exit("This is a command line script.  Root required." . PHP_EOL);
        }

        self::parse_and_write_logs();

        exit(0);
    }

    private static function parse_and_write_logs() {
        $submitty_logfile = self::CONFIG['submitty_logfile_path'] . self::CONFIG['submitty_logfile'] . "_" . date("m-d-y") . "log";
        $submitty_fh = fopen($submitty_logfile, 'w')
        if ($submitty_fh === false) {
            exit("Cannot create Submitty logfile". PHP_EOL);
        }

        $preg_str = "~^" . self::CONFIG['postgresql_logfile'] . "\-" . preg_quote(date("Y-m-d", time() - 86400)) . "_\d{6}\.csv$~";
        $logfiles = scandir(self::CONFIG['postgresql_logfile_path']);
        $logfiles = preg_grep($preg_str, $logfiles);

        foreach ($logfiles as $logfile) {
            $psql_fh = fopen(CONFIG::['postgresql_logfile_path'] . $logfile, 'r');
            if ($psql_fh === false) {
                printf(STDERR, "Cannot open {$logfile}%s", PHP_EOL);
                continue;
            }

            $line = fgets($psql_fh);
            while($line !== false) {
                $row = str_getcsv($line);
                switch(true) {
                case $row[7] !== "UPDATE":
                case $row[11] !== "LOG":
                case $row[13] !== "PREFERRED_NAME DATA UPDATE":
                    $line = fgets($psql_fh);
                    continue;
                }

                $date = $row[8] . "  ";

                if (preg_match("~/\* AUTH: [\w\-]+ \*/~", $row[18]) === 1) {
                    $key['start'] = strpos($row[18], "/* AUTH: ") + 3;
                    $key['end'] = strpos($row[18], " */");
                    $auth = " | " . substr($row[18], $key['start'], $key['end'] - $key['start']);
                } else {
                    $auth = " | AUTH NOT LOGGED";
                }

                $preferred_name = array();
                $preferred_names_data = explode(" ", $row[14]);
                $key = array_search("PREFERRED_FIRSTNAME", $preferred_names_data);
                if ($key !== false) {
                    $preferred_name['first']['old'] = $preferred_names_data[$key+2];
                    $preferred_name['first']['new'] = $preferred_names_data[$key+4];
                }

                $key = array_search("PREFERRED_LASTNAME", $preferred_names_data);
                if ($key !== false) {
                    $preferred_name['last']['old'] = $preferred_names_data[$key+2];
                    $preferred_name['last']['new'] = $preferred_names_data[$key+4];
                }

                $log = $date . $auth;
                if (isset($preferred_name['first'])) {
                    $log .= " | PREFERRED_FIRSTNAME OLD: {$preferred_name['first']['old']} -> NEW: {$preferred_name['first']['new']}";
                } else {
                    $log .= " | PREFERRED_FIRSTNAME UNCHANGED";
                }

                if (isset($preferred_name['last'])) {
                    $log .= " | PREFERRED_LASTNAME OLD: {$preferred_name['last']['old']} -> NEW: {$preferred_name['last']['new']}";
                } else {
                    $log .= " | PREFERRED_LASTNAME UNCHANGED";
                }

                fwrite($submitty_fh, $log . PHP_EOL);
                $line = fgets($psql_fh);
            }

            fclose($psql_fh);
        }

        fclose($submitty_fh);
    }
}

main::run();

?>
