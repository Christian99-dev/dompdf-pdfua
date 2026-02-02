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

        print "[TextTagging] [TaggingDecision] {$decision->name}\n";
        
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
        $inSameParent = $currentSemanticNode->hasSameStructuralParentAs(
            $stateManager->getPreviousSemanticNode()
        );

        // print "[TextTagging] analyze(): \n\tisTextNode=" . ($isTextNode ? 'true' : 'false') . ",\n\tpdfTag=" . ($pdfTag ?? 'null') . ", \n\tisArtifact=" . ($isArtifact ? 'true' : 'false') . ", \n\tinSameParent=" . ($inSameParent ? 'true' : 'false') . "\n";
        
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
                    return TaggingDecision::OPEN_SEMANTIC_WITH_PARENT_TAG;
                }
            case TaggingState::SEMANTIC:

                if($inSameParent) {
                    return TaggingDecision::CONTINUE;
                }

                if($isArtifact) {
                    return TaggingDecision::CLOSE_AND_OPEN_ARTIFACT;
                } else {
                    return TaggingDecision::CLOSE_AND_OPEN_SEMANTIC_WITH_PARENT_TAG;
                }
            case TaggingState::ARTIFACT:
                if($inSameParent || $isArtifact) {
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
        $pdfTag = null;
        $mcid = null;

        switch ($decision) {
            case TaggingDecision::CONTINUE:
                $textCallback();
                break;
            
            // Close first
            case TaggingDecision::CLOSE_AND_OPEN_SEMANTIC_WITH_PARENT_TAG:
            case TaggingDecision::CLOSE_AND_OPEN_ARTIFACT:
            case TaggingDecision::CLOSE:
                $stateManager->setState(TaggingState::NONE);
                $addContentCallback(TagOps::endMarkedContent());
            
            // Open Semantic parent
            case TaggingDecision::OPEN_SEMANTIC_WITH_PARENT_TAG:
            case TaggingDecision::CLOSE_AND_OPEN_SEMANTIC_WITH_PARENT_TAG:
                $stateManager->setState(TaggingState::SEMANTIC);
                
                $mcid = $stateManager->getNextMcid();
                $pdfTag = $stateManager->getCurrentSemanticNode()->getStructuralParentPdfStructureTag();
                
                $addContentCallback(TagOps::startMarkedContent($pdfTag, $mcid));
                $textCallback();
                break;
            
            // Open Artifact
            case TaggingDecision::OPEN_ARTIFACT:
            case TaggingDecision::CLOSE_AND_OPEN_ARTIFACT:
                $stateManager->setState(TaggingState::ARTIFACT);

                $addContentCallback(TagOps::startArtifactContent());
                $textCallback();
                break;

        }
        
        return $output;
    }
}
