<?php
/**
 * Service for resolving IDs between form_data and nmm_data tables
 * Priority: form_data > nmm_data when form_data has latest matched_transcriptions
 */
class IdResolverService
{
    private $conn;
    private $response;
    private $latestTranscriptionService;
    
    /**
     * Constructor
     * 
     * @param mysqli $conn Database connection
     * @param array $response Reference to response array
     * @param LatestTranscriptionService $latestTranscriptionService Latest transcription service
     */
    public function __construct($conn, &$response, $latestTranscriptionService = null)
    {
        $this->conn = $conn;
        $this->response = &$response;
        $this->latestTranscriptionService = $latestTranscriptionService;
    }
    
    /**
     * Resolve ID based on priority system
     * Priority: form_data > nmm_data when form_data has latest matched_transcriptions
     * 
     * @param int $id The original ID
     * @param string $type The type of ID ('glos' for form_data, 'nmm' for nmm_data)
     * @return array Resolved ID information
     */
    public function resolveId($id, $type)
    {
        $resolvedData = [
            'originalId' => $id,
            'originalType' => $type,
            'resolvedId' => $id,
            'resolvedType' => $type,
            'signbankId' => null,
            'hasVideos' => false,
            'source' => $type === 'glos' ? 'form_data' : 'nmm_data'
        ];
        
        // If type is 'glos' (form_data), check if it has the latest matched_transcriptions
        if ($type === 'glos') {
            $formMetadata = $this->getFormDataMetadata($id);
            
            if ($formMetadata && !empty($formMetadata['glos'])) {
                $resolvedData['signbankId'] = $formMetadata['signbank'];
                
                // Use LatestTranscriptionService to determine which record has the latest transcriptions
                if ($this->latestTranscriptionService) {
                    $latestMatch = $this->latestTranscriptionService->getLatestMatchedTranscriptionForGloss($formMetadata['glos']);
                    
                    if ($latestMatch) {
                        // The LatestTranscriptionService tells us which record (form_data or nmm_data) has the latest transcriptions
                        if ($latestMatch['source']['type'] === 'form_data' && $latestMatch['source']['id'] == $id) {
                            // This form_data record has the latest transcriptions, keep it
                            $resolvedData['hasVideos'] = true;
                            $resolvedData['source'] = 'form_data';
                            $this->response['debug']['resolved_to_form_data'] = [
                                'form_data_id' => $id,
                                'glos' => $formMetadata['glos'],
                                'matched_transcription_id' => $latestMatch['transcription']['id']
                            ];
                        } else {
                            // Another record (possibly nmm_data) has the latest transcriptions
                            if ($latestMatch['source']['type'] === 'nmm_data') {
                                $resolvedData['resolvedId'] = $latestMatch['source']['id'];
                                $resolvedData['resolvedType'] = 'nmm';
                                $resolvedData['hasVideos'] = true;
                                $resolvedData['source'] = 'nmm_data';
                                $this->response['debug']['resolved_to_nmm_by_latest_transcription'] = [
                                    'nmm_id' => $latestMatch['source']['id'],
                                    'matched_transcription_id' => $latestMatch['transcription']['id']
                                ];
                            }
                        }
                    } else {
                        // No latest transcriptions found, fall back to checking basic videos
                        $resolvedData['hasVideos'] = $this->hasMatchedTranscriptions($id, ['glos', 'extern', 'labels']);
                    }
                } else {
                    // Fall back to old logic if LatestTranscriptionService not available
                    $resolvedData['hasVideos'] = $this->hasMatchedTranscriptions($id, ['glos', 'extern', 'labels']);
                }
            }
        }
        
        // For nmm type, use LatestTranscriptionService if available
        if ($type === 'nmm') {
            $nmmMetadata = $this->getNmmMetadata($id);
            
            if ($nmmMetadata && !empty($nmmMetadata['glos']) && $this->latestTranscriptionService) {
                $latestMatch = $this->latestTranscriptionService->getLatestMatchedTranscriptionForGloss($nmmMetadata['glos']);
                
                if ($latestMatch && $latestMatch['source']['type'] === 'form_data') {
                    // A form_data record has the latest transcriptions, resolve to it instead
                    $resolvedData['resolvedId'] = $latestMatch['source']['id'];
                    $resolvedData['resolvedType'] = 'glos';
                    $resolvedData['hasVideos'] = true;
                    $resolvedData['source'] = 'form_data';
                    $this->response['debug']['resolved_nmm_to_form_data'] = [
                        'form_data_id' => $latestMatch['source']['id'],
                        'matched_transcription_id' => $latestMatch['transcription']['id']
                    ];
                } else {
                    // This nmm record has the latest (or only) transcriptions
                    $resolvedData['hasVideos'] = $this->hasMatchedTranscriptions($id, ['nmm', 'glos']);
                }
            } else {
                // Fall back to basic check
                $resolvedData['hasVideos'] = $this->hasMatchedTranscriptions($id, ['nmm', 'glos']);
            }
        }
        
        return $resolvedData;
    }
    
