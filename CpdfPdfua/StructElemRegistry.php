<?php

namespace Dompdf\CpdfPdfua;

/**
 * StructElemRegistry - Manages all structure elements before PDF object creation
 * 
 * This registry collects all structure element information during document processing
 * and provides data for generating PDF objects in the output phase.
 * 
 * @package CpdfPdfua
 * @author  Christian Keller
 */
class StructElemRegistry
{
    /**
     * @var array All registered structure elements
     * Format: [key => [id, type, parent, children, mcid, page]]
     */
    private $structElems = [];

    /**
     * @var string|null Key of the document root element
     */
    private $documentRootKey = null;

    /**
     * @var int Counter for generating unique keys
     */
    private $keyCounter = 0;

    /**
     * Register the document root element or return existing one
     * 
     * @return string The key of the document root
     */
    public function registerDocumentRoot(): string
    {
        if ($this->documentRootKey !== null) {
            return $this->documentRootKey;
        }

        $key = 'document_root';
        $this->structElems[$key] = [
            'id' => null,           // Will be set in output()
            'type' => 'Document',
            'parent' => null,       // Root has no parent
            'children' => [],
            'mcid' => null,         // Root has no MCID
            'page' => null
        ];

        $this->documentRootKey = $key;
        return $key;
    }

    /**
     * Register a structure element
     * 
     * @param string $tagType PDF structure tag (P, H1, Span, etc.)
     * @param int $mcid Marked Content ID
     * @param int $pageIndex Page index (StructParents value)
     * @param string|null $parentKey Key of parent element (null = document root)
     * @return string The unique key for this element
     */
    public function registerStructElem(string $tagType, int $mcid, int $pageIndex, ?string $parentKey = null): string
    {
        // Ensure document root exists
        if ($this->documentRootKey === null) {
            $this->registerDocumentRoot();
        }

        // Default parent is document root
        if ($parentKey === null) {
            $parentKey = $this->documentRootKey;
        }

        // Generate unique key
        $this->keyCounter++;
        $key = strtolower($tagType) . '_' . $this->keyCounter;

        // Register element
        $this->structElems[$key] = [
            'id' => null,           // Will be set in output()
            'type' => $tagType,
            'parent' => $parentKey,
            'children' => [],
            'mcid' => $mcid,
            'page' => $pageIndex
        ];

        // Add to parent's children
        if (isset($this->structElems[$parentKey])) {
            $this->structElems[$parentKey]['children'][] = $key;
        }

        return $key;
    }

    /**
     * Set the PDF object ID for a structure element
     * 
     * @param string $key Element key
     * @param int $objectId PDF object ID
     */
    public function setObjectId(string $key, int $objectId): void
    {
        if (isset($this->structElems[$key])) {
            $this->structElems[$key]['id'] = $objectId;
        }
    }

    /**
     * Get the PDF object ID for a structure element
     * 
     * @param string $key Element key
     * @return int|null Object ID or null if not set
     */
    public function getObjectId(string $key): ?int
    {
        return $this->structElems[$key]['id'] ?? null;
    }

    /**
     * Get all structure elements
     * 
     * @return array All structure elements
     */
    public function getStructElems(): array
    {
        return $this->structElems;
    }

    /**
     * Get the document root key
     * 
     * @return string|null Document root key
     */
    public function getDocumentRootKey(): ?string
    {
        return $this->documentRootKey;
    }

    /**
     * Get the document root object ID
     * 
     * @return int|null Document root object ID
     */
    public function getDocumentRootObjectId(): ?int
    {
        if ($this->documentRootKey === null) {
            return null;
        }
        return $this->getObjectId($this->documentRootKey);
    }

    /**
     * Get structure elements for a specific page
     * 
     * @param int $pageIndex Page index
     * @return array Array of [key => element] for that page
     */
    public function getStructElemsForPage(int $pageIndex): array
    {
        $result = [];
        foreach ($this->structElems as $key => $elem) {
            if ($elem['page'] === $pageIndex && $elem['mcid'] !== null) {
                $result[$key] = $elem;
            }
        }
        return $result;
    }

    /**
     * Get structure element by key
     * 
     * @param string $key Element key
     * @return array|null Element data or null
     */
    public function getStructElem(string $key): ?array
    {
        return $this->structElems[$key] ?? null;
    }

    /**
     * Check if an element exists
     * 
     * @param string $key Element key
     * @return bool
     */
    public function hasStructElem(string $key): bool
    {
        return isset($this->structElems[$key]);
    }

    /**
     * Get number of registered elements
     * 
     * @return int
     */
    public function count(): int
    {
        return count($this->structElems);
    }
}
