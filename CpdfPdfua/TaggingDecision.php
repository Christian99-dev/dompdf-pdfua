<?php 
namespace Dompdf\CpdfPdfua;

/**
 * TaggingDecision - Enum for Tagging Decisions
 */

enum TaggingDecision
{
    case OPEN_SEMANTIC_WITH_PARENT_TAG;
    case OPEN_ARTIFACT;
    
    case CONTINUE;

    case CLOSE;
    case CLOSE_AND_OPEN_ARTIFACT;
    case CLOSE_AND_OPEN_SEMANTIC_WITH_PARENT_TAG;
}
