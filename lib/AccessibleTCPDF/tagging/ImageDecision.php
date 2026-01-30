<?php

/**
 * ImageDecision - Decision types for image rendering
 * 
 * ATOMIC DESIGN (like old atomic pattern):
 * - Images open → render → close immediately
 * - Each image gets its own structure element
 * - Images CANNOT stay in P/Div containers!
 * 
 * ARCHITECTURE:
 * - 3 possible states: NONE, SEMANTIC, ARTIFACT
 * - 2 image types: Semantic (with alt) or Decorative (without alt)
 * - Result: 6 atomic decisions
 * 
 * CASES:
 * - From NONE: OPEN_SEMANTIC | OPEN_ARTEFACT
 * - From SEMANTIC: CLOSE_SEMANTIC_AND_OPEN_SEMANTIC | CLOSE_SEMANTIC_AND_OPEN_ARTEFACT
 * - From ARTIFACT: CLOSE_ARTEFACT_AND_OPEN_SEMANTIC | CLOSE_ARTEFACT_AND_OPEN_ARTEFACT
 * 
 * WHY NO CONTINUE?
 * - Images are structure elements (Figure/Artifact)
 * - Cannot be inline in P-tags like text
 * - Must always close current BDC and open new one
 * 
 * @package dompdf-accessible
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
enum ImageDecision
{
    // From NONE state
    case OPEN_SEMANTIC;           // Open Figure, render, close
    case OPEN_ARTEFACT;           // Open Artifact, render, close
    
    // From SEMANTIC state (e.g. P-tag from text)
    case CLOSE_SEMANTIC_AND_OPEN_SEMANTIC;     // Close P, open Figure, render, close
    case CLOSE_SEMANTIC_AND_OPEN_ARTEFACT;     // Close P, open Artifact, render, close
    
    // From ARTIFACT state
    case CLOSE_ARTEFACT_AND_OPEN_SEMANTIC;     // Close Artifact, open Figure, render, close
    case CLOSE_ARTEFACT_AND_OPEN_ARTEFACT;     // Close Artifact, open new Artifact, render, close
}
