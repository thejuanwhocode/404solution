<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

/* Functions in this class should all reference the one of the following variables or support functions that do.
 *      $wpdb, $_GET, $_POST, $_SERVER, $_.*
 * everything $wpdb related.
 * everything $_GET, $_POST, (etc) related.
 * Read the database, Store to the database,
 */

class ABJ_404_Solution_DataAccess {

    /** Create the tables when the plugin is first activated. 
     * @global type $wpdb
     */
    static function createDatabaseTables() {
        global $wpdb;
        
        $charset_collate = '';
        if (!empty($wpdb->charset)) {
            $charset_collate .= " DEFAULT CHARACTER SET $wpdb->charset";
        }
        if (!empty($wpdb->collate)) {
            $charset_collate .= " COLLATE $wpdb->collate";
        }
        $query = "CREATE TABLE IF NOT EXISTS `" . $wpdb->prefix . "abj404_redirects` (
              `id` bigint(30) NOT NULL auto_increment,
              `url` varchar(512) NOT NULL,
              `status` bigint(20) NOT NULL,
              `type` bigint(20) NOT NULL,
              `final_dest` varchar(512) NOT NULL,
              `code` bigint(20) NOT NULL,
              `disabled` int(10) NOT NULL default '0',
              `timestamp` bigint(30) NOT NULL,
              PRIMARY KEY  (`id`),
              KEY `status` (`status`),
              KEY `type` (`type`),
              KEY `code` (`code`),
              KEY `timestamp` (`timestamp`),
              KEY `disabled` (`disabled`),
              FULLTEXT KEY `url` (`url`),
              FULLTEXT KEY `final_dest` (`final_dest`)
            ) ENGINE=MyISAM " . esc_html($charset_collate) . " COMMENT='404 Solution Plugin Redirects Table' AUTO_INCREMENT=1";
        $wpdb->query($query);

        $query = $this->readFileContents(__DIR__ . "/sql/createLogTable.sql");
        $query = str_replace('{wp_abj404_logsv2}', $wpdb->prefix . 'abj404_logsv2', $query);
        $query = str_replace('{charset_collate}', esc_html($charset_collate), $query);
        $result = ABJ_404_Solution_DataAccess::queryAndGetResults($query);

        $query = $this->readFileContents(__DIR__ . "/sql/migrateToNewLogsTable.sql");
        $query = str_replace('{wp_abj404_logsv2}', $wpdb->prefix . 'abj404_logsv2', $query);
        $query = str_replace('{wp_abj404_logs}', $wpdb->prefix . 'abj404_logs', $query);
        $query = str_replace('{charset_collate}', esc_html($charset_collate), $query);
        $result = ABJ_404_Solution_DataAccess::queryAndGetResults($query);
        
