<?php
/**
 * Service for retrieving a random video from form_data
 */
class RandomVideoService
{
    private $conn;
    private $videoService;
    private $nmmService;
    private $response;
    
    /**
     * Constructor
     * 
     * @param mysqli $conn Database connection
     */
    public function __construct($conn)
    {
        $this->conn = $conn;
        $this->response = []; // Initialize response array
        $this->videoService = new VideoService($conn, $this->response);
        $this->nmmService = new NmmService($conn, $this->response);
    }
    
    /**
     * Get a random video from form_data following the same conditions as FormService
     * 
     * @return array|null Random video data or null if no videos found
     */
    public function getRandomVideo()
    {
        // SQL query to get a random form with video availability
        // Following the same conditions as FormService::searchFormsByGlos()
        // Note: Column is 'theme' in test DB, 'thema' in production
        $themeColumn = IS_PROD ? 'thema' : 'theme';
        $sql = "SELECT DISTINCT f.id, f.senses, f.signbank, 
                IFNULL(f.$themeColumn, 'Unknown') as thema, f.glos
                FROM form_data f
                INNER JOIN matched_transcriptions m ON f.id = m.m_transcription
                WHERE f.extern = '1' 
                AND f.glosZichtbaar = '0'
                AND m.zOg IN ('glos', 'extern', 'labels')
                AND (m.l_file != '' OR m.m_file != '' OR m.r_file != '')
                ORDER BY RAND()
                LIMIT 1";
        
        $stmt = $this->conn->prepare($sql);
        
        if (!$stmt) {
            error_log("RandomVideoService: Prepare failed - " . $this->conn->error);
            return null;
        }
        
        if (!$stmt->execute()) {
            error_log("RandomVideoService: Execute failed - " . $stmt->error);
            $stmt->close();
            return null;
        }
        
        $result = $stmt->get_result();
        $stmt->close();
        
        if ($result->num_rows === 0) {
            return null;
        }
        
        $formData = $result->fetch_assoc();
        
        // Process senses field (convert to JSON if needed)
        $sensesValue = $formData['senses'];
        if (empty($sensesValue) || $sensesValue === null) {
            $formData['senses'] = '[]';
        } else {
            // Try to decode to check if it's valid JSON
            $decoded = json_decode($sensesValue, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                // If not valid JSON, wrap it as a single-element array
                $formData['senses'] = json_encode([$sensesValue]);
            } else {
                // If it's already valid JSON, keep it as is
                $formData['senses'] = $sensesValue;
            }
        }
        
        // Get videos for this form
        $videosSql = "SELECT m.m_transcription, m.l_file as L_video, m.m_file as M_video, m.r_file as R_video, m.zOg
                      FROM matched_transcriptions m
                      WHERE m.m_transcription = ?
                      AND m.zOg IN ('extern', 'labels')
                      AND (m.l_file != '' OR m.m_file != '' OR m.r_file != '')";
        
        $videosStmt = $this->conn->prepare($videosSql);
        
        if (!$videosStmt) {
            error_log("RandomVideoService: Videos prepare failed - " . $this->conn->error);
            return $formData; // Return without videos
        }
        
        $videosStmt->bind_param("i", $formData['id']);
        
        if (!$videosStmt->execute()) {
            error_log("RandomVideoService: Videos execute failed - " . $videosStmt->error);
            $videosStmt->close();
            return $formData; // Return without videos
        }
        
        $videosResult = $videosStmt->get_result();
        $videosStmt->close();
        
        // Process videos with priority system (extern takes precedence over labels)
        $videos = [
            'videoLeft' => null,
            'videoCenter' => null,
            'videoRight' => null
        ];
        
        $externVideos = null;
        $labelsVideos = null;
        
        while ($videoRow = $videosResult->fetch_assoc()) {
            if ($videoRow['zOg'] === 'extern') {
                $externVideos = $videoRow;
            } elseif ($videoRow['zOg'] === 'labels') {
                $labelsVideos = $videoRow;
            }
        }
        
        // Use extern videos if available, otherwise fall back to labels
        $selectedVideos = $externVideos ?: $labelsVideos;
        
        if ($selectedVideos) {
            // Process video URLs
            $videos['videoLeft'] = $this->processVideoUrl($selectedVideos['L_video']);
            $videos['videoCenter'] = $this->processVideoUrl($selectedVideos['M_video']);
            $videos['videoRight'] = $this->processVideoUrl($selectedVideos['R_video']);
        }
        
        $formData['videos'] = $videos;
        
        // Get NMM data if signbank is not empty
        if (!empty($formData['signbank'])) {
            $formData['nmm_data'] = $this->nmmService->getNmmBySignbank($formData['signbank']);
        } else {
            $formData['nmm_data'] = [];
        }
        
        return $formData;
    }
    
    /**
     * Process video URL (convert .wav to .mp4 and add base URL if needed)
     * 
     * @param string|null $videoFile Video file name
     * @return string|null Processed video URL
     */
    private function processVideoUrl($videoFile)
    {
        if (empty($videoFile)) {
            return null;
        }
        
        // Replace .wav with .mp4
        $videoFile = str_replace('.wav', '.mp4', $videoFile);
        
        // Add base URL if not already a full URL
        if (strpos($videoFile, 'http') !== 0) {
            return MEDIA_BASE_URL . $videoFile;
        }
        
        return $videoFile;
    }
}