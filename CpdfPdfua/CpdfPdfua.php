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
     * @var SemanticNode Current SemanticNode being processed
     */
    private $currentSemanticNode;

    /**
     * Constructor
     */
    public function __construct($pageSize = [0, 0, 612, 792], $isUnicode = false, $fontcache = '', $tmp = '')
    {
        parent::__construct($pageSize, $isUnicode, $fontcache, $tmp);
    }

    /**
    * Sets the current SemanticNode being processed
    * @param \DOMNode $node
    */
    public function setCurrentDomNode($node): void
    {
        $this->currentSemanticNode = new SemanticNode($node);
        if ($this->debugEnabled) {
            print "[CPDF PDFUA] setCurrentSemanticNode({$this->currentSemanticNode})\n";
        }
    }

    /**
     * Enable or disable debug logging
     */
    public function setDebugEnabled(bool $enabled): void
    {
        $this->debugEnabled = $enabled;
    }

    // ========================
    // Text Operations
    // ========================

    function addText($x, $y, $size, $text, $angle = 0, $wordSpaceAdjust = 0, $charSpaceAdjust = 0, $smallCaps = false)
    {
        if(!$this->pdfua) return parent::addText($x, $y, $size, $text, $angle, $wordSpaceAdjust, $charSpaceAdjust, $smallCaps);
        
        if ($this->debugEnabled) print "[CPDF PDFUA] addText(x=$x, y=$y, size=$size, text=\"$text\")\n";
        return parent::addText($x, $y, $size, $text, $angle, $wordSpaceAdjust, $charSpaceAdjust, $smallCaps);
    }
}
