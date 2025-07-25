<?php
/**
 * Service for handling mocap data operations
 */
class MocapService
{
    private $conn;
    private $response;
    private $logger;
    
    /**
     * Constructor
     * 
     * @param mysqli $conn Database connection
     * @param array $response Reference to response array
     * @param ApiLogger $logger Optional logger instance
     */
    public function __construct($conn, &$response, $logger = null)
    {
        $this->conn = $conn;
        $this->response = &$response;
        $this->logger = $logger;
    }
    
    /**
     * Check if mocap data exists for a given gloss identifier
     * 
     * @param string $glossIdentifier Gloss identifier to check (annotation_id_gloss_dutch or glos)
     * @return bool True if mocap data exists, false otherwise
     */
    public function hasMocapData($glossIdentifier)
    {
        if (empty($glossIdentifier)) {
            return false;
        }
        
        // Log the mocap data check
        if ($this->logger) {
            $this->logger->logRequest('hasMocapData', "Checking: $glossIdentifier");
        }
        
        try {
            // Prepare the SQL query to check for the gloss identifier in the mocap_files table
            $sql = "SELECT COUNT(*) as count FROM mocap_files WHERE glos = ?";
            $stmt = $this->conn->prepare($sql);
            
            if (!$stmt) {
                $this->response['debug']['mocap_prepare_error'] = $this->conn->error;
                
                // Log error
                if ($this->logger) {
                    $this->logger->logRequest('hasMocapData_error', $glossIdentifier, 'error', "Prepare error: {$this->conn->error}");
                }
                
                return false;
            }
            
            $stmt->bind_param("s", $glossIdentifier);
            
            if (!$stmt->execute()) {
                $this->response['debug']['mocap_execute_error'] = $stmt->error;
                
                // Log execute error
                if ($this->logger) {
                    $this->logger->logRequest('hasMocapData_error', $glossIdentifier, 'error', "Execute error: {$stmt->error}");
                }
                
                $stmt->close();
                return false;
            }
            
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();
            
            $hasMocapData = ($row['count'] > 0);
            
            // Log result
            if ($this->logger) {
                $logStatus = $hasMocapData ? 'success' : 'info';
                $logMessage = $hasMocapData ? "Mocap data found" : "No mocap data found";
                $this->logger->logRequest('hasMocapData_result', $glossIdentifier, $logStatus, $logMessage);
            }
            
            return $hasMocapData;
            
        } catch (Exception $e) {
            $this->response['debug']['mocap_exception'] = $e->getMessage();
            
            // Log exception
            if ($this->logger) {
                $this->logger->logRequest('hasMocapData_exception', $glossIdentifier, 'error', $e->getMessage());
            }
            
            return false;
        }
    }
    
    /**
     * Add mocap data flag to an array of records
     * 
     * @param array $records Array of records (either form_data or sb_records)
     * @param string $identifierField The field name that contains the identifier to look up
     * @return array Records with mocap field added
     */
    public function addMocapFlagToRecords($records, $identifierField)
    {
        if (empty($records) || !is_array($records)) {
            return $records;
        }
        
        foreach ($records as &$record) {
            $identifier = $record[$identifierField] ?? '';
            if (!empty($identifier)) {
                $record['mocap'] = $this->hasMocapData($identifier);
            } else {
                $record['mocap'] = false;
            }
        }
        
        return $records;
    }
}