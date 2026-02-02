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
            'em' => 'Em',     'b' => 'Strong',    'i' => 'Em'
        ];
        
        return $mapping[$this->domNode->nodeName] ?? null;
    }

    public function getParentPdfStructureTag(): string | null
    {
        $parentNode = $this->domNode->parentNode;
        if ($parentNode instanceof \DOMElement) {
            $parentSemanticNode = new SemanticNode($parentNode);
            return $parentSemanticNode->getPdfStructureTag();
        }
        return null;
    }

    public function isTextNode(): bool
    {
        return $this->domNode->nodeName === '#text';
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

    /**
    * String representation
    */
    public function __toString()
    {
        $nodeName = $this->domNode ? $this->domNode->nodeName : 'null';
        return "SemanticNode(node={$nodeName}, pdfTag={$this->getPdfStructureTag()})";
    }
}