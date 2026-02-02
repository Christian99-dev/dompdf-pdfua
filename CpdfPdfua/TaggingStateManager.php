<?php
namespace Dompdf\CpdfPdfua;

/**
 * TaggingStateManager - Manages Tagging State
 */
class TaggingStateManager
{
    private TaggingState $state = TaggingState::NONE;
    private int $mcidCounter = 0;
    private ?SemanticNode $currentSemanticNode = null;
    private ?SemanticNode $previousSemanticNode = null;
    public function getState(): TaggingState
    {
        return $this->state;
    }

    public function setState(TaggingState $state): void
    {
        print "[TaggingStateManager] [TaggingState] changed from {$this->state->name} to {$state->name}\n";
        $this->state = $state;
    }

    public function getNextMcid(): int
    {
        return ++$this->mcidCounter;
    }

    public function resetMcidCounter(): void
    {
        $this->mcidCounter = 0;
    }

    public function setCurrentSemanticNode(SemanticNode $node): void
    {
        // Filter
        if($node->isEmptyTextNode()) {
            // print "[TaggingStateManager] setCurrentSemanticNode() Ignoring empty text node\n";
            return;
        }

        if($node->isBodyTag()) {
            // print "[TaggingStateManager] setCurrentSemanticNode() Ignoring body tag\n";
            return;
        }
        
        if($node->isLineBreakTag()) {
            // print "[TaggingStateManager] setCurrentSemanticNode() Ignoring line break tag\n";
            return;
        }

        // Set prev only if current is not null (happens on first set)
        if($this->currentSemanticNode !== null) {
            $this->previousSemanticNode = $this->currentSemanticNode;
        }

        $this->currentSemanticNode = $node;

        print "[TaggingStateManager] setCurrentSemanticNode() to '" . $node->getDomNode()->textContent . "' (". $node->getDomNode()->nodeName .") (" . $node->getPdfStructureTag() . ")\n";
    }

    public function getCurrentSemanticNode(): ?SemanticNode
    {
        return $this->currentSemanticNode;
    }

    public function getPreviousSemanticNode(): ?SemanticNode
    {
        return $this->previousSemanticNode;
    }

}
