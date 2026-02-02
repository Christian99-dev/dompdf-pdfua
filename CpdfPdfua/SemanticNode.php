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
        $thisParent = $this->getNextStructuralParentNode()->getDomNode();
        $otherParent = $otherNode->getNextStructuralParentNode()->getDomNode();

        return $thisParent === $otherParent;
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

    public function isArtifactNode(): bool
    {
        // get aria-hidden attribute
        if ($this->domNode instanceof \DOMElement) {
            $ariaHidden = $this->domNode->getAttribute('aria-hidden');
            
            if ($ariaHidden === '') {
                return true;
            }
        }
        return false;
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

    /**
    * String representation
    */
    public function __toString()
    {
        $nodeName = $this->domNode ? $this->domNode->nodeName : 'null';
        return "SemanticNode(node={$nodeName}, pdfTag={$this->getPdfStructureTag()})";
    }
}