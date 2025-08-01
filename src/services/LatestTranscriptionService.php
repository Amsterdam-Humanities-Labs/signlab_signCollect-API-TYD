<?php
/**
 * Service for getting latest matched_transcriptions based on timestamp priority
 */
class LatestTranscriptionService
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
     * Get the latest matched_transcriptions entry for a gloss
     * Searches both form_data and nmm_data sources and returns the most recent one
     * 
     * @param string $gloss The gloss to search for
     * @return array|null Latest matched transcription data with source info
     */
    public function getLatestMatchedTranscriptionForGloss($gloss)
    {
        // First, get form_data IDs for this gloss (exact match)
        $formDataIds = [];
        $sql = "SELECT id FROM form_data 
                WHERE glos = ?
                AND extern = '1' 
                AND glosZichtbaar = '0'";
        
        $stmt = $this->conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("s", $gloss);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $formDataIds[] = $row['id'];
            }
            $stmt->close();
        }
        
        // Get nmm_data IDs for this gloss (exact match)
        $nmmDataIds = [];
        $sql = "SELECT id FROM nmm_data WHERE glos = ?";
        
        $stmt = $this->conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("s", $gloss);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $nmmDataIds[] = $row['id'];
            }
            $stmt->close();
        }
        
        // Now find the latest matched_transcriptions entry
        $latestTranscription = null;
        $sourceInfo = null;
        
        // Check form_data matched_transcriptions
        if (!empty($formDataIds)) {
            $placeholders = str_repeat('?,', count($formDataIds) - 1) . '?';
            $sql = "SELECT mt.*, 'form_data' as source_type, mt.m_transcription as source_id
                    FROM matched_transcriptions mt
                    WHERE mt.m_transcription IN ($placeholders)
                    AND mt.zOg IN ('glos', 'extern', 'labels')
                    AND mt.added = '1'
                    ORDER BY mt.date DESC, mt.time DESC
                    LIMIT 1";
            
            $stmt = $this->conn->prepare($sql);
            if ($stmt) {
                $types = str_repeat('i', count($formDataIds));
                $stmt->bind_param($types, ...$formDataIds);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($row = $result->fetch_assoc()) {
                    $latestTranscription = $row;
                    $sourceInfo = [
                        'type' => 'form_data',
                        'id' => $row['source_id']
                    ];
                    $this->response['debug']['form_data_latest_found'] = [
                        'id' => $row['id'],
                        'date' => $row['date'],
                        'time' => $row['time'],
                        'zOg' => $row['zOg']
                    ];
                }
                $stmt->close();
            }
        }
        
        // Check nmm_data matched_transcriptions (only zOg = 'nmm')
        if (!empty($nmmDataIds)) {
            $placeholders = str_repeat('?,', count($nmmDataIds) - 1) . '?';
            $sql = "SELECT mt.*, 'nmm_data' as source_type, mt.m_transcription as source_id
                    FROM matched_transcriptions mt
                    WHERE mt.m_transcription IN ($placeholders)
                    AND mt.zOg = 'nmm'
                    AND mt.added = '1'
                    ORDER BY mt.date DESC, mt.time DESC
                    LIMIT 1";
            
            $stmt = $this->conn->prepare($sql);
            if ($stmt) {
                $types = str_repeat('i', count($nmmDataIds));
                $stmt->bind_param($types, ...$nmmDataIds);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($row = $result->fetch_assoc()) {
                    $this->response['debug']['nmm_data_latest_found'] = [
                        'id' => $row['id'],
                        'date' => $row['date'],
                        'time' => $row['time'],
                        'zOg' => $row['zOg']
                    ];
                    
                    // Compare with existing latest transcription using date/time
                    if (!$latestTranscription || $this->isMoreRecent($row, $latestTranscription)) {
                        $this->response['debug']['nmm_data_is_more_recent'] = true;
                        $latestTranscription = $row;
                        $sourceInfo = [
                            'type' => 'nmm_data',
                            'id' => $row['source_id']
                        ];
                    } else {
                        $this->response['debug']['form_data_is_more_recent'] = true;
                    }
                }
                $stmt->close();
            }
        }
        
        if ($latestTranscription && $sourceInfo) {
            return [
                'transcription' => $latestTranscription,
                'source' => $sourceInfo
            ];
        }
        
        return null;
    }
    
    /**
     * Get videos from the latest matched_transcriptions entry for a gloss
     * 
     * @param string $gloss The gloss to search for
     * @param string $baseUrl Base URL for media files (default: https://media.signcollect.nl/)
     * @return array Video data with source information
     */
    public function getLatestVideosForGloss($gloss, $baseUrl = 'https://media.signcollect.nl/')
    {
        $latestMatch = $this->getLatestMatchedTranscriptionForGloss($gloss);
        
        if (!$latestMatch) {
            return [
                'videos' => ['videoLeft' => null, 'videoCenter' => null, 'videoRight' => null],
                'thumbnails' => ['videoLeft' => null, 'videoCenter' => null, 'videoRight' => null],
                'source' => null,
                'matched_transcription_id' => null
            ];
        }
        
        $transcriptionData = $latestMatch['transcription'];
        $sourceInfo = $latestMatch['source'];
        
        $videos = [];
        $thumbnails = [];
        
        // Process video files
        if (!empty($transcriptionData['l_file'])) {
            $videoFile = preg_replace('/\.wav$/i', '.mp4', $transcriptionData['l_file']);
            $thumbnailFile = preg_replace('/\.wav$/i', '.jpg', $transcriptionData['l_file']);
            $videos['videoLeft'] = $baseUrl . $videoFile;
            $thumbnails['videoLeft'] = $baseUrl . $thumbnailFile;
        }
        
        if (!empty($transcriptionData['m_file'])) {
            $videoFile = preg_replace('/\.wav$/i', '.mp4', $transcriptionData['m_file']);
            $thumbnailFile = preg_replace('/\.wav$/i', '.jpg', $transcriptionData['m_file']);
            $videos['videoCenter'] = $baseUrl . $videoFile;
            $thumbnails['videoCenter'] = $baseUrl . $thumbnailFile;
        }
        
        if (!empty($transcriptionData['r_file'])) {
            $videoFile = preg_replace('/\.wav$/i', '.mp4', $transcriptionData['r_file']);
            $thumbnailFile = preg_replace('/\.wav$/i', '.jpg', $transcriptionData['r_file']);
            $videos['videoRight'] = $baseUrl . $videoFile;
            $thumbnails['videoRight'] = $baseUrl . $thumbnailFile;
        }
        
        return [
            'videos' => $videos,
            'thumbnails' => $thumbnails,
            'source' => $sourceInfo,
            'matched_transcription_id' => $transcriptionData['id'],
            'zOg' => $transcriptionData['zOg']
        ];
    }
    
    /**
     * Compare two matched_transcriptions records to determine which is more recent
     * 
     * @param array $record1 First record with date and time fields
     * @param array $record2 Second record with date and time fields
     * @return bool True if record1 is more recent than record2
     */
    private function isMoreRecent($record1, $record2)
    {
        // Convert date and time to comparable format
        $datetime1 = $this->parseDateTime($record1['date'], $record1['time']);
        $datetime2 = $this->parseDateTime($record2['date'], $record2['time']);
        
        return $datetime1 > $datetime2;
    }
    
    /**
     * Parse date and time strings into a comparable timestamp
     * 
     * @param string $date Date string (e.g., "2025-7-31")
     * @param string $time Time string (e.g., "14:05:58")
     * @return int Unix timestamp
     */
    private function parseDateTime($date, $time)
    {
        // Handle potential null or empty values
        if (empty($date) || empty($time)) {
            return 0;
        }
        
        // Combine date and time into a standard format
        $dateTimeString = $date . ' ' . $time;
        
        // Parse into timestamp
        $timestamp = strtotime($dateTimeString);
        
        // Return 0 if parsing failed
        return $timestamp !== false ? $timestamp : 0;
    }
}