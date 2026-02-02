<?php
namespace Dompdf\CpdfPdfua;

class TextTagging
{
    /**
     * Process text rendering
     * 
     * Simple orchestration: analyze → execute
     * 
     * @param SemanticNode $currentSemanticNode Current semantic node
     * @param TaggingStateManager $stateManager State manager
     * @param callable $textCallback Text rendering callback from Cpdf
     * @param callable $addContentCallback Content adding callback from Cpdf
     * @return string Rendered content with tagging
     */
    public function process(
        TaggingStateManager $stateManager,
        callable $textCallback,
        callable $addContentCallback,
    ): string {
        // PHASE 1: Analyze - What should we do?
        $decision = $this->analyze($stateManager);

        print "[TextTagging] Decision: {$decision->name}\n";
        
        // PHASE 2: Execute - Do it!
        return $this->execute($decision, $stateManager, $textCallback, $addContentCallback);
    }
    
    /**
     * Analyze text rendering decision
     * 
     * @param SemanticNode $currentSemanticNode Current semantic node
     * @param TaggingStateManager $stateManager State manager
     * 
     * @return TaggingDecision Decision type (enum)
     */
    public function analyze(
        TaggingStateManager $stateManager
    ): TaggingDecision {
        // the SemanticNode will always be a #text node, so we need to jump to the next parent for tagging information.

        $currentSemanticNode = $stateManager->getCurrentSemanticNode();

        $isTextNode = $currentSemanticNode->isTextNode();
        $pdfTag = $currentSemanticNode->getPdfStructureTag();
        $isArtifact = $currentSemanticNode->isArtifactNode();
        $isSameSemanticNode = $stateManager->isSameSemanticNode($currentSemanticNode);

        print "[TextTagging] analyze(): isTextNode=" . ($isTextNode ? 'true' : 'false') . ", pdfTag=" . ($pdfTag ?? 'null') . ", isArtifact=" . ($isArtifact ? 'true' : 'false') . ", isSameSemanticNode=" . ($isSameSemanticNode ? 'true' : 'false') . "\n";
        
        if(!$isTextNode) {
            // no text node, or no pdf tag associated
            // this should not happen, but if it does, we just continue without tagging
            return TaggingDecision::CONTINUE;
        }
            
        switch ($stateManager->getState()) {
            case TaggingState::NONE:
                if ($isArtifact) {
                    return TaggingDecision::OPEN_ARTIFACT;
                } else {
                    return TaggingDecision::OPEN_SEMANTIC;
                }
            case TaggingState::SEMANTIC:

                if($isSameSemanticNode) {
                    return TaggingDecision::CONTINUE;
                }

                if($isArtifact) {
                    return TaggingDecision::CLOSE_AND_OPEN_ARTIFACT;
                } else {
                    return TaggingDecision::CLOSE_AND_OPEN_SEMANTIC_WITH_PARENT_TAG;
                }
            case TaggingState::ARTIFACT:
                if($isSameSemanticNode || $isArtifact) {
                    return TaggingDecision::CONTINUE;
                }
                return TaggingDecision::CLOSE_AND_OPEN_SEMANTIC_WITH_PARENT_TAG;
        }

        return TaggingDecision::CONTINUE;
    }
    
    /**
     * PHASE 2: Execute text rendering with tagging
     * 
     * @param TaggingDecision $decision The decision from analyze()
     * @param TaggingStateManager $stateManager State manager
     * @param callable $contentRenderer Content rendering callback
     * @param callable $addContentCallback Content adding callback
     */
    public function execute(
        TaggingDecision $decision,
        TaggingStateManager $stateManager,
        callable $textCallback,
        callable $addContentCallback
    ): string {
        $output = '';

        switch ($decision) {
            case TaggingDecision::OPEN_SEMANTIC_WITH_PARENT_TAG:
                $stateManager->setState(TaggingState::SEMANTIC);
                break;
            case TaggingDecision::OPEN_SEMANTIC:
                $stateManager->setState(TaggingState::SEMANTIC);
                break;
            case TaggingDecision::OPEN_ARTIFACT:
                $stateManager->setState(TaggingState::ARTIFACT);
                break;
            
            case TaggingDecision::CONTINUE:
                break;

            case TaggingDecision::CLOSE:
                $stateManager->setState(TaggingState::NONE);
                break;
            case TaggingDecision::CLOSE_AND_OPEN_SEMANTIC:
                $stateManager->setState(TaggingState::NONE);
                break;
            case TaggingDecision::CLOSE_AND_OPEN_ARTIFACT:
                $stateManager->setState(TaggingState::NONE);
                break;
            case TaggingDecision::CLOSE_AND_OPEN_SEMANTIC_WITH_PARENT_TAG:
                $stateManager->setState(TaggingState::SEMANTIC);
                break;

        }
        
        return $output;
    }
}
