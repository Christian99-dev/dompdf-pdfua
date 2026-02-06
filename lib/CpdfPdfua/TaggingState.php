<?php 
namespace Dompdf\CpdfPdfua;

/**
 * TaggingState - Enum for PDF/UA BDC State
 */

enum TaggingState
{
    /**
     * No BDC block is currently open
     */
    case NONE;
    
    /**
     * Semantic BDC block is currently open
     */
    case SEMANTIC;
    
    /**
     * Artifact block is currently open
     */
    case ARTIFACT;
}
