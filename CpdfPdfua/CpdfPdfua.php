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
     * Current link structure element key (for StructParent linkage)
     * @var string|null
     */
    private $currentLinkStructElemKey = null;

    /**
     * Pending link annotations waiting for structure elements
     * @var array Array of [annotId => structParentIndex]
     */
    private $pendingLinkAnnotations = [];

    /**
     * Global StructParent counter
     * Start at 10000 to avoid collision with page StructParents indices (0, 1, 2, ...)
     * @var int
     */
    private $structParentCounter = 10000;

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
            print "[CPDF PDFUA] Marked Content Added: tag=$tag, mcid=" . $mcid . "\n";
            if ($this->debugEnabled) {
            }

            $pageId = $this->currentPage;
            $documentRootKey = $this->structElemRegistry->registerDocumentRoot();

            // Build ancestor chain from document root down to current element (including current)
            $currentNode = $this->taggingStateManager->getCurrentSemanticNode();
            $ancestorChain = $this->buildAncestorChain($currentNode);
            
            // Register all ancestors as containers (except the last one which will be the leaf)
            $parentKey = $documentRootKey;
            $chainLength = count($ancestorChain);
            $leafStructElemKey = null;
            
            for ($i = 0; $i < $chainLength; $i++) {
                $ancestorNode = $ancestorChain[$i];
                $ancestorTag = $ancestorNode->getPdfStructureTag();
                if ($ancestorTag === null) continue;
                
                $isLeaf = ($i === $chainLength - 1);
                
                if ($isLeaf) {
                    // Last element in chain - register with MCID and all attributes
                    // Use $tag parameter (from content stream) instead of $ancestorTag for leaf
                    // This ensures Figure/Image tags from wrapImageInSemanticTag are used correctly
                    $this->structElemRegistry->registerStructElem(
                        $tag,
                        $mcid,
                        $pageId,
                        $parentKey,
                        $ancestorNode->getAlt(),
                        $ancestorNode->getActualText(),
                        $ancestorNode->getLang(),
                        $ancestorNode->getExpansion(),
                        $ancestorNode->getTitle()
                    );
                    
                    // Store the key of the last registered element (leaf)
                    $structElems = $this->structElemRegistry->getStructElems();
                    $leafStructElemKey = array_key_last($structElems);
                } else {
                    // Container element - get or create
                    $parentKey = $this->getOrCreateContainerElement($ancestorTag, $ancestorNode, $parentKey);
                }
            }
            
            // If this is a Link element (leaf with text/MCID), store its key for StructParent linkage
            if ($tag === 'Link' && $leafStructElemKey !== null) {
                $this->currentLinkStructElemKey = $leafStructElemKey;
                if ($this->debugEnabled) {
                    print "[CPDF PDFUA] Stored Link StructElem key: $leafStructElemKey\n";
                }
                
                // Check if there's a pending link annotation waiting for this structure element
                if (!empty($this->pendingLinkAnnotations)) {
                    // Pop the first pending annotation (FIFO)
                    $annotId = array_key_first($this->pendingLinkAnnotations);
                    $annotInfo = $this->pendingLinkAnnotations[$annotId];
                    unset($this->pendingLinkAnnotations[$annotId]);
                    
                    // Store the linkage for later resolution during finalization
                    // This will add the OBJR to the Link StructElem's kids
                    $this->objects[$annotId]['info']['linkStructElemKey'] = $leafStructElemKey;
                    $this->objects[$annotId]['info']['linkAnnotId'] = $annotId;
                    $this->objects[$annotId]['info']['linkPageId'] = $annotInfo['pageId'];
                    
                    print "[CPDF PDFUA] Linked pending annotation $annotId (StructParent {$annotInfo['structParent']}) to Link StructElem $leafStructElemKey\n";
                }
            } else {
                // Check if there's a Link container in the ancestor chain (e.g., <a><img></a>)
                // This handles links without text content (container-only links)
                foreach ($ancestorChain as $ancestorNode) {
                    if ($ancestorNode->getPdfStructureTag() === 'Link') {
                        // Generate container key for this Link element
                        $domNode = $ancestorNode->getDomNode();
                        $nodeId = spl_object_id($domNode);
                        $linkContainerKey = 'link_container_' . $nodeId;
                        
                        // Check if there's a pending link annotation
                        if (!empty($this->pendingLinkAnnotations) && $this->structElemRegistry->hasStructElem($linkContainerKey)) {
                            // Pop the first pending annotation (FIFO)
                            $annotId = array_key_first($this->pendingLinkAnnotations);
                            $annotInfo = $this->pendingLinkAnnotations[$annotId];
                            unset($this->pendingLinkAnnotations[$annotId]);
                            
                            // Store the linkage for later resolution
                            $this->objects[$annotId]['info']['linkStructElemKey'] = $linkContainerKey;
                            $this->objects[$annotId]['info']['linkAnnotId'] = $annotId;
                            $this->objects[$annotId]['info']['linkPageId'] = $annotInfo['pageId'];
                            
                            print "[CPDF PDFUA] Linked pending annotation $annotId (StructParent {$annotInfo['structParent']}) to Link container $linkContainerKey\n";
                            break;  // Only link to first Link ancestor
                        }
                    }
                }
            }

            if ($this->debugEnabled) {
                print "[CPDF PDFUA] Registered StructElem: tag=$tag, mcid=$mcid, pageId=$pageId\n";
            }
        };
    }
    
    /**
     * Build ancestor chain from document root to current element
     * Returns array of SemanticNodes from root down (excluding body/html)
     * 
     * FLATTENING: If any ancestor has data-dompdf-pdf-structure-flatten:
     * - Removes container elements between flatten wrapper and leaf
     * - Keeps wrapper if shouldIncludeSelfInFlatten() = true
     * - Always keeps the leaf element (element with actual content/MCID)
     */
    private function buildAncestorChain(?SemanticNode $currentNode): array
    {
        if ($currentNode === null) return [];
        
        // For text nodes, start with structural parent (e.g., <p>)
        // For element nodes (img), start with the element itself
        if ($currentNode->isTextNode()) {
            $current = $currentNode->getNextStructuralParentNode();
            if ($current === null) return [];
            $rawChain = [$current]; // Start with parent (leaf element)
        } else {
            $current = $currentNode;
            $rawChain = [$current]; // Start with current element (leaf element)
        }
        
        // Walk up to collect all ancestors
        $parent = $current->getNextStructuralParentNode();
        while ($parent !== null && !$parent->isBodyTag() && !$parent->isHtmlTag()) {
            array_unshift($rawChain, $parent); // Add to beginning (root first)
            $parent = $parent->getNextStructuralParentNode();
        }
        
        // print "[buildAncestorChain] Raw chain (" . count($rawChain) . " elements): ";
        // foreach ($rawChain as $node) {
            // print $node->getDomNode()->nodeName . " > ";
        // }
        // print "\n";
        
        // Check for flatten wrapper in chain
        $flattenWrapper = null;
        $flattenWrapperIndex = -1;
        
        foreach ($rawChain as $index => $node) {
            if ($node->hasFlattenAttribute()) {
                $flattenWrapper = $node;
                $flattenWrapperIndex = $index;
                // print "[buildAncestorChain] Found flatten wrapper: " . $node->getDomNode()->nodeName . " at index $index (includeSelf=" . ($node->shouldIncludeSelfInFlatten() ? 'true' : 'false') . ")\n";
                break; // Use first (closest to leaf)
            }
        }
        
        // No flattening needed
        if ($flattenWrapper === null) {
            // print "[buildAncestorChain] No flattening, returning raw chain\n";
            return $rawChain;
        }
        
        // CRITICAL: Check if there's a Link element between flatten wrapper and leaf
        // Links act as a barrier - flattening stops at the Link
        $linkIndex = -1;
        for ($i = $flattenWrapperIndex + 1; $i < count($rawChain); $i++) {
            if ($rawChain[$i]->isLinkNode()) {
                $linkIndex = $i;
                break; // Use first Link after flatten wrapper
            }
        }
        
        // If Link found, treat it as the new "leaf" - don't flatten beyond it
        $effectiveLeafIndex = ($linkIndex !== -1) ? $linkIndex : count($rawChain) - 1;
        
        // FLATTENING: Build filtered chain
        $filteredChain = [];
        
        foreach ($rawChain as $index => $node) {
            if ($index < $flattenWrapperIndex) {
                // Before flatten region - keep
                $filteredChain[] = $node;
            } elseif ($index === $flattenWrapperIndex) {
                // The flatten wrapper itself
                if ($flattenWrapper->shouldIncludeSelfInFlatten()) {
                    $filteredChain[] = $node;
                    // print "[buildAncestorChain] Keeping flatten wrapper (include-self mode)\n";
                } else {
                    // print "[buildAncestorChain] Removing flatten wrapper (exclude-self mode)\n";
                }
            } elseif ($index >= $effectiveLeafIndex) {
                // At or after effective leaf (Link or actual leaf) - ALWAYS keep
                $filteredChain[] = $node;
            } else {
                // Container between wrapper and effective leaf - remove
            }
        }
        
        foreach ($filteredChain as $node) {
            print $node->getDomNode()->nodeName . " > ";
        }
        print "\n\n";
        
        return $filteredChain;
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
            $parentKey,
            $node->getAlt(),
            $node->getActualText(),
            $node->getLang(),
            $node->getExpansion(),
            $node->getTitle()
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
            
            // Set PDF attributes if present
            if (!empty($elemData['alt'])) {
                $this->o_structElem($objectId, 'alt', $elemData['alt']);
            }
            if (!empty($elemData['actualText'])) {
                $this->o_structElem($objectId, 'actualText', $elemData['actualText']);
            }
            if (!empty($elemData['lang'])) {
                $this->o_structElem($objectId, 'lang', $elemData['lang']);
            }
            if (!empty($elemData['expansion'])) {
                $this->o_structElem($objectId, 'expansion', $elemData['expansion']);
            }
            if (!empty($elemData['title'])) {
                $this->o_structElem($objectId, 'title', $elemData['title']);
            }
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
        
        // Third pass: Handle deferred OBJR additions and ParentTree linkages for annotations
        // (annotations created before structure elements had object IDs)
        foreach ($this->objects as $objId => $obj) {
            if (isset($obj['info']['linkStructElemKey']) && isset($obj['info']['linkAnnotId'])) {
                $linkStructElemKey = $obj['info']['linkStructElemKey'];
                $linkStructElemObjectId = $this->structElemRegistry->getObjectId($linkStructElemKey);
                $annotId = $obj['info']['linkAnnotId'];
                $pageId = $obj['info']['linkPageId'];
                
                if ($linkStructElemObjectId !== null && isset($obj['info']['structParent'])) {
                    $structParentIndex = $obj['info']['structParent'];
                    
                    // Create indirect OBJR object (PDF/UA best practice)
                    $this->numObj++;
                    $objrObjectId = $this->numObj;
                    $this->o_objr($objrObjectId, 'new', [
                        'obj' => $annotId,
                        'pg' => $pageId
                    ]);
                    
                    // Get current kids (should be MCID from link text)
                    $currentKids = $this->objects[$linkStructElemObjectId]['info']['kids'] ?? null;
                    $mcid = is_int($currentKids) ? $currentKids : null;
                    
                    if ($mcid !== null) {
                        // Create a Span StructElem for the link text MCID
                        $this->numObj++;
                        $spanObjectId = $this->numObj;
                        $this->o_structElem($spanObjectId, 'new');
                        $this->o_structElem($spanObjectId, 'structType', 'Span');
                        $this->o_structElem($spanObjectId, 'parent', $linkStructElemObjectId);
                        $this->o_structElem($spanObjectId, 'kids', $mcid);
                        $this->o_structElem($spanObjectId, 'page', $pageId);
                        
                        // Link StructElem contains Span (with text MCID) and OBJR (with annotation)
                        $this->objects[$linkStructElemObjectId]['info']['kids'] = [
                            ['ref' => $spanObjectId],   // Span with link text
                            ['ref' => $objrObjectId]    // OBJR with annotation
                        ];
                        
                        // CRITICAL FIX: Update ParentTree for MCID to point to Span (leaf), not Link (container)
                        // The MCID is the index in the ParentTree array for the page's StructParents key
                        // We need to update ParentTree[pageStructParents][MCID] from linkStructElemObjectId to spanObjectId
                        $pageStructParentsKey = $this->objects[$pageId]['info']['structParents'] ?? null;
                        if ($pageStructParentsKey !== null && isset($this->parentTreeData[$pageStructParentsKey])) {
                            // The MCID is the index in the array
                            if (isset($this->parentTreeData[$pageStructParentsKey][$mcid])) {
                                $oldValue = $this->parentTreeData[$pageStructParentsKey][$mcid];
                                $this->parentTreeData[$pageStructParentsKey][$mcid] = $spanObjectId;
                                print "[CPDF PDFUA] ParentTree: Updated MCID $mcid mapping - ParentTree[$pageStructParentsKey][$mcid]: $oldValue -> $spanObjectId\n";
                            }
                        }
                        
                        print "[CPDF PDFUA] Link StructElem $linkStructElemObjectId: /K -> [Span $spanObjectId (MCID $mcid), OBJR $objrObjectId]\n";
                    } else {
                        // No MCID - this is a container Link (e.g., <a><img></a>)
                        // The container may already have children (e.g., Figure elements)
                        // Add OBJR to existing children, don't replace them
                        $existingKids = $this->objects[$linkStructElemObjectId]['info']['kids'] ?? [];
                        
                        // Ensure kids is an array
                        if (!is_array($existingKids)) {
                            $existingKids = [$existingKids];
                        }
                        
                        // Add OBJR reference to the kids array
                        $existingKids[] = ['ref' => $objrObjectId];
                        $this->objects[$linkStructElemObjectId]['info']['kids'] = $existingKids;
                        
                        print "[CPDF PDFUA] Link StructElem $linkStructElemObjectId: /K -> [...existing children, OBJR $objrObjectId]\n";
                    }
                    
                    // Remove /Pg from Link StructElem since children have it
                    unset($this->objects[$linkStructElemObjectId]['info']['page']);
                    
                    // CRITICAL: ParentTree[StructParent] must point to Link StructElem, not OBJR
                    // The OBJR is a child of the Link that references back to the annotation
                    // Annotation → /StructParent N → ParentTree[N] → Link StructElem → /K contains OBJR → Annotation
                    $this->setParentTreeSingle($structParentIndex, $linkStructElemObjectId);
                    print "[CPDF PDFUA] ParentTree: StructParent $structParentIndex -> Link StructElem $linkStructElemObjectId (contains OBJR $objrObjectId for annotation $annotId)\n";
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
    // Utility Methods
    // ========================

    /**
     * Wrap a visual operation in an artifact tag
     * This closes any open semantic/artifact tag, wraps the operation in artifact, then restores state
     */
    private function wrapInArtifact(callable $operation)
    {
        if (!$this->pdfua) {
            $operation();
            return;
        }

        $taggingState = $this->taggingStateManager->getState();

        switch ($taggingState) {
            case TaggingState::SEMANTIC:
                parent::addContent(TagOps::endMarkedContent());
                // fall through
            case TaggingState::NONE:
                parent::addContent(TagOps::startArtifactContent());
                // fall through
            case TaggingState::ARTIFACT:
                $operation();
                break;
                
        }

        $this->taggingStateManager->setState(TaggingState::ARTIFACT);
    }

    /**
     * Wrap image rendering in semantic Figure tag
     * Basic version: Always wraps in Figure, no artifact detection yet
     */
    private function wrapImageInSemanticTag(string $img, callable $imageOperation)
    {
        if (!$this->pdfua) {
            $imageOperation();
            return;
        }

        $imgNode = $this->taggingStateManager->getCurrentSemanticNode();

        // Check if image is a dompdf-generated temporary file (bg, canvas, borders)
        $isDompdfTempImage = str_contains($img, 'bg_dompdf_img_');

        $isArtefact = $imgNode === null || $imgNode->isArtifactNode() || $isDompdfTempImage;

        if ($isArtefact) {
            print "[CPDF PDFUA] addImage detected as Artifact, wrapping in Artifact tag\n\n";
            // Image is decorative only - wrap in Artifact
            $this->wrapInArtifact(function() use ($imageOperation) {
                $imageOperation();
            });
            return;

        }

        $mcid = $this->taggingStateManager->getNextMcid();
        $tag = 'Figure';

        $currentState = $this->taggingStateManager->getState();

        switch ($currentState) {
            case TaggingState::SEMANTIC:
                parent::addContent(TagOps::endMarkedContent());

            case TaggingState::NONE:
                parent::addContent(TagOps::startMarkedContent($tag, $mcid));
                $this->taggingStateManager->setState(TaggingState::SEMANTIC);
                $imageOperation();
                break;
            case TaggingState::ARTIFACT:
                parent::addContent(TagOps::endMarkedContent());
                parent::addContent(TagOps::startMarkedContent($tag, $mcid));
                $imageOperation();
                $this->taggingStateManager->setState(TaggingState::SEMANTIC);
                break;
        }

        // Call the callback using callable syntax
        ($this->onMarkedContentAdded)($tag, $mcid);

        print "[CPDF PDFUA] addImage wrapped in Figure tag with mcid=$mcid\n\n";
    }

    // ========================
    // Page Operations
    // ========================

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

    // ========================
    // Text Operations
    // ========================

    function addText($x, $y, $size, $text, $angle = 0, $wordSpaceAdjust = 0, $charSpaceAdjust = 0, $smallCaps = false)
    {
        if (!$this->pdfua) return parent::addText($x, $y, $size, $text, $angle, $wordSpaceAdjust, $charSpaceAdjust, $smallCaps);

        // empty text check
        if (trim($text) === '') {
            if ($this->debugEnabled) print "[CPDF PDFUA] addText(): Skipping empty text\n";
            return;
        }

        // Add whitespace if <br> is next node to prevent word concatenation in structure tree
        // this is bearly visible in the PDF, but important for screen readers
        $currentSemanticNode = $this->taggingStateManager->getCurrentSemanticNode();
        if($currentSemanticNode !== null &&
           $currentSemanticNode->isBeforeLineBreakNode()
        ) {
            $text .= ' ';
        }

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
    
    // ========================
    // Drawing Operations
    // ========================
    function filledRectangle($x1, $y1, $width, $height)
    {
        $this->wrapInArtifact(function() use ($x1, $y1, $width, $height) {
            parent::filledRectangle($x1, $y1, $width, $height);
        });
    }

    function rectangle($x1, $y1, $width, $height)
    {
        $this->wrapInArtifact(function() use ($x1, $y1, $width, $height) {
            parent::rectangle($x1, $y1, $width, $height);
        });
    }

    function line($x1, $y1, $x2, $y2, $stroke = true)
    {
        $this->wrapInArtifact(function() use ($x1, $y1, $x2, $y2, $stroke) {
            parent::line($x1, $y1, $x2, $y2, $stroke);
        });
    }

    function clippingRectangle($x1, $y1, $width, $height)
    {
        $this->wrapInArtifact(function() use ($x1, $y1, $width, $height) {
            parent::clippingRectangle($x1, $y1, $width, $height);
        });
    }

    function clippingEnd()
    {
        $this->wrapInArtifact(function() {
            parent::clippingEnd();
        });
    }

    // ========================
    // Image Operations
    // ========================

    function addJpegFromFile($img, $x, $y, $w = 0, $h = 0)
    {
        print "[CpdfPdfua] addJpegFromFile: $img\n";

        // Wrap image in semantic Figure tag
        $this->wrapImageInSemanticTag($img, function() use ($img, $x, $y, $w, $h) {
            parent::addJpegFromFile($img, $x, $y, $w, $h);
        });
    }  

    function addPngFromFile($img, $x, $y, $w = 0, $h = 0)
    {
        print "[CpdfPdfua] addPngFromFile: $img\n";

        // Wrap image in semantic Figure tag
        $this->wrapImageInSemanticTag($img, function() use ($img, $x, $y, $w, $h) {
            parent::addPngFromFile($img, $x, $y, $w, $h);
        });
    }

    function addSvgFromFile($img, $x, $y, $w = 0, $h = 0)
    {
        print "[CpdfPdfua] addSvgFromFile: $img\n";

        // Wrap image in semantic Figure tag
        $this->wrapImageInSemanticTag($img, function() use ($img, $x, $y, $w, $h) {
            parent::addSvgFromFile($img, $x, $y, $w, $h);
        });
    }

    // ========================
    // Link Operations
    // ========================

    function addLink($url, $x0, $y0, $x1, $y1)
    {
        if (!$this->pdfua) {
            parent::addLink($url, $x0, $y0, $x1, $y1);
            return;
        }

        print "[CpdfPdfua] addLink: $url\n";

        // Store current numObj before creating annotation
        $numObjBefore = $this->numObj;
        
        // Create the link annotation (creates 2 objects: annotation + action)
        parent::addLink($url, $x0, $y0, $x1, $y1);
        
        // The annotation ID is numObjBefore + 1 (first object created)
        $annotId = $numObjBefore + 1;
        
        // Get alt text from current semantic node
        $linkNode = $this->taggingStateManager->getCurrentSemanticNode();
        $altText = $linkNode ? $linkNode->getAlt() : null;
        
        // If no alt text, use URL as fallback
        if ($altText === null) {
            $altText = $url;
        }
        
        // Add Contents key and StructParent to annotation (required by PDF/UA)
        if (isset($this->objects[$annotId]['info'])) {
            $this->objects[$annotId]['info']['contents'] = $altText;
            
            // Store link node for later use when wrapping text
            // This will be used in addText to wrap link content in /Link tag
            $this->objects[$annotId]['info']['linkNode'] = $linkNode;
            
            // Add StructParent linkage
            // Note: Link structure elements are created AFTER addLink() is called,
            // so we store this as pending and link it when the structure element is created
            $structParentIndex = $this->structParentCounter++;
            $this->objects[$annotId]['info']['structParent'] = $structParentIndex;
            
            // Store annotation info for OBJR creation
            $this->pendingLinkAnnotations[$annotId] = [
                'structParent' => $structParentIndex,
                'pageId' => $this->currentPage  // Store current page for OBJR
            ];
            
            print "[CPDF PDFUA] Link annotation $annotId created (pending StructParent $structParentIndex): $altText\n\n";
        }
    }

    /**
     * Check if we are currently inside a link element
     */
    private function isInsideLinkElement(): bool
    {
        $currentNode = $this->taggingStateManager->getCurrentSemanticNode();
        return $currentNode && $currentNode->isLinkNode();
    }

    /**
     * Override annotation output to add Contents key for PDF/UA compliance
     */
    protected function o_annotation($id, $action, $options = '')
    {
        // For 'new' action, let parent handle it first
        if ($action === 'new') {
            $result = parent::o_annotation($id, $action, $options);
            return $result;
        }
        
        // For 'out' action, call parent and modify output if needed
        if ($action === 'out') {
            $result = parent::o_annotation($id, $action, $options);
            
            // If PDF/UA is enabled, add Contents and StructParent keys
            if ($this->pdfua) {
                $modifications = '';
                
                // Add Contents key if present
                if (isset($this->objects[$id]['info']['contents'])) {
                    $contents = $this->objects[$id]['info']['contents'];
                    // Use hex string format with UTF-16BE encoding (like /Alt)
                    $contentsUtf16 = $this->utf8toUtf16BE($contents, true);
                    $modifications .= "\n/Contents <" . bin2hex($contentsUtf16) . ">";
                }
                
                // Add StructParent key if present
                if (isset($this->objects[$id]['info']['structParent'])) {
                    $structParent = $this->objects[$id]['info']['structParent'];
                    $modifications .= "\n/StructParent " . $structParent;
                }
                
                // Insert modifications before closing >>
                if ($modifications !== '') {
                    $result = str_replace(
                        "\n>>\nendobj",
                        $modifications . "\n>>\nendobj",
                        $result
                    );
                }
            }
            
            return $result;
        }
        
        // For any other action, just call parent
        return parent::o_annotation($id, $action, $options);
    }

    /**
     * Override page output to add /Tabs key for pages with annotations (PDF/UA 7.18.3)
     */
    protected function o_page($id, $action, $options = '')
    {
        // For 'out' action, add /Tabs /S if page has annotations
        if ($action === 'out' && $this->pdfua) {
            $result = parent::o_page($id, $action, $options);
            
            // Check if page has annotations
            if (isset($this->objects[$id]['info']['annot']) && !empty($this->objects[$id]['info']['annot'])) {
                // Insert /Tabs /S before closing >>
                $result = str_replace(
                    "\n>>\nendobj",
                    "\n/Tabs /S\n>>\nendobj",
                    $result
                );
            }
            
            return $result;
        }
        
        // For any other action, call parent
        return parent::o_page($id, $action, $options);
    }

}
