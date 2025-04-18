<?php
/**
 * Class to manage error reporting settings
 */
class ErrorReporting
{
    /**
     * Configure error reporting settings
     * 
     * @param int $level Error reporting level
     */
    public static function configure($level = null)
    {
        if ($level === null) {
            // Default: Disable PHP warnings but keep errors
            error_reporting(E_ERROR | E_PARSE);
        } else {
            error_reporting($level);
        }
    }
}
