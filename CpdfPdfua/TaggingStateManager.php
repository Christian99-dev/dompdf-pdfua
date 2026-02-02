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
        print "[TaggingStateManager] State changed from {$this->state->name} to {$state->name}\n";
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
        $this->currentSemanticNode = $node;
    }

    public function getCurrentSemanticNode(): ?SemanticNode
    {
        return $this->currentSemanticNode;
    }

    public function isSameSemanticNode(SemanticNode $node): bool
    {
        return $this->currentSemanticNode->getDomNode() === $node->getDomNode();
    }
}
