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
        $stmt = $this->conn->prepare("SELECT id, senses_dutch, annotation_id_gloss_dutch, nme_videos FROM sb_records WHERE id = ?");
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
        
        // Initialize videos structure
        $videos = [
            'videoLeft' => null,
            'videoCenter' => null,
            'videoRight' => null
        ];
        
        // Get video from nme_videos JSON field
        if (!empty($sbRecord['nme_videos'])) {
            try {
                $nmeVideos = json_decode($sbRecord['nme_videos'], true);
                
                if (is_array($nmeVideos) && !empty($nmeVideos)) {
                    // Get the first video
                    $firstVideo = $nmeVideos[0];
                    
                    if (isset($firstVideo['Link'])) {
                        $videoLink = $firstVideo['Link'];
                        
                        // Transform the URL as required
                        $videoLink = str_replace(
                            'https://signbank.cls.ru.nl//dictionary/protected_media/glossvideo/NGT/UI/', 
                            'https://signcollect.nl/uploads/',
                            $videoLink
                        );
                        
                        // Assign to videoCenter
                        $videos['videoCenter'] = $videoLink;
                    }
                }
            } catch (Exception $e) {
                $this->response['debug']['nme_videos_parse_error'] = $e->getMessage();
            }
        }
        
        // Prepare the final response
        return [
            "id" => $sbRecord['id'] ?? null,
            "senses_dutch" => $sensesDutch,
            "annotation_id_gloss_dutch" => $sbRecord['annotation_id_gloss_dutch'] ?? "",
            "videos" => $videos
        ];
    }
}
