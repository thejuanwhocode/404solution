<?php declare(strict_types = 1);

/* Functions in this class should only be for plugging into WordPress listeners (filters, actions, etc).  */

class ABJ_404_Solution_ErrorHandler {

    /** Setup. */
    static function init() {
        // set to the user defined error handler
        set_error_handler("ABJ_404_Solution_ErrorHandler::NormalErrorHandler");
        register_shutdown_function('ABJ_404_Solution_ErrorHandler::FatalErrorHandler');
    }

    /** Try to capture PHP errors.
     * @param type $errno
     * @param type $errstr
     * @param type $errfile
     * @param type $errline
     * @return boolean
     */
    static function NormalErrorHandler($errno, $errstr, $errfile, $errline) {
        global $abj404logging;
        try {
            // if the error file does not contain the name of our plugin then we ignore it.
            $pluginFolder = substr(ABJ404_NAME, 0, strpos(ABJ404_NAME, '/'));
            if (strpos($errfile, $pluginFolder) === false) {
                return false;
            }

            switch ($errno) {
                case E_NOTICE:
                    // ignore these. it happens when we use the @ symbol to ignore undefined variables.
                    break;

                default:
                    $abj404logging->errorMessage("ABJ404-SOLUTION Normal error handler error: errno: " .
                            wp_kses_post(json_encode($errno)) . ", errstr: " . wp_kses_post(json_encode($errstr)) .
                            ", errfile: " . stripcslashes(wp_kses_post(json_encode($errfile))) .
                            ", errline: " . wp_kses_post(json_encode($errline)));
                    break;
            }
        } catch (Exception $ex) { 
            // ignored
        }
        /* Execute the PHP internal error handler anyway. */
        return false;
    }

    static function FatalErrorHandler() {
        global $abj404logging;
        $lasterror = error_get_last();

        try {
            $errno = $lasterror['type'];
            $errfile = $lasterror['file'];
            $pluginFolder = substr(ABJ404_NAME, 0, strpos(ABJ404_NAME, '/'));

            // if the error file does not contain the name of our plugin then we ignore it.
            if (strpos($errfile, $pluginFolder) === false) {
                return false;
            }

            switch ($errno) {
                case E_NOTICE:
                    // ignore these. it happens when we use the @ symbol to ignore undefined variables.
                    break;

                default:
                    $abj404logging->errorMessage("ABJ404-SOLUTION Fatal error handler: " .
                            stripcslashes(wp_kses_post(json_encode($lasterror))));
                    break;
            }
        } catch (Exception $ex) {
            // ignored
        }
    }
}

ABJ_404_Solution_ErrorHandler::init();
