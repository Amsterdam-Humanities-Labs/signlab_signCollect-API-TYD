<?php
/**
 * Service for handling sentence data
 */
class SentenceService
{
    private $conn;
    private $response;
    private $videoService;
    
    /**
     * Constructor
     * 
     * @param mysqli $conn Database connection
     * @param array $response Reference to response array
     * @param VideoService $videoService Video service
     */
    public function __construct($conn, &$response, $videoService)
    {
        $this->conn = $conn;
        $this->response = &$response;
        $this->videoService = $videoService;
    }
    
    /**
     * Get sentence data by ID
     * 
     * @param int $id Sentence ID
     * @return array Sentence data
     * @throws Exception If sentence not found
     */
    public function getSentenceById($id)
    {
        // Fetch sentence data with only existing columns (ID and zinString)
        $stmt = $this->conn->prepare("SELECT ID, zinString FROM sentences WHERE ID = ?");
        if (!$stmt) {
            $this->response['debug']['sentence_prepare_error'] = $this->conn->error;
            throw new Exception('Prepare statement failed: ' . $this->conn->error);
        }
        
        $stmt->bind_param("i", $id);
        if (!$stmt->execute()) {
            $this->response['debug']['sentence_execute_error'] = $stmt->error;
            throw new Exception('Execute statement failed: ' . $stmt->error);
        }
        
        $sentenceResult = $stmt->get_result();
        $stmt->close();
        
        if ($sentenceResult->num_rows === 0) {
            throw new Exception('Sentence not found with ID: ' . $id);
        }
        
        $sentence = $sentenceResult->fetch_assoc();
        
        // Get videos
        $sentence['videos'] = $this->videoService->getVideosForEntity($id, 'zin');
        
        // Generate subtitle files
        $subtitleFiles = [];
        if (!empty($sentence['videos']['videoCenter'])) {
            $videoUrl = $sentence['videos']['videoCenter'];
            // Extract filename from URL (e.g., M20241216_0838.mp4)
            if (preg_match('/\/([^\/]+\.mp4)$/', $videoUrl, $matches)) {
                $baseFilename = str_replace('.mp4', '', $matches[1]);
                
                // Create the SRT filenames
                $subtitleFiles = [
                    'Nederlands' => $baseFilename . '_Nederlands.srt',
                    'Gebaar_voor_Gebaar' => $baseFilename . '_Gebaar-voor-gebaar.srt',
                    'Signbank_ID_glossen' => $baseFilename . '_Signbank_ID_glossen.srt'
                ];
            }
        }
        
        // Add full URLs for subtitle files
        $subtitleFullUrls = [];
        foreach ($subtitleFiles as $type => $filename) {
            $subtitleFullUrls[$type] = $filename ? SUBTITLE_BASE_URL . $filename : null;
        }
        
        // Prepare the final response
        return [
            "ID" => $sentence['ID'] ?? null,
            "zinString" => $sentence['zinString'] ?? "",
            "Nederlands" => "", // Providing empty default for non-existent column
            "Gebaar_voor_Gebaar" => "", // Providing empty default for non-existent column
            "Signbank_ID_glossen" => "", // Providing empty default for non-existent column
            "videos" => $sentence['videos'],
            "subtitleFiles" => $subtitleFullUrls
        ];
    }
}
