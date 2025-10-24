<?php

namespace Websailing\SubcolumnsBundle\Element;

use Contao\ContentElement;
use Contao\Database;

class ContentColsetPart extends ContentElement
{
    protected $strTemplate = 'ce_colsetPart';

    public function generate(): string
    {
        if ((defined('TL_MODE') && TL_MODE === 'BE') || (defined('TL_SCRIPT') && strpos((string)TL_SCRIPT, 'contao') !== false)) {
            $objTemplate = new \BackendTemplate('be_wildcard');
            $objTemplate->wildcard = '### Spaltenset Teil ###';
            $objTemplate->title = (string) ($this->headline ?: ($this->sc_name ?: ''));
            $objTemplate->id = $this->id;
            $objTemplate->link = 'ID '.$this->id;
            $objTemplate->href = '';
            return $objTemplate->parse();
        }
        return parent::generate();
    }

    protected function compile(): void
    {
        $setKey = $GLOBALS['TL_CONFIG']['subcolumns'] ?? 'flex';
        $sets   = $GLOBALS['TL_SUBCL'][$setKey]['sets'] ?? [];
        $type   = trim((string) ($this->sc_type ?? '')) ?: '50x50';
        $map    = $sets[$type] ?? [];
        $idx    = (int) ($this->sc_sortid ?? 1); // part 1 corresponds to column 2
        $colIdx = $idx; // zero based: 0 -> 1st col, part has sc_sortid starting at 1
        if (!empty($map) && !empty($map[$colIdx])) {
            $this->Template->col_class = implode(' ', array_filter($map[$colIdx]));
        } else {
            $this->Template->col_class = 'col';
        }

        // No inline widths: handled via classes and CSS only
    }
}