        // TODO: optionally drop the logs table on the uninstall hook (not the deactivation hook).
        // see: https://developer.wordpress.org/plugins/the-basics/uninstall-methods/
        // don't drop the other table though because that would lose all previous settings.
    }
    
    /** 
     * @global type $wpdb
     */
    function importDataFromPluginRedirectioner() {
        global $wpdb;
        global $abj404logging;
        
        $oldTable = $wpdb->prefix . 'wbz404_redirects';
        $newTable = $wpdb->prefix . 'abj404_redirects';
        // wp_wbz404_redirects -- old table
        // wp_abj404_redirects -- new table
        
        $query = $this->readFileContents(__DIR__ . "/sql/importDataFromPluginRedirectioner.sql");
        $query = str_replace('{OLD_TABLE}', $oldTable, $query);
        $query = str_replace('{NEW_TABLE}', $newTable, $query);
        
        $result = ABJ_404_Solution_DataAccess::queryAndGetResults($query);

        $abj404logging->debugMessage("Importing redirectioner SQL result: " . 
                wp_kses_post(json_encode($result)));
        
        return $result;
    }
    
    /** Return the results of the query in a variable.
     * @param type $query
     * @return type
     */
    static function queryAndGetResults($query) {
        global $wpdb;
        
        $wpdb->query($query);
        $result['last_error'] = $wpdb->last_error;
        $result['last_result'] = $wpdb->last_result;
        $result['rows_affected'] = $wpdb->rows_affected;
        
        return $result;
    }
    
    /** 
     * @param type $path
     * @return type
     * @throws Exception
     */
    function readFileContents($path) {
        if (!file_exists($path)) {
            throw new Exception("Error: Can't find file: " . $path);
        }
        
        $fileContents = file_get_contents($path);
        if ($fileContents !== false) {
            return $fileContents;
        }
        
        // if we can't read the file that way then try curl.
        if (!function_exists('curl_init')) {
            throw new Exception("Error: Can't read file: " . $path .
                    "\n   file_get_contents didn't work and curl is not installed.");
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'file://' . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $output = curl_exec($ch);
        curl_close($ch);
        
        if ($output == null) {
            throw new Exception("Error: Can't read file, even with cURL: " . $path);
        }
        
        return $output;        
    }

   /**
    * @global type $wpdb
    * @return int the total number of redirects that have been captured.
    */
   function getCapturedCount() {
       global $wpdb;

       $query = "select count(id) from " . $wpdb->prefix . "abj404_redirects where status = " . ABJ404_STATUS_CAPTURED;
       $captured = $wpdb->get_col($query, 0);
       if (count($captured) == 0) {
           $captured[0] = 0;
       }
       return intval($captured[0]);
   }

    /**
     * @global type $wpdb
     * @param type $types specified types such as ABJ404_STATUS_MANUAL, ABJ404_STATUS_AUTO, ABJ404_STATUS_CAPTURED, ABJ404_STATUS_IGNORED.
     * @param int $trashed 1 to only include disabled redirects. 0 to only include enabled redirects.
     * @return int the number of records matching the specified types.
     */
    function getRecordCount($types = array(), $trashed = 0) {
        global $wpdb;
        $recordCount = 0;

        if (count($types) >= 1) {

            $query = "select count(id) from " . $wpdb->prefix . "abj404_redirects where 1 and (";
            $x = 0;
            foreach ($types as $type) {
                if ($x >= 1) {
                    $query .= " or ";
                }
                $query .= "status = " . esc_sql($type);
                $x++;
            }
            $query .= ")";

            $query .= " and disabled = " . esc_sql($trashed);

            $row = $wpdb->get_row($query, ARRAY_N);
            $recordCount = $row[0];
        }

        return $recordCount;
    }

    /**
     * @global type $wpdb
     * @param int $redirectID only return results from this redirect ID. Use 0 to get all records.
     * @return int the number of records found.
     */
    function getLogsCount($redirectID) {
        global $wpdb;

        $query = $this->readFileContents(__DIR__ . "/sql/getLogsCount.sql");
        $query = str_replace('{wp_abj404_logsv2}', $wpdb->prefix . 'abj404_logsv2', $query);
        
        if ($redirectID != 0) {
            $query = str_replace('/* {SPECIFIC_ID}', '', $query);
            $query = str_replace('{redirect_id}', $redirectID, $query);
            $query = str_replace('{wp_abj404_redirects}', $wpdb->prefix . 'abj404_redirects', $query);
            
        }
        
        $row = $wpdb->get_row($query, ARRAY_N);
        $records = $row[0];

        return intval($records);
    }

    /** 
     * Return the dates/times when a redirect was used.
     * @global type $wpdb
     * @param type $id
     * @return type
     */
    function getRedirectLastUsed($id) {
        global $wpdb;

        $query = $wpdb->prepare("select timestamp from " . $wpdb->prefix . "abj404_logsv2 where " .
                " requested_url in (select url from " . $wpdb->prefix . 
                "abj404_redirects where redirect_id = %d) " .
                " order by timestamp desc", esc_sql($id));
        $row = $wpdb->get_col($query);

        if (isset($row[0])) {
            return $row[0];
        } else {
            return;
        }
    }
    
    /** 
     * @global type $wpdb
     * @return type
     */
    function getRedirectsAll() {
        global $wpdb;
        $query = "select id, url from " . $wpdb->prefix . "abj404_redirects order by url";
        $rows = $wpdb->get_results($query, ARRAY_A);
        return $rows;
    }

    /** Returns the redirects that are in place.
     * @global type $wpdb
     * @param type $sub either "redirects" or "captured".
     * @param type $tableOptions filter, order by, paged, perpage etc.
     * @param type $limitEnforced add "limit" to the query.
     * @return type rows from the redirects table.
     */
    function getRedirects($sub, $tableOptions, $limitEnforced = 1) {
        global $wpdb;
        global $abj404logging;

        $redirects = $wpdb->prefix . "abj404_redirects";
        $logs = $wpdb->prefix . "abj404_logsv2";

        $query = "select " . $redirects . ".id, " . $redirects . ".url, " . $redirects . ".status, " . $redirects . ".type, " . $redirects . ".final_dest, " . $redirects . ".code, " . $redirects . ".timestamp";
        $query .= ", count(" . $logs . ".id) as hits from " . $redirects . " ";
        $query .= " left outer join " . $logs . " on " . $redirects . ".url = " . $logs . ".requested_url ";
        $query .= " where 1 and (";
        if ($tableOptions['filter'] == 0 || $tableOptions['filter'] == -1) {
            if ($sub == 'abj404_redirects') {
                $query .= "status = " . ABJ404_STATUS_MANUAL . " or status = " . ABJ404_STATUS_AUTO;

            } else if ($sub == 'abj404_captured') {
                $query .= "status = " . ABJ404_STATUS_CAPTURED . " or status = " . ABJ404_STATUS_IGNORED;

            } else {
                $abj404logging->errorMessage("Unrecognized sub type: " . esc_html($sub));
            }
        } else {
            $query .= "status = " . sanitize_text_field($tableOptions['filter']);
        }
        $query .= ") ";

        if ($tableOptions['filter'] != -1) {
            $query .= "and disabled = 0 ";
        } else {
            $query .= "and disabled = 1 ";
        }

        $query .= "group by " . $redirects . ".id ";

        $query .= "order by " . sanitize_text_field($tableOptions['orderby']) . " " . sanitize_text_field($tableOptions['order']) . " ";

        if ($limitEnforced == 1) {
            $start = ( absint(sanitize_text_field($tableOptions['paged']) - 1)) * absint(sanitize_text_field($tableOptions['perpage']));
            $query .= "limit " . $start . ", " . absint(sanitize_text_field($tableOptions['perpage']));
        }

        $rows = $wpdb->get_results($query, ARRAY_A);
        return $rows;
    }

    /**
     * @global type $wpdb
     * @param type $tableOptions logsid, orderby, paged, perpage, etc.
     * @return type rows from querying the logs table.
     */
    function getLogRecords($tableOptions) {
        global $wpdb;

        $logs = $wpdb->prefix . "abj404_logsv2";
        $redirects = $wpdb->prefix . "abj404_redirects";

        $query = "select " . $logs . ".timestamp, " . $logs . ".user_ip as remote_host, " . $logs . ".referrer, " . $logs . ".dest_url as action, " . $redirects . ".url from " . $logs;
        $query .= " left outer join " . $redirects . " on " . $logs . ".requested_url = " . $redirects . ".url where 1 ";
        if ($tableOptions['logsid'] != 0) {
            $query .= " and " . $logs . ".requested_url in (select url from " . $redirects . 
                    " where id = " . sanitize_text_field($tableOptions['logsid']) . ") ";
        }

        $query .= "order by " . sanitize_text_field($tableOptions['orderby']) . " " . sanitize_text_field($tableOptions['order']) . " ";
        $start = ( absint(sanitize_text_field($tableOptions['paged']) - 1)) * absint(sanitize_text_field($tableOptions['perpage']));
        $query .= "limit " . $start . ", " . absint(sanitize_text_field($tableOptions['perpage']));

        $rows = $wpdb->get_results($query, ARRAY_A);
        return $rows;
    }
    
    /** 
     * Log that a redirect was done.
     * @global type $wpdb
     * @param type $id
     * @param type $action
     * @param type $matchReason
     */
    function logRedirectHit($id, $action, $matchReason) {
        global $wpdb;
        global $abj404logging;
        $now = time();

        // no nonce here because redirects are not user generated.

        $referer = wp_get_referer();
        if ($abj404logging->isDebug()) {
            $current_user = wp_get_current_user();
            $current_user_name = "(none)";
            if (isset($current_user)) {
                $current_user_name = $current_user->user_login;
            }
            $current_user->user_login;
            
            $redirect = $this->getRedirectByID($id);
            $from = "(not yet stored for ID " . esc_html($id) . ")";
            if (isset($redirect)) {
                $from = $redirect['url'];
            }
            $abj404logging->debugMessage("Logging redirect. redirect_id: " . absint($id) . 
                    " | Referer: " . esc_html($referer) . " | Current user: " . $current_user_name . 
                    " | From: " . esc_html($from) . esc_html(" to: ") . esc_html($action) . 
                    ', Reason: ' . $matchReason);
        }

        $wpdb->insert($wpdb->prefix . "abj404_logsv2", array(
            'timestamp' => esc_sql($now),
            'user_ip' => esc_sql($_SERVER['REMOTE_ADDR']),
            'referrer' => esc_sql($referer),
            'dest_url' => esc_sql($action),
            'reason' => esc_sql($matchReason),
            'requested_url' => esc_sql($from),
                ), array(
            '%d',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s'
                )
        );
    }

    /** Remove the redirect from the redirects table and from the logs.
     * @global type $wpdb
     * @param type $id
     */
    function deleteRedirect($id) {
        global $wpdb;
        $cleanedID = absint(sanitize_text_field($id));

        // no nonce here because this action is not always user generated.

        if ($cleanedID >= 0 && is_numeric($id)) {
            $queryRedirects = $wpdb->prepare("delete from " . $wpdb->prefix . "abj404_redirects where id = %d", $cleanedID);
            $wpdb->query($queryRedirects);
        }
    }

    /** Delete old redirects based on how old they are. 
     * @global type $wpdb
     * @global type $abj404dao
     * @global type $abj404logic
     */
    static function deleteOldRedirectsCron() {
        global $wpdb;
        global $abj404dao;
        global $abj404logic;
        global $abj404logging;

        $options = $abj404logic->getOptions();
        $now = time();
        $capturedURLsCount = 0;
        $autoRedirectsCount = 0;
        $manualRedirectsCount = 0;
        $oldLogsCount = 0;

        //Remove Captured URLs
        if ($options['capture_deletion'] != '0') {
            $capture_time = $options['capture_deletion'] * 86400;
            $then = $now - $capture_time;

            //Find unused urls
            $query = "select id from " . $wpdb->prefix . "abj404_redirects where (status = " . ABJ404_STATUS_CAPTURED . " or status = " . ABJ404_STATUS_IGNORED . ") and ";
            $query .= "timestamp <= " . esc_sql($then);
            $rows = $wpdb->get_results($query, ARRAY_A);
            foreach ($rows as $row) {
                //Remove Them
                $abj404dao->deleteRedirect($row['id']);
                $capturedURLsCount++;
            }
        }

        //Remove Automatic Redirects
        if (array_key_exists('auto_deletion', $options) && isset($options['auto_deletion']) && $options['auto_deletion'] != '0') {
            $auto_time = $options['auto_deletion'] * 86400;
            $then = $now - $auto_time;

            //Find unused urls
            $query = "select id from " . $wpdb->prefix . "abj404_redirects where status = " . ABJ404_STATUS_AUTO . " and ";
            $query .= "timestamp <= " . esc_sql($then);
            $rows = $wpdb->get_results($query, ARRAY_A);
            foreach ($rows as $row) {
                //Remove Them
                $abj404dao->deleteRedirect($row['id']);
                $autoRedirectsCount++;
            }
        }

        //Remove Manual Redirects
        if (array_key_exists('manual_deletion', $options) && isset($options['manual_deletion']) && $options['manual_deletion'] != '0') {
            $manual_time = $options['manual_deletion'] * 86400;
            $then = $now - $manual_time;

            //Find unused urls
            $query = "select id from " . $wpdb->prefix . "abj404_redirects where status = " . ABJ404_STATUS_MANUAL . " and ";
            $query .= "timestamp <= " . esc_sql($then);
            $rows = $wpdb->get_results($query, ARRAY_A);
            foreach ($rows as $row) {
                //Remove Them
                $abj404dao->deleteRedirect($row['id']);
                $manualRedirectsCount++;
            }
        }
        
        //Clean up old logs
        if ($options['log_deletion'] != '0') {
            $log_time = $options['log_deletion'] * 86400;
            $then = $now - $log_time;

            //Clean up old logs
            $query = "delete from " . $wpdb->prefix . "abj404_logsv2 where ";
            $query .= " timestamp < " . esc_sql($then);
            $results = ABJ_404_Solution_DataAccess::queryAndGetResults($query);
            $oldLogsCount = $results['rows_affected'];
        }

        $abj404logging->debugMessage("ABJ404_SOLUTION cron job run. Old captured URLs removed: " . 
                $capturedURLsCount . ", Old automatic redirects removed: " . $autoRedirectsCount .
                ", Old manual redirects removed: " . $manualRedirectsCount . 
                ", Old log lines removed: " . $oldLogsCount);
    }
    /** Remove duplicates. 
     * @global type $wpdb
     */
    static function removeDuplicatesCron() {
        global $wpdb;

        $rtable = $wpdb->prefix . "abj404_redirects";

        $query = "SELECT COUNT(id) as repetitions, url FROM " . $rtable . " GROUP BY url HAVING repetitions > 1";
        $rows = $wpdb->get_results($query, ARRAY_A);
        foreach ($rows as $row) {
            $url = $row['url'];

            $queryr1 = "select id from " . $rtable . " where url = '" . esc_sql(esc_url($url)) . "' order by id limit 0,1";
            $orig = $wpdb->get_row($queryr1, ARRAY_A, 0);
            if ($orig['id'] != 0) {
                $original = $orig['id'];

                $queryl = "delete from " . $rtable . " where url='" . esc_sql(esc_url($url)) . "' and id != " . esc_sql($original);
                $wpdb->query($queryl);
            }
        }
    }

    /**
     * Store a redirect for future use.
     * @global type $wpdb
     * @param type $fromURL
     * @param type $status
     * @param type $type
     * @param type $final_dest
     * @param type $code
     * @param type $disabled
     * @return type
     */
    function setupRedirect($fromURL, $status, $type, $final_dest, $code, $disabled = 0) {
        global $wpdb;
        global $abj404logging;

        // nonce is verified outside of this method. We can't verify here because 
        // automatic redirects are sometimes created without user interaction.

        if (!is_numeric($type)) {
            $abj404logging->errorMessage("Wrong data type for redirect. TYPE is non-numeric. From: " . 
                    esc_url($fromURL) . " to: " . esc_url($final_dest) . ", Type: " .esc_html($type) . ", Status: " . $status);
        } else if (absint($type) < 0) {
            $abj404logging->errorMessage("Wrong range for redirect TYPE. From: " . 
                    esc_url($fromURL) . " to: " . esc_url($final_dest) . ", Type: " .esc_html($type) . ", Status: " . $status);
        } else if (!is_numeric($status)) {
            $abj404logging->errorMessage("Wrong data type for redirect. STATUS is non-numeric. From: " . 
                    esc_url($fromURL) . " to: " . esc_url($final_dest) . ", Type: " .esc_html($type) . ", Status: " . $status);
        }
            
        $now = time();
        $wpdb->insert($wpdb->prefix . 'abj404_redirects', array(
            'url' => esc_sql($fromURL),
            'status' => esc_html($status),
            'type' => esc_html($type),
            'final_dest' => esc_html($final_dest),
            'code' => esc_html($code),
            'disabled' => esc_html($disabled),
            'timestamp' => esc_html($now)
                ), array(
            '%s',
            '%d',
            '%d',
            '%s',
            '%d',
            '%d',
            '%d'
                )
        );
        return $wpdb->insert_id;
    }

    /** Get the redirect for the URL. 
     * @global type $wpdb
     * @param type $url
     * @return type
     */
    function getActiveRedirectForURL($url) {
        global $wpdb;
        $redirect = array();

        // a disabled value of '1' means in the trash.
        $query = "select * from " . $wpdb->prefix . "abj404_redirects where url = '" . esc_sql(esc_url($url)) . "'" .
                " and disabled = 0 and status in (" . ABJ404_STATUS_MANUAL . ", " . ABJ404_STATUS_AUTO . ") " .
                "and type not in (" . ABJ404_TYPE_404_DISPLAYED . ", " . ABJ404_TYPE_HOME . ") ";

        $row = $wpdb->get_row($query, ARRAY_A);
        if ($row == NULL) {
            $redirect['id'] = 0;
        } else {
            foreach($row as $key => $value) {
                $redirect[$key] = $value;
            }
        }
        return $redirect;
    }

    /** Get the redirect for the URL. 
     * @global type $wpdb
     * @param type $url
     * @return type
     */
    function getExistingRedirectForURL($url) {
        global $wpdb;
        $redirect = array();

        // a disabled value of '1' means in the trash.
        $query = "select * from " . $wpdb->prefix . "abj404_redirects where url = '" . esc_sql($url) . "'" .
                " and disabled = 0 "; 

        $row = $wpdb->get_row($query, ARRAY_A);
        if ($row == NULL) {
            $redirect['id'] = 0;
        } else {
            foreach($row as $key => $value) {
                $redirect[$key] = $value;
            }
        }
        return $redirect;
    }
    
    /** Returns rows with the IDs of the published items.
     * @return type
     */
    function getPublishedPagesAndPostsIDs($slug = "") {
        global $wpdb;
        
        $query = "select id from $wpdb->posts where post_status='publish' and (post_type='page' or post_type='post')";
        if ($slug != "") {
            $query .= " and post_name='" . esc_sql($slug) . "'";
        }
        $rows = $wpdb->get_results($query);
        return $rows;
    }
    
    /** 
     * @global type $wpdb
     * @return type
     */
    function getPublishedPostIDs() {
        global $wpdb;
        $query = "select id from $wpdb->posts where post_status='publish' and post_type='post' order by post_title";
        $rows = $wpdb->get_results($query);
        
        return $rows;
    }
    
    /** Returns rows with the defined terms (tags).
     * @global type $wpdb
     * @return type
     */
    function getPublishedTags() {
        global $wpdb;
        
        $query = "select " . $wpdb->terms . ".term_id from " . $wpdb->terms . " ";
        $query .= "left outer join " . $wpdb->term_taxonomy . " on " . $wpdb->terms . ".term_id = " . $wpdb->term_taxonomy . ".term_id ";
        $query .= "where " . $wpdb->term_taxonomy . ".taxonomy='post_tag' and " . $wpdb->term_taxonomy . ".count >= 1";
        $rows = $wpdb->get_results($query);
        return $rows;
    }
    
    /** Returns rows with the defined categories.
     * @global type $wpdb
     * @return type
     */
    function getPublishedCategories() {
        global $wpdb;
        
        $query = "select " . $wpdb->terms . ".term_id from " . $wpdb->terms . " ";
        $query .= "left outer join " . $wpdb->term_taxonomy . " on " . $wpdb->terms . ".term_id = " . $wpdb->term_taxonomy . ".term_id ";
        $query .= "where " . $wpdb->term_taxonomy . ".taxonomy='category' and " . $wpdb->term_taxonomy . ".count >= 1";
        $rows = $wpdb->get_results($query);
        return $rows;
    }

    /** Delete stored redirects based on passed in POST data.
     * @global type $wpdb
     * @return type
     */
    function deleteSpecifiedRedirects() {
        global $wpdb;
        global $abj404logging;
        $message = "";

        // nonce already verified.

        $redirects = $wpdb->prefix . "abj404_redirects";
        $logs = $wpdb->prefix . "abj404_logsv2";

        
        if (@$_POST['sanity_purge'] != "1") {
            $message = __('Error: You didn\'t check the I understand checkbox. No purging of records for you!', '404-solution');
            return $message;
        }
        
        if (!array_key_exists('types', $_POST) || !isset($_POST['types']) || $_POST['types'] == '') {
            $message = __('Error: No redirect types were selected. No purges will be done.', '404-solution');
            return $message;
        }
        
        if (is_array($_POST['types'])) {
            $type = array_map('sanitize_text_field', $_POST['types']);
        } else {
            $type = sanitize_text_field($_POST['types']);
        }

        if (!is_array($type)) {
            $message = __('An unknown error has occurred.', '404-solution');
            return $message;
        }
        
        $redirectTypes = array();
        foreach ($type as $aType) {
            if (('' . $aType != ABJ404_TYPE_HOME) && ('' . $aType != ABJ404_TYPE_HOME)) {
                array_push($redirectTypes, absint($aType));
            }
        }

        if (empty($redirectTypes)) {
            $message = __('Error: No valid redirect types were selected. Exiting.', '404-solution');
            $abj404logging->debugMessage("Error: No valid redirect types were selected. Types: " .
                    wp_kses_post(json_encode($redirectTypes)));
            return $message;
        }
        $purge = sanitize_text_field($_POST['purgetype']);

        if ($purge != 'abj404_logs' && $purge != 'abj404_redirects') {
            $message = __('Error: An invalid purge type was selected. Exiting.', '404-solution');
            $abj404logging->debugMessage("Error: An invalid purge type was selected. Type: " .
                    wp_kses_post(json_encode($purge)));
            return $message;
        }
        
        // always add the type "0" because it's an invalid type that may exist in the databse. 
        // Adding it here does some cleanup if any is necessary.
        array_push($redirectTypes, 0);
        $typesForSQL = implode(',', $redirectTypes);
        
        if ($purge == 'abj404_redirects') {
            $queryStringReds = "update " . $redirects . " set disabled = 1 where status in (" . $typesForSQL . ")";
            $redirectCount = $wpdb->query($queryStringReds);
            
            $message .= sprintf( _n( '%s redirect entry was moved to the trash.', 
                    '%s redirect entries were moved to the trash.', $redirectCount, '404-solution'), $redirectCount);
        }

        return $message;
    }

    /**
     * This returns only the first column of the first row of the result.
     * @global type $wpdb
     * @param type $query a query that starts with "select count(id) from ..."
     * @param array $valueParams values to use to prepare the query.
     * @return int the count (result) of the query.
     */
    function getStatsCount($query = '', array $valueParams) {
        global $wpdb;

        if ($query == '') {
            return 0;
        }

        $results = $wpdb->get_col($wpdb->prepare($query, $valueParams));

        return intval($results[0]);
    }
    
    /** 
     * Look at $_POST and $_GET for the specified option and return the default value if it's not set.
     * @param type $name
     * @param type $defaultValue
     * @return type
     */
    function getPostOrGetSanitize($name, $defaultValue = null) {
        if (array_key_exists($name, $_GET) && isset($_GET[$name])) {
            if (is_array($_GET[$name])) {
                return array_map('sanitize_text_field', $_GET[$name]);
            }
            return sanitize_text_field($_GET[$name]);

        } else if (array_key_exists($name, $_POST) && isset($_POST[$name])) {
            if (is_array($_POST[$name])) {
                return array_map('sanitize_text_field', $_POST[$name]);
            }
            return sanitize_text_field($_POST[$name]);

        } else {
            return $defaultValue;
        }
    }

    /** 
     * @param type $id
     * @return type
     */
    function getRedirectByID($id) {
        global $wpdb;
        $query = $wpdb->prepare("select id, url, type, final_dest, code from " . $wpdb->prefix . 
                "abj404_redirects where 1 and id = %d", $id);
        $redirect = $wpdb->get_row($query, ARRAY_A);
        
        return $redirect;
    }
    
    /** 
     * @global type $wpdb
     * @param type $ids
     * @return type
     */
    function getRedirectsByIDs($ids) {
        global $wpdb;
        $validids = array_map('absint', $ids);
        $multipleIds = implode(',', $validids);
    
        $query = "select id, url, type, final_dest, code from " . $wpdb->prefix . "abj404_redirects " .
                "where id in (" . $multipleIds . ")";
        $rows = $wpdb->get_results($query, ARRAY_A);
        
        return $rows;
    }
    
    /** 
     * @param type $id
     * @return type
     */
    function getPostParent($id) {
        global $wpdb;
        $query = $wpdb->prepare("select id, post_parent from $wpdb->posts where post_status='publish' and post_type='page' and id = %d", $id);
        $prow = $wpdb->get_row($query, OBJECT);
        
        return $prow;
    }
    
    /** Change the status to "trash" or "ignored," for example.
     * @global type $wpdb
     * @param type $id
     * @param type $newstatus
     * @return type
     */
    function updateRedirectTypeStatus($id, $newstatus) {
        global $wpdb;
        $message = "";

        $result = false;
        if (preg_match('/[0-9]+/', '' . $id)) {

            $result = $wpdb->update($wpdb->prefix . "abj404_redirects", 
                    array('status' => esc_sql($newstatus)), 
                    array('id' => absint($id)), 
                    array('%d'), 
                    array('%d')
            );
        }
        if ($result == false) {
            $message = __('Error: Unknown Database Error!', '404-solution');
        }
        return $message;
    }

    /** Move a redirect to the "trash" folder.
     * @global type $wpdb
     * @param type $id
     * @param type $trash
     * @return type
     */
    function moveRedirectsToTrash($id, $trash) {
        global $wpdb;
        
        $message = "";
        $result = false;
        if (preg_match('/[0-9]+/', '' . $id)) {

            $result = $wpdb->update($wpdb->prefix . "abj404_redirects", array('disabled' => esc_html($trash)), array('id' => absint($id)), array('%d'), array('%d')
            );
        }
        if ($result == false) {
            $message = __('Error: Unknown Database Error!', '404-solution');
        }
        return $message;
    }

    /** 
     * @global type $wpdb
     * @param type $type ABJ404_EXTERNAL, ABJ404_POST, ABJ404_CAT, or ABJ404_TAG.
     * @param type $dest
     */
    function updateRedirect($type, $dest, $fromURL, $idForUpdate, $redirectCode) {
        global $wpdb;
        global $abj404logging;
        
        if (($type <= 0) || ($idForUpdate <= 0)) {
            $abj404logging->errorMessage("Bad data passed for update redirect request. Type: " .
                esc_html($type) . ", Dest: " . esc_html($dest) . ", ID(s): " . esc_html($idForUpdate));
            echo __('Error: Bad data passed for update redirect request.', '404-solution');
            return;
        }
        
        $wpdb->update($wpdb->prefix . "abj404_redirects", array(
            'url' => esc_sql($fromURL),
            'status' => ABJ404_STATUS_MANUAL,
            'type' => absint($type),
            'final_dest' => esc_sql($dest),
            'code' => esc_attr($redirectCode)
                ), array(
            'id' => absint($idForUpdate)
                ), array(
            '%s',
            '%d',
            '%d',
            '%s',
            '%d'
                ), array(
            '%d'
                )
        );
    }

    /** 
     * @return type
     */
    function getCapturedCountForNotification() {
        global $abj404dao;
        return $abj404dao->getRecordCount(array(ABJ404_STATUS_CAPTURED));
    }
}
