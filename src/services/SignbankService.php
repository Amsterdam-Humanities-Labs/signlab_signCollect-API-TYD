<?php
/**
 * Service for handling Signbank records
 */
class SignbankService
{
    private $conn;
    private $response;
    
    /**
     * Constructor
     * 
     * @param mysqli $conn Database connection
     * @param array $response Reference to response array
     */
    public function __construct($conn, &$response)
    {
        $this->conn = $conn;
        $this->response = &$response;
    }
    
    /**
     * Get Signbank record by ID
     * 
     * @param int $id Signbank ID
     * @return array Signbank data
     * @throws Exception If record not found
     */
    public function getSignbankById($id)
    {
        // Fetch sb_records data
        $stmt = $this->conn->prepare("SELECT * FROM sb_records WHERE id = ?");
        if (!$stmt) {
            $this->response['debug']['sb_records_prepare_error'] = $this->conn->error;
            throw new Exception('Prepare statement failed: ' . $this->conn->error);
        }
        
        $stmt->bind_param("i", $id);
        if (!$stmt->execute()) {
            $this->response['debug']['sb_records_execute_error'] = $stmt->error;
            throw new Exception('Execute statement failed: ' . $stmt->error);
        }
        
        $sbResult = $stmt->get_result();
        $stmt->close();
        
        if ($sbResult->num_rows === 0) {
            throw new Exception('SignBank record not found with ID: ' . $id);
        }
        
        $sbRecord = $sbResult->fetch_assoc();
        
        // Parse senses_dutch from JSON
        $sensesDutch = !empty($sbRecord['senses_dutch']) ? json_decode($sbRecord['senses_dutch'], true) : [];
        
        // Get video for this record (different format than other tables)
        $videos = [
            'videoLeft' => null,
            'videoCenter' => null,
            'videoRight' => null
        ];
        
        if (!empty($sbRecord['video'])) {
            // For sb_records, video is stored directly in the video field
            $videoPath = $sbRecord['video'];
            // Check if it has the full URL already
            if (strpos($videoPath, 'http') !== 0) {
                $videos['videoCenter'] = MEDIA_BASE_URL . $videoPath;
            } else {
                $videos['videoCenter'] = $videoPath;
            }
        }
        
        // Prepare the final response
        return [
            "id" => $sbRecord['id'] ?? null,
            "senses_dutch" => $sensesDutch,
            "videos" => $videos
        ];
    }
}
