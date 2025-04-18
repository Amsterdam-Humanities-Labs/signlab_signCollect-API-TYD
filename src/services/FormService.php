<?php
/**
 * Service for handling form data
 */
class FormService
{
    private $conn;
    private $response;
    private $videoService;
    private $nmmService;
    
    /**
     * Constructor
     * 
     * @param mysqli $conn Database connection
     * @param array $response Reference to response array
     * @param VideoService $videoService Video service
     * @param NmmService $nmmService NMM service
     */
    public function __construct($conn, &$response, $videoService, $nmmService)
    {
        $this->conn = $conn;
        $this->response = &$response;
        $this->videoService = $videoService;
        $this->nmmService = $nmmService;
    }
    
    /**
     * Get form data by ID
     * 
     * @param int $id Form ID
     * @return array Form data
     * @throws Exception If form not found
     */
    public function getFormById($id)
    {
        // Fetch form data
        $stmt = $this->conn->prepare("SELECT * FROM form_data WHERE id = ?");
        if (!$stmt) {
            $this->response['debug']['form_prepare_error'] = $this->conn->error;
            throw new Exception('Prepare statement failed: ' . $this->conn->error);
        }
        
        $stmt->bind_param("i", $id);
        if (!$stmt->execute()) {
            $this->response['debug']['form_execute_error'] = $stmt->error;
            throw new Exception('Execute statement failed: ' . $stmt->error);
        }
        
        $formResult = $stmt->get_result();
        $stmt->close();
        
        if ($formResult->num_rows === 0) {
            throw new Exception('Form not found with ID: ' . $id);
        }
        
        $form = $formResult->fetch_assoc();
        
        // Fetch videos
        $form['videos'] = $this->videoService->getVideosForEntity($id, 'glos');
        
        // Check if it has signbank_id, then fetch NMM data as well
        $nmm_data = [];
        if (!empty($form['signbank'])) {
            $nmm_data = $this->nmmService->getNmmDataForSignbankId($form['signbank']);
        }
        
        // Prepare the final response
        return [
            "id" => $form['id'] ?? null,
            "senses" => $form['senses'] ?? "",
            "signbank" => $form['signbank'] ?? "",
            "videos" => $form['videos'],
            "nmm_data" => $nmm_data
        ];
    }
}
