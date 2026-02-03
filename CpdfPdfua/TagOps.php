<?php
namespace Dompdf\CpdfPdfua;

/**
 * TagOps - Static PDF Operator Generator for PDF/UA Tagging
 */
class TagOps
{
    /**
     * Generate BDC (Begin Marked Content) operator with properties
     * 
     * Creates a tagged content block opener with MCID and optional properties.
     * 
     * Example output:
     *   /P <</MCID 5>> BDC
     *   /H1 <</MCID 3 /Lang (en-US)>> BDC
     * 
     * @param string $tag PDF structure tag (P, H1, Table, etc.)
     * @param int $mcid Marked Content ID (unique per page)
     * @return string PDF BDC operator with newline
     */
    public static function startMarkedContent(string $tag, int $mcid): string
    {
        return sprintf("\n/%s <</MCID %d>> BDC", $tag, $mcid);
    }
    
    /**
     * Generate Artifact BMC (Begin Marked Content) operator
     * 
     * @return string PDF Artifact BMC operator with newline
     */
    public static function startArtifactContent(): string
    {
        return "\n/Artifact BMC";
    }

    /**
     * Generate EMC (End Marked Content) operator
     * 
     * @return string PDF EMC operator with newline
     */
    public static function endMarkedContent(): string
    {
        return "\nEMC";
    }
    
    /**
     * Escape special characters for PDF strings
     * 
     * Escapes characters that have special meaning in PDF strings:
     * - Backslash (\)
     * - Parentheses (( and ))
     * - Carriage return, line feed, tab, backspace, form feed
     * 
     * @param string $text Text to escape
     * @return string Escaped text safe for PDF string literals
     */
    private static function escape(string $text): string
    {
        // Replace special PDF characters
        $text = str_replace('\\', '\\\\', $text);  // Backslash first!
        $text = str_replace('(', '\\(', $text);
        $text = str_replace(')', '\\)', $text);
        $text = str_replace("\r", '\\r', $text);
        $text = str_replace("\n", '\\n', $text);
        $text = str_replace("\t", '\\t', $text);
        $text = str_replace("\b", '\\b', $text);
        $text = str_replace("\f", '\\f', $text);
        
        return $text;
    }
}