    /**
     * Get resolved ID with full metadata
     * 
     * @param int $id The original ID
     * @param string $type The type of ID ('glos' or 'nmm')
     * @return array Resolved ID with metadata
     */
    public function getResolvedIdWithMetadata($id, $type)
    {
        $resolvedData = $this->resolveId($id, $type);
        
        // Add additional metadata based on resolved type
        if ($resolvedData['resolvedType'] === 'nmm') {
            $metadata = $this->getNmmMetadata($resolvedData['resolvedId']);
            $resolvedData['metadata'] = $metadata;
        } else if ($resolvedData['resolvedType'] === 'glos') {
            $metadata = $this->getFormDataMetadata($resolvedData['resolvedId']);
            $resolvedData['metadata'] = $metadata;
        }
        
        return $resolvedData;
    }
    
    /**
     * Check if form_data record has signbank_id
     * 
     * @param int $formDataId Form data ID
     * @return string|null Signbank ID or null
     */
    private function checkFormDataSignbank($formDataId)
    {
        $sql = "SELECT signbank FROM form_data WHERE id = ?";
        
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            $this->response['debug']['signbank_check_prepare_error'] = $this->conn->error;
            return null;
        }
        
        $stmt->bind_param("i", $formDataId);
        
        if (!$stmt->execute()) {
            $this->response['debug']['signbank_check_execute_error'] = $stmt->error;
            $stmt->close();
            return null;
        }
        
        $result = $stmt->get_result();
        $stmt->close();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $this->response['debug']['form_data_signbank_value'] = $row['signbank'];
            return !empty($row['signbank']) ? $row['signbank'] : null;
        }
        
        $this->response['debug']['form_data_not_found'] = "No record found for ID: $formDataId";
        return null;
    }
    
    /**
     * Find nmm_data records by signbank_id
     * 
     * @param string $signbankId Signbank ID
     * @return array Array of nmm_data records
     */
    private function findNmmBySignbankId($signbankId)
    {
        $nmmData = [];
        
        $sql = "SELECT id, glos, type, thema FROM nmm_data WHERE signbank_id = ?";
        
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            $this->response['debug']['nmm_find_prepare_error'] = $this->conn->error;
            return $nmmData;
        }
        
        $stmt->bind_param("s", $signbankId);
        
        if (!$stmt->execute()) {
            $this->response['debug']['nmm_find_execute_error'] = $stmt->error;
            $stmt->close();
            return $nmmData;
        }
        
        $result = $stmt->get_result();
        $stmt->close();
        
        while ($row = $result->fetch_assoc()) {
            $nmmData[] = $row;
        }
        
        $this->response['debug']['nmm_records_found'] = count($nmmData);
        
        return $nmmData;
    }
    
    /**
     * Check if an ID has matched transcriptions with specified zOg types
     * 
     * @param int $id The ID to check
     * @param array $zOgTypes Array of zOg types to check (e.g., ['nmm', 'glos'])
     * @return bool True if has matched transcriptions
     */
    private function hasMatchedTranscriptions($id, $zOgTypes)
    {
        $placeholders = implode(',', array_fill(0, count($zOgTypes), '?'));
        $sql = "SELECT 1 FROM matched_transcriptions 
                WHERE m_transcription = ? 
                AND zOg IN ($placeholders) 
                AND added = '1' 
                LIMIT 1";
        
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            $this->response['debug']['transcription_check_prepare_error'] = $this->conn->error;
            return false;
        }
        
        // Build bind parameters
        $types = 'i' . str_repeat('s', count($zOgTypes));
        $params = array_merge([$id], $zOgTypes);
        
        // Create references for bind_param
        $bindParams = [$types];
        foreach ($params as $key => $value) {
            $bindParams[] = &$params[$key];
        }
        
        call_user_func_array([$stmt, 'bind_param'], $bindParams);
        
        if (!$stmt->execute()) {
            $this->response['debug']['transcription_check_execute_error'] = $stmt->error;
            $stmt->close();
            return false;
        }
        
        $result = $stmt->get_result();
        $stmt->close();
        
        return $result->num_rows > 0;
    }
    
    /**
     * Get metadata for nmm_data record
     * 
     * @param int $nmmId NMM ID
     * @return array|null Metadata or null
     */
    private function getNmmMetadata($nmmId)
    {
        $sql = "SELECT id, glos, type, thema, zelfopname, signbank_id 
                FROM nmm_data WHERE id = ?";
        
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            $this->response['debug']['nmm_metadata_prepare_error'] = $this->conn->error;
            return null;
        }
        
        $stmt->bind_param("i", $nmmId);
        
        if (!$stmt->execute()) {
            $this->response['debug']['nmm_metadata_execute_error'] = $stmt->error;
            $stmt->close();
            return null;
        }
        
        $result = $stmt->get_result();
        $stmt->close();
        
        if ($result->num_rows > 0) {
            return $result->fetch_assoc();
        }
        
        return null;
    }
    
    /**
     * Get metadata for form_data record
     * 
     * @param int $formDataId Form data ID
     * @return array|null Metadata or null
     */
    private function getFormDataMetadata($formDataId)
    {
        $sql = "SELECT id, glos, senses, signbank, thema 
                FROM form_data 
                WHERE id = ? AND extern = '1' AND glosZichtbaar = '0'";
        
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            $this->response['debug']['form_metadata_prepare_error'] = $this->conn->error;
            return null;
        }
        
        $stmt->bind_param("i", $formDataId);
        
        if (!$stmt->execute()) {
            $this->response['debug']['form_metadata_execute_error'] = $stmt->error;
            $stmt->close();
            return null;
        }
        
        $result = $stmt->get_result();
        $stmt->close();
        
        if ($result->num_rows > 0) {
            return $result->fetch_assoc();
        }
        
        return null;
    }
}