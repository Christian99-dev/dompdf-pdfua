<?php
/**
 * ImageProcessor - Processes image rendering with PDF/UA tagging
 * 
 * Handles the complete image rendering lifecycle:
 * 1. Analyze: Determine image type (semantic vs artifact) and current state
 * 2. Execute: Open → Render → Close (atomic pattern)
 * 
 * ATOMIC DESIGN:
 * - analyze() returns ImageDecision enum based on state + isDecorative()
 * - execute() performs action WITHOUT additional logic/IF checks
 * - Images CLOSE immediately after rendering
 * - Each image gets its own /Figure or /Artifact structure element
 * 
 * ARCHITECTURE PATTERN:
 * - Images cannot be inline in P, Div, or other text containers
 * - Must always close current BDC (if open) and open new one
 * - State resets to NONE after each image
 * - Different from TextProcessor which maintains state
 * 
 * @package dompdf-accessible
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */

require_once __DIR__ . '/ContentProcessor.php';
require_once __DIR__ . '/ImageDecision.php';
require_once __DIR__ . '/TagOps.php';
require_once __DIR__ . '/TaggingStateManager.php';
require_once __DIR__ . '/TaggingState.php';
require_once __DIR__ . '/TreeLogger.php';
require_once __DIR__ . '/../../../src/SemanticTree.php';

use Dompdf\SemanticTree;

class ImageProcessor implements ContentProcessor
{
    /**
     * Process image rendering - orchestrates analyze → execute
     * 
     * Simple orchestration: analyze → execute
     */
    public function process(
        ?string $frameId,
        TaggingStateManager $stateManager,
        SemanticTree $semanticTree,
        callable $contentRenderer,
        ?callable $onBDCOpened = null
    ): string {
        // PHASE 1: Analyze - What should we do?
        $decision = $this->analyze($frameId, $stateManager, $semanticTree);
        
        // PHASE 2: Execute - Do it!
        return $this->execute($decision, $frameId, $stateManager, $semanticTree, $contentRenderer, $onBDCOpened);
    }
    
    /**
     * PHASE 1: Analyze image rendering decision
     * 
     * Determines what action to take based on:
     * - Current tagging state (NONE, SEMANTIC, ARTIFACT)
     * - Image type: isDecorative() → artifact vs semantic
     * - Frame type: Figure frame vs container frame (background-image)
     * 
     * CRITICAL: Images ALWAYS need their own container!
     * - Images cannot continue in P, Div, or other text BDCs
     * - Must always close current BDC (if not NONE) and open new one
     * - Each image gets its own /Figure or /Artifact BDC
     * 
     * BACKGROUND-IMAGE BUG FIX:
     * - CSS background-images have $frameId pointing to container (Div), not image
     * - Container nodes have getPdfStructureTag() != 'Figure'
     * - These must ALWAYS be treated as artifacts (WCAG compliant)
     * - They must close any open semantic BDC to avoid MCID ambiguity
     * 
     * LOGIC (6 cases):
     * - From NONE: Open semantic/artifact (2 cases)
     * - From SEMANTIC: Close semantic, open semantic/artifact (2 cases)
     * - From ARTIFACT: Close artifact, open semantic/artifact (2 cases)
     * 
     * @param string|null $frameId Current frame ID
     * @param TaggingStateManager $stateManager State manager
     * @param SemanticTree $semanticTree Semantic tree
     * @return ImageDecision Decision enum
     */
    private function analyze(
        ?string $frameId,
        TaggingStateManager $stateManager,
        SemanticTree $semanticTree
    ): ImageDecision
    {
        // CRITICAL: Handle null frameId (e.g., TCPDF-generated content)
        if ($frameId === null) {
            // No frame context = treat as artifact
            $currentState = $stateManager->getState();
            return ($currentState === TaggingState::NONE)
                ? ImageDecision::OPEN_ARTEFACT
                : ImageDecision::CLOSE_SEMANTIC_AND_OPEN_ARTEFACT;
        }
        
        // Get node and determine if decorative
        $node = $semanticTree->getNodeById($frameId);
        
        // CRITICAL: Handle missing node (frame created during reflow)
        if ($node === null) {
            // No semantic info = treat as artifact
            $currentState = $stateManager->getState();
            return ($currentState === TaggingState::NONE)
                ? ImageDecision::OPEN_ARTEFACT
                : ImageDecision::CLOSE_SEMANTIC_AND_OPEN_ARTEFACT;
        }
        
        // CRITICAL: Check if this is a background-image (CSS)
        // Background-images have frameId pointing to container (Div/P), not image frame
        // We detect this by checking if the node's PDF tag is NOT 'Figure'
        $pdfTag = $node->getPdfStructureTag();
        $isBackgroundImage = ($pdfTag !== 'Figure');
        
        // Background-images are ALWAYS decorative (WCAG requirement)
        // Only <img> tags with alt text can be semantic
        $isDecorative = $isBackgroundImage || $node->isDecorative();
        
        // Get current state
        $currentState = $stateManager->getState();
        
        // Decision matrix: State × Type → Decision
        switch ($currentState) {
            
            // ────────────────────────────────────────────────────────────────
            case TaggingState::NONE:
            // ────────────────────────────────────────────────────────────────
                // No BDC open yet - open appropriate type
                return $isDecorative
                    ? ImageDecision::OPEN_ARTEFACT
                    : ImageDecision::OPEN_SEMANTIC;
            
            // ────────────────────────────────────────────────────────────────
            case TaggingState::SEMANTIC:
            // ────────────────────────────────────────────────────────────────
                // Semantic BDC is open (e.g. P-tag from text)
                // ALWAYS close it first - images need their own container!
                if ($isDecorative) {
                    // Close P/Div, open Artifact
                    return ImageDecision::CLOSE_SEMANTIC_AND_OPEN_ARTEFACT;
                } else {
                    // Close P/Div, open Figure
                    return ImageDecision::CLOSE_SEMANTIC_AND_OPEN_SEMANTIC;
                }
            
            // ────────────────────────────────────────────────────────────────
            case TaggingState::ARTIFACT:
            // ────────────────────────────────────────────────────────────────
                // Artifact BDC is open
                // ALWAYS close it first - each image needs new container!
                if ($isDecorative) {
                    // Close old Artifact, open new Artifact
                    return ImageDecision::CLOSE_ARTEFACT_AND_OPEN_ARTEFACT;
                } else {
                    // Close Artifact, open Figure
                    return ImageDecision::CLOSE_ARTEFACT_AND_OPEN_SEMANTIC;
                }
        }

        return ImageDecision::OPEN_SEMANTIC; // Fallback (should not reach here)
    }
    
