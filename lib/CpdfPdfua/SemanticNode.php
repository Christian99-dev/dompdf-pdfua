<?php
namespace Dompdf\CpdfPdfua;

/**
 * SemanticNode - Represents a DOM node with additional semantic information
 *
 * @package CpdfPdfua
 * @author  Christian Keller
 */
class SemanticNode
{
    /**
     * @var \DOMNode Current DOM node being processed
     */
    private $domNode;

    public function __construct($domNode)
    {
        $this->domNode = $domNode;
    }

    public function getDomNode()
    {
        return $this->domNode;
    }

    /**
     * Get the PDF structure tag that should be used for this element
     * 
     * @return string PDF structure tag (e.g., 'H1', 'P', 'Figure', 'Link')
     */
    public function getPdfStructureTag(): string | null
    {
        // HTML to PDF tag mapping
        $mapping = [
            'h1' => 'H1',     'h2' => 'H2',       'h3' => 'H3',
            'h4' => 'H4',     'h5' => 'H5',       'h6' => 'H6',
            'p' => 'P',       'div' => 'Div',     'span' => 'Span',
            'section' => 'Sect',   'article' => 'Art',  'aside' => 'Aside',
            'nav' => 'Nav',   'table' => 'Table', 'tr' => 'TR',
            'th' => 'TH',     'td' => 'TD',       'thead' => 'THead',
            'tbody' => 'TBody',    'tfoot' => 'TFoot', 'ul' => 'L',
            'ol' => 'L',      'li' => 'LI',       'img' => 'Figure',
            'figure' => 'Figure',  'a' => 'Link',  'blockquote' => 'BlockQuote',
            'code' => 'Code', 'pre' => 'Code',    'strong' => 'Strong',
            'em' => 'Em',     'b' => 'Strong',    'i' => 'Em', 
            "body" => "P", 
        ];

        if ($this->domNode instanceof \DOMElement) {
            $customTag = $this->domNode->getAttribute('data-dompdf-pdf-tag');
            if ($customTag !== '') {
                return $customTag;
            }
        }
        
        return $mapping[$this->domNode->nodeName] ?? null;
    }

    public function getStructuralParentPdfStructureTag(): string | null
    {
        $structuralParentNode = $this->getNextStructuralParentNode();
        if($structuralParentNode !== null) {
            return $structuralParentNode->getPdfStructureTag();
        }
        return null;
    }

    public function hasOnTopAttribute(): bool
    {
        if ($this->domNode instanceof \DOMElement) {
            return $this->domNode->hasAttribute('data-dompdf-on-top');
        }
        return false;
    }

    public function getNextStructuralParentNode(): ?SemanticNode
    {
        // Traverse up the DOM tree to find the next structural parent node
        // Skip inline tags like <strong>, <em>, <span>, etc.

        $parentNode = $this->domNode->parentNode;
        $saveCounter = 99;
        
        while ($parentNode instanceof \DOMElement) {
            $saveCounter--;
            if($saveCounter <= 0) {
                break;
            };

            $semanticParent = new SemanticNode($parentNode);
            
            // Stop when we find a non-inline (structural) tag
            if (!$semanticParent->isInlineTag()) {
                return $semanticParent;
            }
            
            // Continue up the tree
            $parentNode = $parentNode->parentNode;
        }
        
        return null;
    }
    
    public function hasSameStructuralParentAs(SemanticNode $otherNode): bool
    {
        $thisParent = $this->getNextStructuralParentNode();
        $otherParent = $otherNode->getNextStructuralParentNode();
            
        // If either has no parent, they can't have the same parent
        if ($thisParent === null || $otherParent === null) {
            return false;
        }

        return $thisParent->getDomNode() === $otherParent->getDomNode();
    }

    public function isTextNode(): bool
    {
        return $this->domNode->nodeName === '#text';
    }

    public function isEmptyTextNode(): bool
    {
        return $this->isTextNode() && trim($this->domNode->textContent) === '';
    }

    public function isBodyTag(): bool
    {
        return $this->domNode->nodeName === 'body';
    }

    public function isHtmlTag(): bool
    {
        return $this->domNode->nodeName === 'html';
    }

    public function isLineBreakTag(): bool
    {
        return $this->domNode->nodeName === 'br';
    }

    public function isLinkNode(): bool
    {
        return $this->domNode->nodeName === 'a';
    }

    public function isArtifactNode(): bool
    {
        // Check current node and traverse up to check all parent elements
        $node = $this->domNode;

        // if its an image and it has no alt text, treat it as artifact
        if($this->isImageNode() && ($this->getAlt() === null || $this->getAlt() === '')) {
            return true;
        }
        
        while ($node !== null) {
            // Only DOMElements can have attributes
            if ($node instanceof \DOMElement) {
                $ariaHidden = $node->getAttribute('aria-hidden');
                
                // Check if aria-hidden="true" (not empty string, which means attribute doesn't exist)
                if ($ariaHidden === 'true') {
                    return true;
                }
                
                // Stop at body/html to avoid going too far up
                if ($node->nodeName === 'body' || $node->nodeName === 'html') {
                    break;
                }
            }
            
            // Move up to parent
            $node = $node->parentNode;
        }
        
        return false;
    }

