<?php

/**
 * CpdfPdfua - Extended Cpdf class with PDF/UA support
 *
 * @package CpdfPdfua
 * @author  Christian Keller
 */

namespace Dompdf\CpdfPdfua;

use Dompdf\Cpdf;

class CpdfPdfua extends Cpdf
{
    /**
     * Debug mode flag
     * @var bool
     */
    private $debugEnabled = false;

    /**
     * @var TaggingStateManager Tagging state manager
     */
    private $taggingStateManager;

    /**
     * @var TextTagging Text tagging processor
     */
    private $textTagging;

    /**
     * @var StructElemRegistry Structure element registry
     */
    private $structElemRegistry;

    /**
     * onMarkedContentAdded callback
     * @var callable
     */
    public $onMarkedContentAdded;

    /**
     * Constructor
     */
    public function __construct($pageSize = [0, 0, 612, 792], $isUnicode = false, $fontcache = '', $tmp = '')
    {
        parent::__construct($pageSize, $isUnicode, $fontcache, $tmp);

        $this->textTagging = new TextTagging();
        $this->taggingStateManager = new TaggingStateManager();
        $this->structElemRegistry = new StructElemRegistry();

        $this->onMarkedContentAdded = function ($tag, $mcid) {
            if ($this->debugEnabled) {
                print "[CPDF PDFUA] Marked Content Added: tag=$tag, mcid=" . $mcid . "\n";
            }

            $pageId = $this->currentPage;
            $documentRootKey = $this->structElemRegistry->registerDocumentRoot();

            // Build ancestor chain from document root down to current element (including current)
            $currentNode = $this->taggingStateManager->getCurrentSemanticNode();
            $ancestorChain = $this->buildAncestorChain($currentNode);
            
            // Register all ancestors as containers (except the last one which will be the leaf)
            $parentKey = $documentRootKey;
            $chainLength = count($ancestorChain);
            
            for ($i = 0; $i < $chainLength; $i++) {
                $ancestorNode = $ancestorChain[$i];
                $ancestorTag = $ancestorNode->getPdfStructureTag();
                if ($ancestorTag === null) continue;
                
                $isLeaf = ($i === $chainLength - 1);
                
                if ($isLeaf) {
                    // Last element in chain - register with MCID
                    $this->structElemRegistry->registerStructElem($ancestorTag, $mcid, $pageId, $parentKey);
                } else {
                    // Container element - get or create
                    $parentKey = $this->getOrCreateContainerElement($ancestorTag, $ancestorNode, $parentKey);
                }
            }

            if ($this->debugEnabled) {
                print "[CPDF PDFUA] Registered StructElem: tag=$tag, mcid=$mcid, pageId=$pageId\n";
            }
        };
    }
    
    /**
     * Build ancestor chain from document root to parent of current text node
     * Returns array of SemanticNodes from root down (excluding body/html)
     */
    private function buildAncestorChain(?SemanticNode $textNode): array
    {
        if ($textNode === null) return [];
        
        // Start with the structural parent of the text node (e.g., <p>)
        $current = $textNode->getNextStructuralParentNode();
        if ($current === null) return [];
        
        $chain = [$current]; // Start with the direct parent (leaf element)
        
        // Walk up to collect all ancestors
        $parent = $current->getNextStructuralParentNode();
        while ($parent !== null && !$parent->isBodyTag() && !$parent->isHtmlTag()) {
            array_unshift($chain, $parent); // Add to beginning (root first)
            $parent = $parent->getNextStructuralParentNode();
        }
        
        return $chain;
    }
    
    /**
     * Get or create a container element (without MCID)
     * Returns the key of the container element
     */
    private function getOrCreateContainerElement(string $tag, SemanticNode $node, string $parentKey): string
    {
        // Generate a unique key based on DOM node identity
        $domNode = $node->getDomNode();
        $nodeId = spl_object_id($domNode);
        $containerKey = strtolower($tag) . '_container_' . $nodeId;
        
        // Check if already registered
        if ($this->structElemRegistry->hasStructElem($containerKey)) {
            return $containerKey;
        }
        
        // Register new container (no MCID, no page)
        $this->structElemRegistry->registerStructElem(
            $tag,
            null,  // No MCID for containers
            null,  // No page for containers
            $parentKey
        );
        
        // Store under the container key
        $structElems = $this->structElemRegistry->getStructElems();
        $lastKey = array_key_last($structElems);
        
        // Rename the key to our container key
        $this->structElemRegistry->renameKey($lastKey, $containerKey);
        
        return $containerKey;
    }

