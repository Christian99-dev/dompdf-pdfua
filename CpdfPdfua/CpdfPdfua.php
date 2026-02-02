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
     * Constructor
     */
    public function __construct($pageSize = [0, 0, 612, 792], $isUnicode = false, $fontcache = '', $tmp = '')
    {
        parent::__construct($pageSize, $isUnicode, $fontcache, $tmp);

        $this->textTagging = new TextTagging();
        $this->taggingStateManager = new TaggingStateManager();
    }

    /**
     * Sets the current SemanticNode being processed
     * @param \DOMNode $node
     */
    public function setCurrentDomNode($node): void
    {
        if(!$this->pdfua) return;
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
            }
        );

        if ($this->debugEnabled) print "[CPDF PDFUA] addText(x=$x, y=$y, size=$size, text=\"$text\")\n\n";
    }

    function output($debug = false)
    {
        if (!$this->pdfua) return parent::output($debug);

        if ($this->taggingStateManager->getState() !== TaggingState::NONE) {
            parent::addContent(TagOps::endMarkedContent());
        }

        return parent::output($debug);
    }
}
