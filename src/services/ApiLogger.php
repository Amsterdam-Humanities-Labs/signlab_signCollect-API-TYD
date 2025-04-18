<?php
/**
 * Logger service for API requests
 */
class ApiLogger
{
    private $conn;
    
    /**
     * Constructor
     * 
     * @param mysqli $conn Database connection
     */
    public function __construct($conn)
    {
        $this->conn = $conn;
    }
    
    /**
     * Log API request details
     * 
     * @param string $action Action being performed
     * @param string $id ID of the resource
     * @param string $status Status of the request
     * @param string $errorMessage Any error message
     * @param float $responseTime Response time in seconds
     */
    public function logRequest($action, $id = '', $status = 'success', $errorMessage = '', $responseTime = null)
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $requestData = json_encode($_REQUEST);
        
        try {
            $stmt = $this->conn->prepare("INSERT INTO zin_api_log (action, query, ip_address, user_agent, request_data, status, error_message, response_time) 
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssssss", $action, $id, $ip, $userAgent, $requestData, $status, $errorMessage, $responseTime);
            $stmt->execute();
            $stmt->close();
        } catch (Exception $e) {
            // If logging fails, write to error log as fallback
            error_log("Failed to log to zin_api_log: " . $e->getMessage());
        }
    }
}