    /**
     * Finalize the structure tree by creating all StructElem objects
     * This is called before output() to generate the actual PDF objects
     */
    private function finalizeStructureTree()
    {
        $structElems = $this->structElemRegistry->getStructElems();

        // First pass: Create all StructElem objects and assign object IDs
        foreach ($structElems as $key => $elemData) {
            $this->numObj++;
            $objectId = $this->numObj;

            // Store the object ID in the registry
            $this->structElemRegistry->setObjectId($key, $objectId);

            // Create the StructElem object
            $this->o_structElem($objectId, 'new');
            $this->o_structElem($objectId, 'structType', $elemData['type']);
        }

        // Second pass: Set up parent-child relationships
        foreach ($structElems as $key => $elemData) {
            $objectId = $this->structElemRegistry->getObjectId($key);

            if ($elemData['parent'] === null) {
                // This is the document root - parent is StructTreeRoot
                $this->o_structElem($objectId, 'parent', $this->structTreeRootId);

                // Link StructTreeRoot to this document root
                $this->o_structTreeRoot($this->structTreeRootId, 'kids', $objectId);
            } else {
                // Regular element - parent is another StructElem
                $parentObjectId = $this->structElemRegistry->getObjectId($elemData['parent']);
                $this->o_structElem($objectId, 'parent', $parentObjectId);
            }

            // Set kids (children or MCID)
            if (!empty($elemData['children'])) {
                $kidRefs = [];
                foreach ($elemData['children'] as $childKey) {
                    $childObjectId = $this->structElemRegistry->getObjectId($childKey);
                    if ($childObjectId) {
                        $kidRefs[] = ['ref' => $childObjectId]; // Mark as object reference
                    }
                }
                if (!empty($kidRefs)) {
                    $this->o_structElem($objectId, 'kids', $kidRefs);
                }
            } elseif ($elemData['mcid'] !== null) {
                // Leaf element with MCID - pass as single value, not array
                $this->o_structElem($objectId, 'kids', $elemData['mcid']);

                // Set page reference
                if ($elemData['page'] !== null) {
                    $this->o_structElem($objectId, 'page', $elemData['page']);
                }

                // Add to ParentTree
                $pageIndex = $elemData['page'];
                if ($pageIndex !== null) {
                    // Get StructParents index for this page
                    $pageObj = $this->objects[$elemData['page']];
                    if (isset($pageObj['info']['structParents'])) {
                        $structParentsIndex = $pageObj['info']['structParents'];
                        $this->addToParentTree($structParentsIndex, $objectId);
                    }
                }
            }
        }
    }

    /**
     * Override: Enable PDF/UA compliance and initialize structure tree
     */
    public function enablePdfUACompliance()
    {
        // Call parent to create StructTreeRoot, ParentTree, MarkInfo
        parent::enablePdfUACompliance();

        // Initialize document root in registry
        $this->structElemRegistry->registerDocumentRoot();
    }

    /**
     * Sets the current SemanticNode being processed
     * @param \DOMNode $node
     */
    public function setCurrentDomNode($node): void
    {
        if (!$this->pdfua) return;
        $this->taggingStateManager->setCurrentSemanticNode(new SemanticNode($node));
    }

    /**
     * Enable or disable debug logging
     */
    public function setDebugEnabled(bool $enabled): void
    {
        $this->debugEnabled = $enabled;
    }

    // ========================
    // Cpdf Operations
    // ========================

    function addText($x, $y, $size, $text, $angle = 0, $wordSpaceAdjust = 0, $charSpaceAdjust = 0, $smallCaps = false)
    {
        if (!$this->pdfua) return parent::addText($x, $y, $size, $text, $angle, $wordSpaceAdjust, $charSpaceAdjust, $smallCaps);


        // hier soll jetzt der textProcessor das machen
        $this->textTagging->process(
            $this->taggingStateManager,
            function () use ($x, $y, $size, $text, $angle, $wordSpaceAdjust, $charSpaceAdjust, $smallCaps) {
                return parent::addText($x, $y, $size, $text, $angle, $wordSpaceAdjust, $charSpaceAdjust, $smallCaps);
            },
            function ($content) {
                return parent::addContent($content);  //
            },
            $this->onMarkedContentAdded
        );

        if ($this->debugEnabled) print "[CPDF PDFUA] addText(x=$x, y=$y, size=$size, text=\"$text\")\n\n";
    }

    function newPage($insert = 0, $id = 0, $pos = 'after')
    {
        if (!$this->pdfua) return parent::newPage($insert, $id, $pos);

        // Close any open marked content before creating new page
        if ($this->taggingStateManager->getState() !== TaggingState::NONE) {
            parent::addContent(TagOps::endMarkedContent());
            $this->taggingStateManager->setState(TaggingState::NONE);
        }

        // Reset MCID counter for new page
        $this->taggingStateManager->resetMcidCounter();

        // Create the new page
        return parent::newPage($insert, $id, $pos);
    }

    function output($debug = false)
    {
        if (!$this->pdfua) return parent::output($debug);

        if ($this->taggingStateManager->getState() !== TaggingState::NONE) {
            parent::addContent(TagOps::endMarkedContent());
        }

        // Generate structure tree before output
        $this->finalizeStructureTree();

        return parent::output($debug);
    }

}