    /**
     * Get /Alt - Alternate description for non-text objects (primarily for Figure elements)
     * Maps from alt attribute
     */
    public function getAlt(): ?string
    {
        if ($this->domNode instanceof \DOMElement) {
            $alt = $this->domNode->getAttribute('alt');
            return $alt !== '' ? $alt : null;
        }
        return null;
    }

    /**
     * Get href attribute (for link elements)
     */
    public function getHref(): ?string
    {
        if ($this->domNode instanceof \DOMElement) {
            $href = $this->domNode->getAttribute('href');
            return $href !== '' ? $href : null;
        }
        return null;
    }

    /**
     * Get /ActualText - Replacement text for reading/copying
     * Maps from actual-text attribute
     */
    public function getActualText(): ?string
    {
        if ($this->domNode instanceof \DOMElement) {
            $actualText = $this->domNode->getAttribute('actual-text');
            return $actualText !== '' ? $actualText : null;
        }
        return null;
    }

    /**
     * Get /Lang - Language specification
     * Maps from lang attribute (standard HTML)
     */
    public function getLang(): ?string
    {
        if ($this->domNode instanceof \DOMElement) {
            $lang = $this->domNode->getAttribute('lang');
            return $lang !== '' ? $lang : null;
        }
        return null;
    }

    /**
     * Get /E - Expanded form/explanation
     * Maps from expansion attribute
     */
    public function getExpansion(): ?string
    {
        if ($this->domNode instanceof \DOMElement) {
            $expansion = $this->domNode->getAttribute('expansion');
            return $expansion !== '' ? $expansion : null;
        }
        return null;
    }

    /**
     * Get /T - Title
     * Maps from title attribute (standard HTML)
     */
    public function getTitle(): ?string
    {
        if ($this->domNode instanceof \DOMElement) {
            $title = $this->domNode->getAttribute('title');
            return $title !== '' ? $title : null;
        }
        return null;
    }

    public function isInlineTag(): bool
    {
        $transparentTags = [
            'strong',  'b',      'em',     'i',      'span',   'u',
            's',       'del',    'ins',    'mark',   'small',  'sub',
            'sup',     'code',   'kbd',    'samp',   'var',    'cite',
            'dfn',     'abbr',   'time',
        ];
        
        return in_array($this->domNode->nodeName, $transparentTags, true);
    }

    public function isBeforeLineBreakNode(): bool
    {
        // only check the previous siblings for <br>    
        $sibling = $this->domNode->nextSibling;
        
        if($sibling instanceof \DOMElement && $sibling->nodeName === 'br') {
            return true;
        }
        return false;
    }

    public function isNonBreakingSpaceInTD(): bool {
        return ($this->domNode->nodeName === "td" || $this->domNode->nodeName === "th") && trim($this->domNode->textContent) === "\u{00a0}";
    }

    public function isImageNode(): bool
    {
        return $this->domNode->nodeName === 'img';
    }

    /**
     * Check if element has the data-dompdf-pdf-structure-flatten attribute
     * 
     * @return bool
     */
    public function hasFlattenAttribute(): bool
    {
        if ($this->domNode instanceof \DOMElement) {
            return $this->domNode->hasAttribute('data-dompdf-pdf-structure-flatten');
        }
        return false;
    }

    /**
     * Check if flatten wrapper should keep itself in structure tree
     * 
     * @return bool True if attribute value is "include-self"
     */
    public function shouldIncludeSelfInFlatten(): bool
    {
        if (!$this->hasFlattenAttribute()) {
            return false;
        }
        
        if ($this->domNode instanceof \DOMElement) {
            $value = $this->domNode->getAttribute('data-dompdf-pdf-structure-flatten');
            // "include-self" → keep wrapper, empty/other → remove wrapper
            return $value === 'include-self';
        }
        
        return false;
    }

    /**
     * Check if element has the data-dompdf-hide attribute
     * this is not like aria-hidden, but a custom attribute to hide elements from the PDF structure tree with its children
     * 
     * @return bool
     */
    public function hasHideAttribute(): bool
    {
        if ($this->domNode instanceof \DOMElement) {
            return $this->domNode->hasAttribute('data-dompdf-hide');
        }
        return false;
    }

    /**
    * String representation
    */
    public function __toString()
    {
        $nodeName = $this->domNode ? $this->domNode->nodeName : 'null';
        return "SemanticNode(node={$nodeName}, pdfTag={$this->getPdfStructureTag()})";
    }
}