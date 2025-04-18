<?php
/**
 * Class to manage security headers for API responses
 */
class SecurityHeaders
{
    /**
     * Set all necessary security headers for API responses
     */
    public static function setHeaders()
    {
        header('Content-Type: application/json');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET');
        header('Access-Control-Allow-Headers: Content-Type');
    }
}