    /**
     * PHASE 2: Execute image rendering with atomic wrapping
     * 
     * Actions based on decision:
     * - OPEN_SEMANTIC: Open → Render → Close
     * - OPEN_ARTEFACT: Open → Render → Close
     * - CLOSE_SEMANTIC_AND_OPEN_SEMANTIC: Close → Open → Render → Close
     * - CLOSE_SEMANTIC_AND_OPEN_ARTEFACT: Close → Open → Render → Close
     * - CLOSE_ARTEFACT_AND_OPEN_SEMANTIC: Close → Open → Render → Close
     * - CLOSE_ARTEFACT_AND_OPEN_ARTEFACT: Close → Open → Render → Close
     * 
     * CRITICAL: Images are ATOMIC!
     * - All logic decisions made in analyze()
     * - Execute only performs actions
     * - Images close themselves immediately after rendering
     * - Each image gets its own structure element
     * 
     * @param ImageDecision $decision The decision from analyze()
     * @param string|null $frameId Current frame ID
     * @param TaggingStateManager $stateManager State manager
     * @param SemanticTree $semanticTree Semantic tree
     * @param callable $contentRenderer Content rendering callback
     * @param callable|null $onBDCOpened Callback when BDC is opened: fn(string $frameId, int $mcid, string $pdfTag, int $pageNumber): void
     * @return string PDF operators
     */
    private function execute(
        ImageDecision $decision,
        ?string $frameId,
        TaggingStateManager $stateManager,
        SemanticTree $semanticTree,
        callable $contentRenderer,
        ?callable $onBDCOpened = null
    ): string {
        $output = '';
        $pdfTag = null;
        $mcid = null;
        
        // Get node for metadata
        $node = $semanticTree->getNodeById($frameId);
        $nodeId = $node->id ?? null;
        
        // CRITICAL: Capture state BEFORE operation for accurate logging
        $stateBeforeOperation = $stateManager->getState();
        
        // Execute based on decision (NO IF-CHECKS!)
        switch ($decision) {

            case ImageDecision::OPEN_SEMANTIC:
                // Open semantic BDC (/Figure) and render
                $pdfTag = $node->getPdfStructureTag();
                
                $mcid = $stateManager->getNextMCID();
                $output .= TagOps::bdcOpen($pdfTag, $mcid);
                $stateManager->openSemanticBDC($frameId, $mcid);
                
                // CALLBACK: Notify that BDC was opened
                if ($onBDCOpened !== null) {
                    $pageNumber = $stateManager->getCurrentPage();
                    $onBDCOpened($frameId, $mcid, $pdfTag, $pageNumber);
                }
                
                // Render content
                $output .= $contentRenderer();
                
                // Close immediately - images are atomic!
                $output .= TagOps::emc();
                $stateManager->closeSemanticBDC();
                break;

            case ImageDecision::OPEN_ARTEFACT:
                // Open artifact BDC and render
                $output .= TagOps::artifactOpen();
                $stateManager->openArtifactBDC();
                
                // Render content
                $output .= $contentRenderer();
                
                // Close immediately - images are atomic!
                $output .= TagOps::artifactClose();
                $stateManager->closeArtifactBDC();
                break;

            case ImageDecision::CLOSE_SEMANTIC_AND_OPEN_SEMANTIC:
                // Close current semantic BDC (e.g. P-tag)
                $output .= TagOps::emc();
                $stateManager->closeSemanticBDC();
                
                // Open new semantic BDC (/Figure)
                $pdfTag = $node->getPdfStructureTag();
                
                $mcid = $stateManager->getNextMCID();
                $output .= TagOps::bdcOpen($pdfTag, $mcid);
                $stateManager->openSemanticBDC($frameId, $mcid);
                
                // CALLBACK: Notify that BDC was opened
                if ($onBDCOpened !== null) {
                    $pageNumber = $stateManager->getCurrentPage();
                    $onBDCOpened($frameId, $mcid, $pdfTag, $pageNumber);
                }
                
                // Render content
                $output .= $contentRenderer();
                
                // Close immediately - images are atomic!
                $output .= TagOps::emc();
                $stateManager->closeSemanticBDC();
                break;

            case ImageDecision::CLOSE_SEMANTIC_AND_OPEN_ARTEFACT:
                // Close semantic BDC
                $output .= TagOps::emc();
                $stateManager->closeSemanticBDC();
                
                // Open artifact BDC
                $output .= TagOps::artifactOpen();
                $stateManager->openArtifactBDC();
                
                // Render content
                $output .= $contentRenderer();
                
                // Close immediately - images are atomic!
                $output .= TagOps::artifactClose();
                $stateManager->closeArtifactBDC();
                break;

            case ImageDecision::CLOSE_ARTEFACT_AND_OPEN_SEMANTIC:
                // Close artifact BDC
                $output .= TagOps::artifactClose();
                $stateManager->closeArtifactBDC();
                
                // Open semantic BDC (/Figure)
                $pdfTag = $node->getPdfStructureTag();
                
                $mcid = $stateManager->getNextMCID();
                $output .= TagOps::bdcOpen($pdfTag, $mcid);
                $stateManager->openSemanticBDC($frameId, $mcid);
                
                // CALLBACK: Notify that BDC was opened
                if ($onBDCOpened !== null) {
                    $pageNumber = $stateManager->getCurrentPage();
                    $onBDCOpened($frameId, $mcid, $pdfTag, $pageNumber);
                }
                
                // Render content
                $output .= $contentRenderer();
                
                // Close immediately - images are atomic!
                $output .= TagOps::emc();
                $stateManager->closeSemanticBDC();
                break;

            case ImageDecision::CLOSE_ARTEFACT_AND_OPEN_ARTEFACT:
                // Close current artifact BDC
                $output .= TagOps::artifactClose();
                $stateManager->closeArtifactBDC();
                
                // Open new artifact BDC
                $output .= TagOps::artifactOpen();
                $stateManager->openArtifactBDC();
                
                // Render content
                $output .= $contentRenderer();
                
                // Close immediately - images are atomic!
                $output .= TagOps::artifactClose();
                $stateManager->closeArtifactBDC();
                break;

        }
        
        // Single tree log at the end
        TreeLogger::logImageOperation(
            $decision->name,
            $frameId,
            $nodeId,
            $pdfTag,
            $mcid,
            $stateManager->getCurrentPage(),
            $output,
            $stateBeforeOperation
        );
        
        return $output;
    }
}
