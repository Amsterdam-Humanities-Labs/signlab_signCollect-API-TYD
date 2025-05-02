<?php
/**
 * Service for handling NMM data
 */
class NmmService
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
     * Get NMM data by ID
     * 
     * @param int $id NMM ID
     * @return array NMM data
     * @throws Exception If NMM not found
     */
    public function getNmmById($id)
    {
        // Fetch NMM data directly - selecting only necessary columns
        $stmt = $this->conn->prepare("SELECT id, name, description, type, signbank_id FROM nmm_data WHERE id = ?");
        if (!$stmt) {
            $this->response['debug']['nmm_prepare_error'] = $this->conn->error;
            throw new Exception('Prepare statement failed: ' . $this->conn->error);
        }
        
        $stmt->bind_param("i", $id);
        if (!$stmt->execute()) {
            $this->response['debug']['nmm_execute_error'] = $stmt->error;
            throw new Exception('Execute statement failed: ' . $stmt->error);
        }
        
        $nmmResult = $stmt->get_result();
        $stmt->close();
        
        if ($nmmResult->num_rows === 0) {
            throw new Exception('NMM data not found with ID: ' . $id);
        }
        
        $nmm = $nmmResult->fetch_assoc();
        
        // Get videos for this NMM
        $videos = [
            'videoLeft' => null,
            'videoCenter' => null,
            'videoRight' => null
        ];
        
        // Get videos from matched_transcriptions with zOg LIKE '%nmm%'
        $videoSql = "SELECT l_file, m_file, r_file FROM matched_transcriptions 
                     WHERE m_transcription = ? AND zOg LIKE '%nmm%'";
        
        $videoStmt = $this->conn->prepare($videoSql);
        if (!$videoStmt) {
            $this->response['debug']['nmm_video_prepare_error'] = $this->conn->error;
            throw new Exception('Prepare statement failed: ' . $this->conn->error);
        }
        
        $videoStmt->bind_param("s", $id);
        
        if (!$videoStmt->execute()) {
            $this->response['debug']['nmm_video_execute_error'] = $videoStmt->error;
            throw new Exception('Execute statement failed: ' . $videoStmt->error);
        }
        
        $videoResult = $videoStmt->get_result();
        $videoStmt->close();
        
        if ($videoResult->num_rows > 0) {
            while ($videoRow = $videoResult->fetch_assoc()) {
                // Convert .wav extensions to .mp4 for video paths and prepend base URL
                $leftFile = preg_replace('/\.wav$/i', '.mp4', $videoRow['l_file']);
                $centerFile = preg_replace('/\.wav$/i', '.mp4', $videoRow['m_file']);
                $rightFile = preg_replace('/\.wav$/i', '.mp4', $videoRow['r_file']);
                
                $videos['videoLeft'] = $leftFile ? MEDIA_BASE_URL . $leftFile : null;
                $videos['videoCenter'] = $centerFile ? MEDIA_BASE_URL . $centerFile : null;
                $videos['videoRight'] = $rightFile ? MEDIA_BASE_URL . $rightFile : null;
            }
        }
        
        $nmm['videos'] = $videos;
        
        // Prepare the final response
        return $nmm;
    }
    
    /**
     * Get NMM data by signbank ID
     * 
     * @param string $signbankId Signbank ID
     * @return array NMM data
     */
    public function getNmmDataForSignbankId($signbankId)
    {
        $nmmData = [];
        
        // Query nmm_data table for records with matching signbank_id
        // Select only necessary columns instead of all columns
        $sql = "SELECT id, name, description, type, signbank_id FROM nmm_data WHERE signbank_id = ?";
        
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            $this->response['debug']['nmm_prepare_error'] = $this->conn->error;
            return $nmmData;
        }
        
        $stmt->bind_param("s", $signbankId);
        
        if (!$stmt->execute()) {
            $this->response['debug']['nmm_execute_error'] = $stmt->error;
            $stmt->close();
            return $nmmData;
        }
        
        $result = $stmt->get_result();
        $stmt->close();
        
        // Process each NMM record
        if ($result->num_rows > 0) {
            while ($nmmRow = $result->fetch_assoc()) {
                $nmmId = $nmmRow['id'];
                $nmmRecord = $nmmRow;
                
                // Get videos from matched_transcriptions with zOg LIKE '%nmm%'
                $videoSql = "SELECT l_file, m_file, r_file FROM matched_transcriptions 
                             WHERE m_transcription = ? AND zOg LIKE '%nmm%'";
                
                $videoStmt = $this->conn->prepare($videoSql);
                if (!$videoStmt) {
                    continue;
                }
                
                $videoStmt->bind_param("s", $nmmId);
                
                if (!$videoStmt->execute()) {
                    $videoStmt->close();
                    continue;
                }
                
                $videoResult = $videoStmt->get_result();
                $videoStmt->close();
                
                // Process videos for this NMM record
                $videos = [
                    'videoLeft' => null,
                    'videoCenter' => null,
                    'videoRight' => null
                ];
                
                if ($videoResult->num_rows > 0) {
                    while ($videoRow = $videoResult->fetch_assoc()) {
                        // Convert .wav extensions to .mp4 for video paths and prepend base URL
                        $leftFile = preg_replace('/\.wav$/i', '.mp4', $videoRow['l_file']);
                        $centerFile = preg_replace('/\.wav$/i', '.mp4', $videoRow['m_file']);
                        $rightFile = preg_replace('/\.wav$/i', '.mp4', $videoRow['r_file']);
                        
                        $videos['videoLeft'] = $leftFile ? MEDIA_BASE_URL . $leftFile : null;
                        $videos['videoCenter'] = $centerFile ? MEDIA_BASE_URL . $centerFile : null;
                        $videos['videoRight'] = $rightFile ? MEDIA_BASE_URL . $rightFile : null;
                    }
                }
                
                // Add videos to the NMM record
                $nmmRecord['videos'] = $videos;
                $nmmData[] = $nmmRecord;
            }
        }
        
        return $nmmData;
    }
}
